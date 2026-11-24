<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\MetadataFailure;
use Eppitnic\Service\PasskeyMetadata;

/**
 * Fetch the FIDO MDS3 metadata and store what it verifies. Also what the
 * scheduled job `passkey_metadata` runs, unless passkeys are off (`cron run`
 * skips it then); by hand it always runs, whatever that job's `enabled` says.
 * --file imports a BLOB downloaded elsewhere, for a server that cannot reach
 * FIDO, under the same checks. --when-due (what the job passes) fetches only
 * once the stored BLOB's nextUpdate is reached and no Retry-After is pending.
 */
final class PasskeyMetadataCommand extends Command
{
    public function describe(): string {
        return 'fetch the FIDO metadata device-bound passkeys are checked against';
    }

    public function options(): array {
        return [
            'file='    => 'import this BLOB (' . PasskeyMetadata::URL . ' as downloaded) instead of fetching it',
            'when-due' => 'fetch only once the stored BLOB is due (its nextUpdate), as the scheduled job does',
        ];
    }

    public function run(): int {
        $this->database();

        if ($this->hasOption('when-due') && ! $this->hasOption('file') && ! PasskeyMetadata::due()) {
            $status = PasskeyMetadata::status();
            $this->record(
                $status['retry_after'] !== null && $status['retry_after'] > PasskeyMetadata::now()->format('Y-m-d H:i:s')
                    ? "metadata not due: the server asked to wait until {$status['retry_after']} UTC"
                    : "metadata BLOB {$status['serial']} not due before {$status['next_update']}",
                ['skipped' => 'not due'] + $status
            );
            return 0;
        }

        try {
            if ($this->hasOption('file')) {
                $path = (string) $this->option('file');
                $jwt = $path === '' ? false : @file_get_contents($path);
                if ($jwt === false) {
                    throw new UsageError("cannot read '{$path}'");
                }
                $result = PasskeyMetadata::import($jwt);
            } else {
                $result = PasskeyMetadata::refresh();
            }
        } catch (MetadataFailure $e) {
            $this->warn('metadata not updated (' . $e->reason . '): ' . $e->getMessage());
            return $e->reason === MetadataFailure::FETCH ? METADATA_FETCH_FAILED : METADATA_REFUSED;
        }

        $this->record(
            isset($result['unchanged'])
                ? "metadata BLOB {$result['serial']} is current: {$result['entries']} authenticator(s), next update {$result['next_update']}"
                : "metadata BLOB {$result['serial']}: {$result['entries']} authenticator(s), next update {$result['next_update']}",
            $result
        );
        return 0;
    }
}
