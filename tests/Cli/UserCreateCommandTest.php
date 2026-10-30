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
        foreach (['users', 'resellers', 'history'] as $table) {
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
}
