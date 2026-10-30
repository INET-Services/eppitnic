<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigEppPasswordCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Service\SessionState;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `config epp-password` -- the one epp.* setting that goes through
 * RegistryPasswordChange rather than a plain local write, since a value
 * this installation and the registry disagree about breaks every EPP call.
 */
final class ConfigEppPasswordCommandTest extends EppTestCase
{
    /** what a login attempt is offered, in order (<pw>, or <newPW> when present) */
    private array $attempted = [];

    /** whether the registry accepts whatever password it is offered */
    private bool $registryAccepts = true;

    private ?string $refusal = null;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'history', 'users'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');

        // mail off: a landed rotation is recorded, and mailed only when enabled
        Config::loadForTesting(static::SETTINGS + ['smtp' => [
            'enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => '',
            'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => '',
            'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
        ]]);
        Config::set('epp', ['password' => 'old-password'] + static::SETTINGS['epp']);

        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);

            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (string $request): string {
                $this->attempted[] = self::credentialIn($request);
                return $this->registryAccepts ? CommandCatalog::OK_RESPONSE : ($this->refusal ?? self::loginRefused());
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);

            return $client;
        });
    }

    protected function tearDown(): void {
        RegistryPasswordChange::useClientFactory(null);
        parent::tearDown();
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testChangesThePasswordAtTheRegistryAndStoresIt(): void {
        $command = new ConfigEppPasswordCommand(['--yes', 'new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('new-password', Config::get('epp')['password']);
        $this->assertArrayNotHasKey('pendingPassword', Config::get('epp'));
        $this->assertSame(['new-password'], $this->attempted, 'sent as <newPW> in one login');
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE action = 'rotate'"));
    }

    public function testChangeStampsLastPasswordUpdate(): void {
        Config::set('epp', ['password' => 'old-password', 'lastPasswordUpdate' => 0] + static::SETTINGS['epp']);

        $command = new ConfigEppPasswordCommand(['--yes', 'new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $command->run());

        $this->assertGreaterThan(0, Config::get('epp')['lastPasswordUpdate']);
    }

    public function testForceAdoptsWithoutChangingWhenTheRegistryAlreadyAcceptsIt(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--force', 'already-live']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('already-live', Config::get('epp')['password']);
    }

    public function testForceRefusesAndChangesNothingWhenTheRegistryDisagrees(): void {
        $this->registryAccepts = false;
        $this->refusal = CommandCatalog::loginRefusal('2200', 'Authentication error', '6005', 'Invalid username or password');

        [$exit, $stderr] = $this->forceWithStderr('not-real');

        $this->assertSame(CHANGE_PASSWORD_FAILED, $exit);
        $this->assertStringContainsString('the registry rejected this password (2200 / 6005: Invalid username or password)', $stderr);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testForceSaysWhenTheLoginWasRefusedForAnotherReason(): void {
        $this->registryAccepts = false;
        $this->refusal = CommandCatalog::loginRefusal('2400', 'Command failed', '5052', 'User IP address is not allowed');

        [$exit, $stderr] = $this->forceWithStderr('maybe-fine');

        $this->assertSame(CHANGE_PASSWORD_FAILED, $exit);
        $this->assertStringContainsString('a reason other than the password', $stderr);
        $this->assertStringContainsString('(2400 / 5052: User IP address is not allowed)', $stderr);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testForceClearsTheSharedSession(): void {
        Config::set('session_cookies', ['JSESSIONID' => 'old']);
        Config::set('session_timestamp', time());

        $this->forceWithStderr('already-live');

        $this->assertSame(0, SessionState::timestamp());
        $this->assertSame([], SessionState::cookies());
    }

    /** @return array{int, string} exit code and what went to stderr */
    private function forceWithStderr(string $password): array {
        $stderr = fopen('php://memory', 'w+');
        $command = new ConfigEppPasswordCommand(['--yes', '--force', $password]);
        $command->useErrorStream($stderr);
        $exit = 0;
        $this->capture(function () use ($command, &$exit) {
            $exit = $command->run();
        });
        rewind($stderr);
        return [$exit, (string) stream_get_contents($stderr)];
    }

    public function testDryRunSendsNothingToTheRegistry(): void {
        $command = new ConfigEppPasswordCommand(['--dry-run', 'new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame([], $this->attempted, 'a dry run must reach no network at all');
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testDeclinesWithoutATerminalOrYes(): void {
        $command = new ConfigEppPasswordCommand(['new-password']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('nothing done', $output);
        $this->assertSame([], $this->attempted);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testRejectsAPasswordShorterThanSix(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppPasswordCommand(['short']))->run();
    }

    public function testRejectsAPasswordLongerThanSixteen(): void {
        $this->expectException(UsageError::class);
        (new ConfigEppPasswordCommand([str_repeat('a', 17)]))->run();
    }

    private function stdin(string $text) {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $text);
        rewind($stream);
        return $stream;
    }

    public function testChangesFromStdin(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--password-stdin']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $command->useInputStream($this->stdin("from-stdin\n"));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('from-stdin', Config::get('epp')['password']);
        $this->assertSame(['from-stdin'], $this->attempted);
    }

    public function testForceAdoptsFromStdin(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--force', '--password-stdin']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $command->useInputStream($this->stdin("already-live\r\n"));

        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('already-live', Config::get('epp')['password']);
    }

    public function testEmptyStdinIsRefused(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--password-stdin']);
        $command->useInputStream($this->stdin(''));

        $this->expectException(UsageError::class);
        $command->run();
    }

    public function testStdinAndPositionalTogetherAreRefused(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--password-stdin', 'new-password']);
        $command->useInputStream($this->stdin("other-one\n"));

        $this->expectException(UsageError::class);
        $command->run();
    }

    public function testStdinPasswordIsValidatedBeforeAnythingIsSent(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--password-stdin']);
        $command->useInputStream($this->stdin(str_repeat('a', 17) . "\n"));

        try {
            $command->run();
            $this->fail('expected a UsageError');
        } catch (UsageError) {
            $this->assertSame([], $this->attempted);
            $this->assertSame('old-password', Config::get('epp')['password']);
        }
    }

    public function testNoSourceWithoutATerminalIsRefused(): void {
        if (stream_isatty(STDIN)) {
            $this->markTestSkipped('needs a non-interactive stdin');
        }
        $command = new ConfigEppPasswordCommand(['--yes']);

        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('--password-stdin');
        $command->run();
    }

    public function testNeverEchoesThePassword(): void {
        $command = new ConfigEppPasswordCommand(['--yes', '--json', '--force', 'secret-live']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $command->run());

        $this->assertStringNotContainsString('secret-live', $output);
    }

    /**
     * The password a login request offers as its credential -- <newPW> when
     * present (a real change), otherwise <pw> (a --force verification).
     */
    private static function credentialIn(string $request): string {
        $dom = new \DOMDocument();
        if ( ! @$dom->loadXML($request)) {
            return '';
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
        $newPw = $xpath->query('//e:login/e:newPW')->item(0);
        if ($newPw !== null) {
            return $newPw->textContent;
        }
        $pw = $xpath->query('//e:login/e:pw')->item(0);
        return $pw === null ? '' : $pw->textContent;
    }

    private static function loginRefused(): string {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="2200"><msg>Authentication error</msg></result>
                <trID><svTRID>x</svTRID></trID>
              </response>
            </epp>
            XML;
    }
}
