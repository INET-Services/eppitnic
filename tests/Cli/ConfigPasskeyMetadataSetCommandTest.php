<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\ConfigPasskeyMetadataSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config passkey-metadata-set` -- a thin AbstractCronjobSetCommand wrapper (see
 * CronjobSettingsTest for the shared layer's own coverage).
 */
final class ConfigPasskeyMetadataSetCommandTest extends EppTestCase
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
            'passkey_metadata' => ['enabled' => true, 'frequency_minutes' => 1440, 'last_run_at' => null],
        ]);
    }

    private function runCommand(array $argv): void {
        $command = new ConfigPasskeyMetadataSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        $this->assertSame(0, $command->run());
        ob_end_clean();
    }

    public function testItIsRegistered(): void {
        $this->assertSame(ConfigPasskeyMetadataSetCommand::class, Application::commands()['config passkey-metadata-set']);
    }

    public function testSetsAndResetsTheFrequency(): void {
        $this->runCommand(['--yes', 'frequency_minutes', '720']);
        $this->assertSame(720, Config::get('passkey_metadata')['frequency_minutes']);

        $this->runCommand(['--yes', 'frequency_minutes']);
        $this->assertSame(1440, Config::get('passkey_metadata')['frequency_minutes']);
    }

    public function testDisables(): void {
        $this->runCommand(['--yes', 'enabled', 'false']);

        $this->assertFalse(Config::get('passkey_metadata')['enabled']);
    }

    public function testRejectsAnUnknownFieldAndAFrequencyBeyondADay(): void {
        foreach ([['--yes', 'batch_size', '10'], ['--yes', 'frequency_minutes', '1441']] as $argv) {
            try {
                (new ConfigPasskeyMetadataSetCommand($argv))->run();
                $this->fail('accepted ' . json_encode($argv));
            } catch (UsageError) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
