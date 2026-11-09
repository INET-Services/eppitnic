<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\Command\SelftestReapCommand;
use Eppitnic\Cli\Command\SelftestRunCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Selftest\Leftovers;
use PHPUnit\Framework\TestCase;

/**
 * What the self-test verbs do before they reach the network. Every test stops
 * short of a session, which is the point: am I allowed to run, and was I given
 * enough to run with, both have to be answered before anything is sent.
 */
final class SelftestCommandTest extends TestCase
{
    private const PRODUCTION = 'https://epp.nic.it';
    private const TEST       = 'https://epp.pubtest.nic.it';

    /** @var string[] scratch directories to remove afterwards */
    private array $scratchDirectories = [];

    private function configured(string $endpoint): void {
        Config::loadForTesting(['epp' => ['server' => $endpoint]]);
    }

    private function quiet(Command $command): Command {
        $command->useErrorStream(fopen('php://memory', 'w+'));

        return $command;
    }

    /**
     * @return array{0: int, 1: string} the exit code and whatever it printed
     */
    private function capture(Command $command): array {
        ob_start();
        try {
            $code = $command->run();
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$code, $output];
    }

    protected function tearDown(): void {
        foreach ($this->scratchDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
        }
        Leftovers::useDirectory(null);
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // it refuses production
    // ---------------------------------------------------------------

    /**
     * The test that matters most: nothing else here protects production, and it
     * must answer before a session opens -- which is why the refusal is
     * asserted with no transport at all. Reaching the network would be the
     * failure.
     */
    public function testTheRunRefusesProduction(): void {
        $this->configured(self::PRODUCTION);

        [$code] = $this->capture($this->quiet(new SelftestRunCommand(['--yes'])));

        $this->assertSame(SELFTEST_REFUSED, $code);
    }

    public function testTheReapRefusesProduction(): void {
        $this->configured(self::PRODUCTION);

        [$code] = $this->capture($this->quiet(new SelftestReapCommand(['--yes'])));

        $this->assertSame(SELFTEST_REFUSED, $code);
    }

    /**
     * A refusal is not a failed test, and a script driving this has to be able
     * to tell them apart.
     */
    public function testARefusalHasItsOwnExitCode(): void {
        $this->assertNotSame(SELFTEST_REFUSED, SELFTEST_FAILED);
    }

    /**
     * --transfer is the operation with the most to lose, so the guard is
     * checked before the arguments are even looked at.
     */
    public function testProductionIsRefusedBeforeTheArgumentsAreChecked(): void {
        $this->configured(self::PRODUCTION);

        // --transfer without --authinfo would be a UsageError on the test
        // registry; here the endpoint decides first
        [$code] = $this->capture($this->quiet(new SelftestRunCommand(['--transfer=example.it'])));

        $this->assertSame(SELFTEST_REFUSED, $code);
    }

    // ---------------------------------------------------------------
    // what it needs to be told
    // ---------------------------------------------------------------

    public function testATransferNeedsAnAuthinfo(): void {
        $this->configured(self::TEST);

        $this->expectException(UsageError::class);
        $this->expectExceptionMessageMatches('/authinfo/');
        $this->quiet(new SelftestRunCommand(['--transfer=example.it', '--yes']))->run();
    }

    public function testATransferNeedsADomain(): void {
        $this->configured(self::TEST);

        $this->expectException(UsageError::class);
        $this->quiet(new SelftestRunCommand(['--transfer=', '--authinfo=SECRET', '--yes']))->run();
    }

    /**
     * An authinfo on its own means the caller expected a transfer and did not
     * get one. Silently running the lifecycle instead would be a surprise.
     */
    public function testAnAuthinfoWithoutATransferIsRejected(): void {
        $this->configured(self::TEST);

        $this->expectException(UsageError::class);
        $this->quiet(new SelftestRunCommand(['--authinfo=SECRET', '--yes']))->run();
    }

    /**
     * The name has to reach the registry, so obvious rubbish is refused here
     * rather than four round trips later.
     */
    public function testASuppliedDomainMustBeAnItName(): void {
        $this->configured(self::TEST);

        $this->expectException(UsageError::class);
        $this->quiet(new SelftestRunCommand(['--domain=not a domain', '--yes']))->run();
    }

    public function testASuppliedDomainIsAcceptedAndAnnounced(): void {
        $this->configured(self::TEST);

        [$code, $output] = $this->capture($this->quiet(new SelftestRunCommand(['--domain=Example-Test.IT'])));

        $this->assertSame(0, $code, 'it should have stopped at the confirmation, not failed');
        $this->assertStringContainsString('example-test.it', $output, 'the name was not lower-cased');
        $this->assertStringContainsString('nothing done', $output);
    }

    public function testASuppliedIdnDomainIsAnnouncedInUnicode(): void {
        $this->configured(self::TEST);

        [$code, $output] = $this->capture($this->quiet(new SelftestRunCommand(['--domain=XN--SESAMSTRAE-E4A.it'])));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('sesamstraße.it', $output);
    }

    /**
     * The pause only exists to let nic.it finish checking a real delegation.
     * A generated name can never resolve, so there is nothing to wait for and
     * the run must not sit there for no reason.
     */
    public function testTheVerificationPauseIsOnlyAnnouncedWithASuppliedDomain(): void {
        $this->configured(self::TEST);

        [, $withName] = $this->capture($this->quiet(new SelftestRunCommand(['--domain=example-test.it'])));
        [, $without]  = $this->capture($this->quiet(new SelftestRunCommand([])));

        $this->assertStringContainsString('pausing', $withName);
        $this->assertStringNotContainsString('pausing', $without);
    }

    public function testTwoNameserversAreTheMinimum(): void {
        $this->configured(self::TEST);

        $this->expectException(UsageError::class);
        $this->quiet(new SelftestRunCommand(['--ns=ns1.example.it', '--yes']))->run();
    }

    /**
     * --dry-run is offered by every other mutating verb and would mean nothing
     * here: the answers a self-test checks come from the registry, and a dry
     * run has none. Better rejected than accepted and ignored.
     */
    public function testDryRunIsNotOffered(): void {
        $this->expectException(UsageError::class);
        new SelftestRunCommand(['--dry-run']);
    }

    // ---------------------------------------------------------------
    // confirmation
    // ---------------------------------------------------------------

    /**
     * With no terminal to ask at and no --yes, it must do nothing rather than
     * proceed -- this creates real objects and costs real round trips.
     */
    public function testItDoesNothingWithoutConfirmation(): void {
        $this->configured(self::TEST);

        [$code, $output] = $this->capture($this->quiet(new SelftestRunCommand([])));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing done', $output);
    }

    // ---------------------------------------------------------------
    // reaping nothing
    // ---------------------------------------------------------------

    public function testReapingWithNothingOnFileIsQuietAndSuccessful(): void {
        $this->configured(self::TEST);
        Leftovers::useDirectory($this->scratch());

        [$code, $output] = $this->capture($this->quiet(new SelftestReapCommand(['--yes'])));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to reap', $output);
    }

    /**
     * The case that prompted the rework: a run that failed before creating its
     * domain leaves contacts nothing holds, and `reap` used to refuse them for
     * 30 days -- a wait invented for a blockage that did not exist.
     */
    public function testContactsFromARunWithNoDomainAreOfferedImmediately(): void {
        $this->configured(self::TEST);
        $this->noteOnFile(['contacts' => ['STTK26M2A1', 'STTK26M2T1'], 'domains' => []]);

        [$code, $output] = $this->capture($this->quiet(new SelftestReapCommand(['--list'])));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('STTK26M2A1', $output);
        $this->assertStringContainsString('STTK26M2T1', $output);
        $this->assertStringNotContainsString('nothing to reap', $output);
    }

    /**
     * A contact that really is held still waits, and the message says how long
     * for -- "there is nothing" and "not yet" are different answers, and only
     * the second means come back later.
     */
    public function testABlockedContactIsStillMadeToWait(): void {
        $this->configured(self::TEST);
        $this->noteOnFile([
            'contacts'      => [],
            'domains'       => [],
            'linked'        => ['STTK26M2R1'],
            'blocked_since' => date('c'),
        ]);

        [$code, $output] = $this->capture($this->quiet(new SelftestReapCommand(['--list'])));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing to reap yet', $output);
        $this->assertStringContainsString('day(s)', $output);
        $this->assertStringNotContainsString('STTK26M2R1', $output);
    }

    /**
     * --min-age=0 attempts them anyway, for when the registry has moved on
     * and this code's arithmetic has not.
     */
    public function testMinAgeZeroAttemptsBlockedContactsAnyway(): void {
        $this->configured(self::TEST);
        $this->noteOnFile([
            'contacts'      => [],
            'domains'       => [],
            'linked'        => ['STTK26M2R1'],
            'blocked_since' => date('c'),
        ]);

        [, $output] = $this->capture($this->quiet(new SelftestReapCommand(['--list', '--min-age=0'])));

        $this->assertStringContainsString('STTK26M2R1', $output);
    }

    /**
     * A leftover domain never waits: nothing holds a domain, and while it
     * exists the contacts it carries cannot be freed either.
     */
    public function testALeftoverDomainIsOfferedImmediately(): void {
        $this->configured(self::TEST);
        $this->noteOnFile([
            'contacts'      => [],
            'domains'       => ['st-tk26m2-1.it'],
            'linked'        => ['STTK26M2R1'],
            'blocked_since' => date('c'),
        ]);

        [, $output] = $this->capture($this->quiet(new SelftestReapCommand(['--list'])));

        $this->assertStringContainsString('st-tk26m2-1.it', $output);
    }

    // ---------------------------------------------------------------
    // helpers for the notes on file
    // ---------------------------------------------------------------

    private function scratch(): string {
        return sys_get_temp_dir() . '/eppitnic-reap-' . bin2hex(random_bytes(6));
    }

    /**
     * @param array<string, mixed> $manifest merged over a plausible note
     */
    private function noteOnFile(array $manifest): void {
        $directory = $this->scratch();
        mkdir($directory, 0o770, true);
        $this->scratchDirectories[] = $directory;
        Leftovers::useDirectory($directory);

        $manifest += ['stamp' => 'TK26M2', 'made_at' => date('c'), 'endpoint' => self::TEST];
        file_put_contents($directory . '/' . $manifest['stamp'] . '.json', json_encode($manifest));
    }
}
