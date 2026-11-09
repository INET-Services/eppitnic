<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Selftest\Guard;
use Eppitnic\Selftest\Leftovers;
use Eppitnic\Selftest\Naming;
use Eppitnic\Selftest\RefusedError;
use Eppitnic\Selftest\Run;
use Eppitnic\Selftest\Scenario\ContactLifecycle;
use Eppitnic\Selftest\Scenario\DomainLifecycle;
use Eppitnic\Selftest\Scenario\TransferRequest;
use Eppitnic\Selftest\Step;
use Eppitnic\Support\Idn;
use Eppitnic\Support\Validate;

/**
 * Exercise the library against the live test registry: the unit suite proves
 * the XML is generated and parsed, not that the registry accepts it. Runs
 * nowhere but `epp.pubtest.nic.it` -- see Guard. `--verbose` adds request and
 * response.
 *
 * @category    Net
 * @package     Eppitnic\Cli\Command\SelftestRunCommand
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SelftestRunCommand extends Command
{
    /**
     * Two to register with and a third to swap one of them for. example.it is
     * reserved for documentation, so these delegate nowhere and cannot be
     * mistaken for somebody's real infrastructure.
     */
    private const DEFAULT_NAMESERVERS = ['ns1.example.it', 'ns2.example.it', 'ns3.example.it'];

    /**
     * How long to leave the registry to check a delegation before reading it
     * back. nic.it validates out of band, so asking at once gets an empty
     * answer whether or not the nameservers are good.
     */
    private const VERIFICATION_WAIT = 10;

    public function describe(): string {
        return 'run a live sequence of registry operations against the test registry';
    }

    public function options(): array {
        return [
            'domain='   => 'register this name instead of a generated one, so its zone can be prepared '
                           . 'in advance and the nameservers actually verify',
            'ns='       => 'nameservers, colon-separated; the third is what the update swaps the second for '
                           . 'and need not exist (default ' . implode(':', self::DEFAULT_NAMESERVERS) . ')',
            'transfer=' => 'instead of the lifecycle, request a transfer-in of this domain (needs --authinfo)',
            'authinfo=' => 'the authinfo code held by the current registrar, for --transfer',
            'yes'       => 'do not ask for confirmation',
        ];
    }

    public function run(): int {
        try {
            $endpoint = Guard::assertTestEnvironment();
        } catch (RefusedError $e) {
            $this->warn($e->getMessage());
            return SELFTEST_REFUSED;
        }

        $scenarios = $this->scenarios();
        $names = new Naming();

        // stated before the question, not after it: what is about to happen is
        // what the answer is about
        $this->line("endpoint  {$endpoint}");
        $this->line("run       {$names->stamp}");

        if ($this->hasOption('domain')) {
            $this->line('domain    ' . $this->domain() . ' (as given)');
            $this->line(
                'Note: pausing ' . self::VERIFICATION_WAIT . 's after each delegation change, so the'
                . " registry's checks can finish. Without that it reports no nameservers at all."
            );
        }
        $this->line('');

        if ( ! $this->confirm($this->question($endpoint))) {
            $this->line('nothing done');
            return 0;
        }

        $run = null;
        $this->withSession(function ($nic) use ($scenarios, $names, &$run) {
            $run = new Run($nic, $names, fn(Step $step) => $this->report($step));

            foreach ($scenarios as $scenario) {
                $this->line($scenario->name());
                $scenario->execute($run);
                $this->line('');
            }
        });

        return $this->conclude($run);
    }

    // -----------------------------------------------------------------
    // what to run
    // -----------------------------------------------------------------

    /**
     * @return \Eppitnic\Selftest\Scenario[]
     */
    private function scenarios(): array {
        if ($this->hasOption('transfer')) {
            $domain = Idn::domain((string) $this->option('transfer'));
            $authinfo = (string) $this->option('authinfo', '');

            if ($domain === '') {
                throw new UsageError('--transfer needs a domain name, as --transfer=example.it');
            }
            // the registry will not consider a transfer without it, and asking
            // here beats a 2201 four round trips later
            if ($authinfo === '') {
                throw new UsageError('--transfer also needs --authinfo=CODE, issued by the current registrar');
            }

            return [new TransferRequest($domain, $authinfo)];
        }

        if ($this->hasOption('authinfo')) {
            throw new UsageError('--authinfo only means something with --transfer');
        }

        return [
            new ContactLifecycle(),
            new DomainLifecycle($this->nameservers(), $this->domain(), $this->verificationWait()),
        ];
    }

    /**
     * The name to register, when one was given -- the point being that its zone
     * can exist beforehand, which nic.it checks, and nothing can be prepared
     * for a name generated from the clock.
     *
     * @return string|null null to generate one
     */
    private function domain(): ?string {
        if ( ! $this->hasOption('domain')) {
            return null;
        }
        $domain = Idn::domain((string) $this->option('domain'));

        if ( ! Validate::isDomain($domain)) {
            throw new UsageError("'{$domain}' is not a .it domain name");
        }

        return $domain;
    }

    private function verificationWait(): int {
        return $this->hasOption('domain') ? self::VERIFICATION_WAIT : 0;
    }

    /**
     * @return string[] at least two nameservers
     */
    private function nameservers(): array {
        if ( ! $this->hasOption('ns')) {
            return self::DEFAULT_NAMESERVERS;
        }

        $given = array_values(array_filter(array_map('trim', explode(':', (string) $this->option('ns')))));
        if (count($given) < 2) {
            throw new UsageError('--ns needs at least two nameservers, colon-separated');
        }

        return $given;
    }

    private function question(string $endpoint): string {
        if ($this->hasOption('transfer')) {
            return "Request a transfer of '" . $this->option('transfer') . "' at {$endpoint}?";
        }
        if ($this->hasOption('domain')) {
            return "Register and delete '" . $this->domain() . "', and its contacts, at {$endpoint}?";
        }

        return "Create and delete contacts and one domain at {$endpoint}?";
    }

    // -----------------------------------------------------------------
    // reporting
    // -----------------------------------------------------------------

    /**
     * One line per operation, as it finishes. A full run is a few dozen round
     * trips against a remote registry; watching it arrive is the difference
     * between a command that looks slow and one that looks hung.
     */
    private function report(Step $step): void {
        $this->record(
            sprintf(
                '  %s %-30s %-18s %6.2fs  %s',
                $step->symbol(),
                $step->label,
                $step->subject,
                $step->seconds,
                $this->firstLine($step->detail)
            ),
            $step->toArray()
        );

        // the detail of a failure is the point of running this, and under
        // --verbose it is the whole exchange -- too much for the line above,
        // and it belongs on stderr where it survives --json
        if ($step->isFailure() && $this->isVerbose()) {
            $this->warn($step->detail);
        }
    }

    /**
     * Under --verbose getError() returns the request and the response as well,
     * which must not be allowed to break the column layout.
     */
    private function firstLine(string $detail): string {
        $line = strtok(trim($detail), "\n");

        return $line === false ? '' : $line;
    }

    private function conclude(?Run $run): int {
        if ($run === null) {
            $this->warn('the run produced nothing at all');
            return SELFTEST_FAILED;
        }

        $summary = $run->summary();
        $note = Leftovers::record($run);

        $this->line(sprintf(
            '%d steps: %d ok, %d failed, %d deferred, %d skipped, in %.1fs',
            $summary['steps'], $summary['ok'], $summary['failed'],
            $summary['deferred'], $summary['skipped'], $summary['seconds']
        ));

        if ($summary['deferred'] > 0) {
            $this->line(sprintf(
                'Deferred items are contacts still linked to the deleted domain, which the registry '
                . 'holds for %d days (redemptionPeriod, then pendingDelete). '
                . 'Clear them with `eppitnic selftest reap` after that.',
                Leftovers::PURGE_DAYS
            ));
        }
        if ($note === null && $summary['deferred'] > 0) {
            $this->warn(
                'Could not write the leftover note to ' . Leftovers::directory()
                . " -- `selftest reap` will not find run {$summary['stamp']}."
            );
        }

        return $summary['failed'] > 0 ? SELFTEST_FAILED : 0;
    }
}
