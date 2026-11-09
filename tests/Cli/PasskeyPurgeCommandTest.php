<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\PasskeyPurgeCommand;
use Eppitnic\Config;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyTestCase;
use RedBeanPHP\R;

/**
 * `passkey purge`: what the scheduled job `passkey_challenge_purge` runs, by
 * hand.
 */
final class PasskeyPurgeCommandTest extends PasskeyTestCase
{
    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(static::SETTINGS + [
            'passkey_challenge_purge' => ['enabled' => false, 'frequency_minutes' => 1440, 'last_run_at' => null],
        ]);
    }

    private function purge(array $argv = []): array {
        $command = new PasskeyPurgeCommand($argv);
        ob_start();
        $code = $command->run();
        $command->flush();
        return [$code, (string) ob_get_clean()];
    }

    public function testItIsRegisteredAsPasskeyPurge(): void {
        $this->assertSame(PasskeyPurgeCommand::class, Application::commands()['passkey purge']);
        $this->assertStringContainsString('passkey purge', (new Application())->overview());
    }

    public function testItDeletesWhatHasExpiredAndSaysHowMany(): void {
        WebAuthnChallenges::issue('stepup');
        WebAuthnChallenges::issue('stepup');
        R::exec("UPDATE webauthn_challenges SET expires_at = '2020-01-01 00:00:00' WHERE id = 1");
        WebAuthnChallenges::issue('stepup');
        R::exec("UPDATE webauthn_challenges SET expires_at = '2020-01-01 00:00:00' WHERE id = 2");
        WebAuthnChallenges::issue('stepup');

        [$code, $output] = $this->purge();

        $this->assertSame(0, $code);
        $this->assertSame("purged 2 challenge(s)\n", $output);
        $this->assertSame(2, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testItRunsByHandWhateverTheJobSettingSays(): void {
        WebAuthnChallenges::issue('stepup');
        R::exec("UPDATE webauthn_challenges SET expires_at = '2020-01-01 00:00:00'");

        [, $output] = $this->purge();

        $this->assertStringContainsString('purged 1', $output);
    }

    public function testItReportsNothingToDoAsZero(): void {
        [$code, $output] = $this->purge();

        $this->assertSame(0, $code);
        $this->assertSame("purged 0 challenge(s)\n", $output);
    }

    public function testJsonOutputCarriesTheCount(): void {
        WebAuthnChallenges::issue('stepup');
        R::exec("UPDATE webauthn_challenges SET expires_at = '2020-01-01 00:00:00'");

        [, $output] = $this->purge(['--json']);

        $this->assertSame([['purged' => 1]], json_decode($output, true));
    }
}
