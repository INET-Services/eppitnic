<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Service\PasskeySettings;

/**
 * The one verb a Docker image or any crontab needs to schedule -- decides
 * internally which of the other scheduled jobs are actually due
 * (`enabled` where the job has that field, and `frequency_minutes` elapsed
 * since `last_run_at`) and runs exactly those, reusing each job's own
 * command class rather than reimplementing its logic. `session keepalive`
 * is invoked every tick unconditionally -- it has no frequency of its own,
 * only its internal 230s-since-last-hello check (SessionKeepaliveCommand,
 * SessionState) -- exactly matching its own crontab line today.
 *
 *   * * * * *  /path/to/bin/eppitnic cron run >> /var/log/eppitnic/cron.log 2>&1
 */
final class CronRunCommand extends Command
{
    /** job => its command class, checked for enabled+frequency_minutes due-ness */
    private const SCHEDULED = [
        'pdns'                    => PdnsSyncCommand::class,
        'domain_sync'             => DomainSyncCommand::class,
        'domain_reap_deletions'   => DomainReapDeletionsCommand::class,
        'poll_process'            => PollProcessCommand::class,
        'passkey_challenge_purge' => PasskeyPurgeCommand::class,
        'passkey_metadata'        => PasskeyMetadataCommand::class,
    ];

    /** jobs with nothing to do while passkeys are off; by hand they still run */
    private const NEEDS_PASSKEYS = ['passkey_metadata'];

    public function describe(): string {
        return 'run every scheduled job that is due -- the one verb to actually schedule';
    }

    public function options(): array {
        return [
            'dry-run' => 'show which jobs would run, without running them',
        ];
    }

    public function run(): int {
        $this->database();

        $ran = [];
        $failed = false;

        foreach (self::SCHEDULED as $job => $class) {
            if ( ! $this->isDue(CronjobSettings::get($job))) {
                continue;
            }
            if (in_array($job, self::NEEDS_PASSKEYS, true) && ! PasskeySettings::enabled()) {
                if ( ! $this->isDryRun()) {
                    $this->record("{$job}: passkeys are off", ['job' => $job, 'skipped' => 'passkeys are off']);
                    CronjobSettings::markRun($job);
                }
                continue;
            }
            $ran[] = $job;
            if ($this->isDryRun()) {
                continue;
            }

            $command = new $class([]);
            $command->useErrorStream($this->errorStream());
            $code = $command->run();
            $command->flush();
            if ($code !== 0) {
                $failed = true;
            }
            $this->record("{$job}: exit {$code}", ['job' => $job, 'exit_code' => $code]);
            CronjobSettings::markRun($job);
        }

        // no frequency of its own -- always attempted, self-gates internally
        if ($this->isDryRun()) {
            $ran[] = 'keepalive';
        } else {
            $keepalive = new SessionKeepaliveCommand([]);
            $code = $keepalive->run();
            $keepalive->flush();
            if ($code !== 0) {
                $failed = true;
            }
        }

        $this->line($ran === []
            ? 'nothing due'
            : ($this->isDryRun() ? 'would run: ' : 'ran: ') . implode(', ', $ran));

        return $failed ? CRON_RUN_FAILED : 0;
    }

    /**
     * @param array $cfg the job's CronjobSettings::get() result -- an
     *        'enabled' key present and false means off; a job with no such
     *        key is always eligible, purely on elapsed time (every
     *        SCHEDULED job has one today, but the check stays generic)
     */
    private function isDue(array $cfg): bool {
        if (array_key_exists('enabled', $cfg) && ! $cfg['enabled']) {
            return false;
        }
        if ($cfg['last_run_at'] === null) {
            return true;
        }
        $elapsedMinutes = (time() - strtotime($cfg['last_run_at'])) / 60;
        return $elapsedMinutes >= $cfg['frequency_minutes'];
    }
}
