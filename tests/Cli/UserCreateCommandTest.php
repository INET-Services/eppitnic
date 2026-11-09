<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\UserCreateCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * `user create` needs an address of its own, like POST /v1/users.
 */
final class UserCreateCommandTest extends TestCase
{
    private const GOOD = 'Correct-Horse-9-Battery';

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history', 'passkeys'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensure(1, 'admin', 1, 'root');
        R::exec("UPDATE users SET email = 'root@example.it' WHERE id = 1");
        Config::loadForTesting([]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function create(array $args): int {
        $command = new UserCreateCommand(['--password=' . self::GOOD, ...$args]);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        try {
            return $command->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testCreatesTheUserWithItsAddress(): void {
        $this->assertSame(0, $this->create(['--email=carol@example.it', 'carol']));

        $this->assertSame('carol@example.it', R::getCell("SELECT email FROM users WHERE username = 'carol'"));
    }

    public function testTheEmailIsRequired(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('--email: email is required');

        $this->create(['carol']);
    }

    public function testAMalformedEmailIsRefused(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('valid address');

        $this->create(['--email=carol', 'carol']);
    }

    public function testAnAddressAnotherUserHasIsRefused(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('this e-mail address is already used by another account');

        $this->create(['--email=ROOT@example.it', 'carol']);
    }

    public function testANewUserStartsWithTotp(): void {
        $this->create(['--email=carol@example.it', 'carol']);

        $this->assertSame('totp', $this->requirementOf('carol'));
    }

    private function enablePasskeys(): void {
        Config::loadForTesting(['passkeys' => [
            'rp_id' => 'localhost', 'origins' => ['http://localhost:5173'], 'type' => 'any', 'models' => [], 'extra_aaguids' => [],
        ]]);
    }

    public function testTheRequirementIsSet(): void {
        $this->enablePasskeys();
        $this->create(['--email=carol@example.it', '--signin-requirement=passkey', 'carol']);
        $this->create(['--email=dave@example.it', '--signin-requirement=totp_or_passkey', 'dave']);

        $this->assertSame('passkey', $this->requirementOf('carol'));
        $this->assertSame('totp_or_passkey', $this->requirementOf('dave'));
        $this->assertStringContainsString('passkey', (string) R::getCell("SELECT data FROM history WHERE object = 'users' AND action = 'create' ORDER BY id LIMIT 1"));
    }

    public function testAUserWhoNeedsAKeyStartsWithTheEnrolmentOpenAndOthersWithItClosed(): void {
        $this->enablePasskeys();
        foreach (['passkey', 'totp_or_passkey', 'totp', 'none'] as $i => $mode) {
            $this->create(["--email=u{$i}@example.it", "--signin-requirement={$mode}", "user{$i}"]);
        }

        $this->assertSame([1, 1, 0, 0], array_map('intval', R::getCol('SELECT passkey_enroll_open FROM users WHERE id > 1 ORDER BY id')));
    }

    public function testAKeyRequirementIsRefusedWhilePasskeysAreOff(): void {
        foreach (['passkey', 'totp_or_passkey'] as $mode) {
            try {
                $this->create(['--email=carol@example.it', "--signin-requirement={$mode}", 'carol']);
                $this->fail("created with {$mode}");
            } catch (UsageError $e) {
                $this->assertStringContainsString('passkeys are not enabled', $e->getMessage());
            }
        }
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = 'carol'"));
        $this->assertSame(0, $this->create(['--email=carol@example.it', '--signin-requirement=totp', 'carol']));
    }

    public function testCreatingWithNoneIsRecordedAsASecurityEvent(): void {
        $this->create(['--email=carol@example.it', '--signin-requirement=none', 'carol']);

        $data = json_decode((string) R::getCell("SELECT data FROM history WHERE object = 'security'"), true);
        $this->assertSame(['signin_requirement_none', 'carol'], [$data['event'], $data['target']]);
    }

    public function testAnUnknownRequirementIsRefused(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('--signin-requirement must be one of');

        $this->create(['--email=carol@example.it', '--signin-requirement=sms', 'carol']);
    }

    public function testTheOldEnrolmentFlagIsGone(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage("unknown option '--must-enroll-mfa'");

        $this->create(['--email=carol@example.it', '--must-enroll-mfa', 'carol']);
    }

    private function requirementOf(string $username): string {
        return (string) R::getCell('SELECT signin_requirement FROM users WHERE username = ?', [$username]);
    }
}
