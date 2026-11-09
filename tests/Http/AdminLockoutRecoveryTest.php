<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Cli\Command\ConfigPasskeysCommand;
use Eppitnic\Cli\Command\UserSigninResetCommand;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Eppitnic\Tests\Support\TestAccounts;
use RedBeanPHP\R;

/**
 * Every admin locked out -- passkey-only, and a policy switch left them with
 * no compliant key -- and the shell the only way back: `user signin-reset`
 * needs no one to sign in.
 */
final class AdminLockoutRecoveryTest extends PasskeyHttpTestCase
{
    private const TEMPORARY = 'Temporary-Passw0rd!';
    private const FRESH = 'Brand-New-Passw0rd!';

    /** @return array{0: int, 1: string} exit code and standard output */
    private function cli(string $class, array $args, ?string $stdin = null): array {
        $command = new $class($args);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        if ($stdin !== null) {
            $stream = fopen('php://memory', 'w+');
            fwrite($stream, $stdin);
            rewind($stream);
            $command->useInputStream($stream);
        }
        ob_start();
        try {
            return [$command->run(), (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    private function signIn(string $username, string $password): \Psr\Http\Message\ResponseInterface {
        return $this->call('POST', '/v1/users/authenticate', ['username' => $username, 'password' => $password]);
    }

    /** @return array<string, mixed> the signed-in answer after the password change and the key */
    private function comeBack(string $username, string $temporary): array {
        $login = $this->signIn($username, $temporary);
        $this->assertSame('password_change', self::json($login)['required'], $username);

        $changed = $this->call('POST', '/v1/users/authenticate/password', [
            'username' => $username, 'password' => $temporary, 'new_password' => self::FRESH,
        ]);
        $this->assertSame('passkey_enrollment', self::json($changed)['required'], $username . ': ' . $changed->getBody());

        $key = new SoftAuthenticator();
        $creds = ['username' => $username, 'password' => self::FRESH];
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/enroll', $creds));
        $done = $this->call('PUT', '/v1/users/authenticate/passkey/enroll', $creds + [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey']), true),
        ]);
        $this->assertSame(200, $done->getStatusCode(), $username . ': ' . $done->getBody());
        return self::json($done);
    }

    public function testEveryAdminLockedOutByAPolicySwitchGetsBackInFromTheShell(): void {
        // two admins, each with a key, signing in with it
        $this->account(3, 'admin', 1, 'rootb');
        $keys = [];
        foreach ([1 => 'admin', 3 => 'rootb'] as $id => $name) {
            $keys[$id] = new SoftAuthenticator(aaguid: sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $id));
            $this->storedKey($keys[$id], [], $id);
            TestAccounts::ensure($id, 'admin', 1, $name);
            R::exec("UPDATE users SET signin_requirement = 'passkey' WHERE id = ?", [$id]);
        }
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $session = self::json($this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($keys[1]->assert($options['publicKey']), true),
        ]));
        $this->assertSame(200, $this->call('GET', '/v1/settings/passkeys', [], $session['token'])->getStatusCode());

        // the policy now wants attested device-bound keys, which theirs are not
        $this->assertSame(0, $this->cli(ConfigPasskeysCommand::class, ['--type=device_bound'])[0]);

        $this->assertSame(401, $this->call('GET', '/v1/settings/passkeys', [], $session['token'])->getStatusCode(), 'the session ends');
        foreach (['admin', 'rootb'] as $name) {
            $locked = $this->signIn($name, self::PASSWORD);
            $this->assertSame(403, $locked->getStatusCode(), $name);
            $this->assertSame('signin_reset', self::json($locked)['required'], $name);
            $this->assertArrayNotHasKey('token', self::json($locked));
        }
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $refused = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($keys[3]->assert($options['publicKey']), true),
        ]);
        $this->assertSame(401, $refused->getStatusCode(), 'and the keys do not sign in either');

        // the shell, with no admin able to sign in
        [$code] = $this->cli(UserSigninResetCommand::class, ['--password-stdin', 'admin'], self::TEMPORARY . "\n");
        $this->assertSame(0, $code);
        [$code, $output] = $this->cli(UserSigninResetCommand::class, ['--generate', 'rootb']);
        $this->assertSame(0, $code);
        preg_match('/store it now\): (\S+)$/m', $output, $generated);

        // the software keys cannot show the attestation the policy asks for
        $this->assertSame(0, $this->cli(ConfigPasskeysCommand::class, ['--type=any'])[0]);

        $first = $this->comeBack('admin', self::TEMPORARY);
        $second = $this->comeBack('rootb', $generated[1]);

        foreach ([$first, $second] as $session) {
            $this->assertSame('passkey', $session['amr']);
            $this->assertSame(200, $this->call('GET', '/v1/settings/passkeys', [], $session['token'])->getStatusCode());
        }
        $this->assertSame([0, 0], array_map('intval', R::getCol('SELECT passkey_enroll_open FROM users WHERE id IN (1, 3) ORDER BY id')));
        $this->assertSame(403, $this->signIn('admin', self::FRESH)->getStatusCode(), 'key-only again');
    }

    public function testTheShellResetWorksWhenTheOnlyAdminHasNoWayToSignInAtAll(): void {
        R::exec("UPDATE users SET signin_requirement = 'passkey', passkey_enroll_open = 0, totp_secret = NULL WHERE id = 1");
        $this->assertSame('signin_reset', self::json($this->signIn('admin', self::PASSWORD))['required']);

        $this->cli(UserSigninResetCommand::class, ['--password=' . self::TEMPORARY, 'admin']);

        $this->assertSame('password_change', self::json($this->signIn('admin', self::TEMPORARY))['required']);
    }
}
