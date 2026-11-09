<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Cli\Command;
use RedBeanPHP\R;

/**
 * The passkey database for command tests: 1 admin (root), 2 manager (boss) and
 * 5 alice in reseller 2, with passkeys on for `localhost`.
 */
abstract class PasskeyCliTestCase extends PasskeyTestCase
{
    protected $errors;

    protected function setUp(): void {
        parent::setUp();

        TestAccounts::ensure(1, 'admin', 1, 'root');
        TestAccounts::ensure(2, 'manager', 2, 'boss');
        TestAccounts::ensure(5, 'user', 2, 'alice');
        R::exec("UPDATE users SET signin_requirement = 'totp'");
        $this->errors = fopen('php://memory', 'w+');
    }

    /**
     * @param class-string<Command> $class
     * @return array{0: int, 1: string} exit code and standard output
     */
    protected function exec(string $class, array $args, ?string $stdin = null): array {
        $command = new $class($args);
        $command->useErrorStream($this->errors);
        if ($stdin !== null) {
            $stream = fopen('php://memory', 'w+');
            fwrite($stream, $stdin);
            rewind($stream);
            $command->useInputStream($stream);
        }

        ob_start();
        try {
            $code = $command->run();
            $command->flush();
            return [$code, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    protected function errorText(): string {
        rewind($this->errors);
        return (string) stream_get_contents($this->errors);
    }

    /** @return SoftAuthenticator[] $count keys registered for alice */
    protected function aliceKeys(int $count): array {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = $key = new SoftAuthenticator(aaguid: sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $i));
            $this->storedKey($key);
        }
        TestAccounts::ensure(5, 'user', 2, 'alice');
        return $keys;
    }

    protected function requirement(string $mode, int $id = 5, int $open = 0): void {
        R::exec('UPDATE users SET signin_requirement = ?, passkey_enroll_open = ? WHERE id = ?', [$mode, $open, $id]);
    }

    /** @return array<int, array> the decoded `data` of the history rows of an object */
    protected function history(string $object, ?int $objectId = null): array {
        $rows = R::getAll(
            'SELECT user_id, action, data FROM history WHERE object = ?' . ($objectId === null ? '' : ' AND object_id = ?') . ' ORDER BY id',
            $objectId === null ? [$object] : [$object, $objectId]
        );
        return array_map(static fn(array $row): array => ['user_id' => $row['user_id'], 'action' => $row['action']] + json_decode($row['data'], true), $rows);
    }
}
