<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\ConfigPasskeyPurgeSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config passkey-purge-set` -- a thin AbstractCronjobSetCommand wrapper (see
 * CronjobSettingsTest for the shared layer's own coverage).
 */
final class ConfigPasskeyPurgeSetCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        Config::loadForTesting(static::SETTINGS + [
            'passkey_challenge_purge' => ['enabled' => true, 'frequency_minutes' => 1440, 'last_run_at' => null],
        ]);
    }

    private function runCommand(array $argv): string {
        $command = new ConfigPasskeyPurgeSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        $this->assertSame(0, $command->run());
        return (string) ob_get_clean();
    }

    public function testItIsRegistered(): void {
        $this->assertSame(ConfigPasskeyPurgeSetCommand::class, Application::commands()['config passkey-purge-set']);
    }

    public function testSetsAndResetsTheFrequency(): void {
        $this->runCommand(['--yes', 'frequency_minutes', '60']);
        $this->assertSame(60, Config::get('passkey_challenge_purge')['frequency_minutes']);

        $this->runCommand(['--yes', 'frequency_minutes']);
        $this->assertSame(1440, Config::get('passkey_challenge_purge')['frequency_minutes']);
    }

    public function testDisables(): void {
        $this->runCommand(['--yes', 'enabled', 'false']);

        $this->assertFalse(Config::get('passkey_challenge_purge')['enabled']);
    }

    public function testRejectsAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ConfigPasskeyPurgeSetCommand(['--yes', 'batch_size', '10']))->run();
    }

    public function testRejectsAFrequencyBeyondADay(): void {
        $this->expectException(UsageError::class);
        (new ConfigPasskeyPurgeSetCommand(['--yes', 'frequency_minutes', '1441']))->run();
    }
}
