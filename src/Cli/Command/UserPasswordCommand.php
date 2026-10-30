<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Support\PasswordGenerator;
use Eppitnic\Support\PasswordPolicy;
use RedBeanPHP\R;

/**
 * Reset a user's password -- the admin reset of PUT /v1/users/{id}, under the
 * same password policy. Existing sessions and API tokens stay valid, as there.
 */
final class UserPasswordCommand extends UserAccountCommand
{
    public function describe(): string {
        return 'reset a user\'s password';
    }

    public function arguments(): string {
        return '<username|id>';
    }

    public function options(): array {
        return [
            'password='       => 'the new plaintext password, hashed before storing',
            'password-stdin'  => 'read the new password as one line from stdin',
            'generate'        => 'create a random password and print it once',
            'must-change-password' => 'refuse login until the user has changed the password',
        ];
    }

    public function run(): int {
        $this->database();

        $ref = $this->names()[0] ?? '';
        if ($ref === '') {
            throw new UsageError('give a username or id');
        }
        $password = $this->newPassword();
        $generated = $this->hasOption('generate');

        if ( ! PasswordPolicy::isAcceptable($password)) {
            throw new UsageError(PasswordPolicy::explain($password));
        }

        $user = $this->findUser($ref);
        if ($user === null) {
            $this->warn("no user '{$ref}'");
            return INVALID_INPUT;
        }
        $actor = $this->actor();
        $own = (int) $user['id'] === $actor->userId;
        if ( ! $own && ( ! $actor->isManager() || ! UserRules::reachable($actor, $user))) {
            $this->warn(self::NOT_AUTHORIZED);
            return INVALID_INPUT;
        }

        $mustChange = $this->hasOption('must-change-password');
        R::exec('UPDATE users SET password = ?' . ($mustChange ? ', must_change_password = 1' : '') . ' WHERE id = ?', [
            password_hash($password, PASSWORD_DEFAULT),
            (int) $user['id'],
        ]);
        History::record(
            'users',
            (int) $user['id'],
            'update',
            ['password' => 'PASSWORD_CHANGED'] + ($mustChange ? ['must_change_password' => 1] : []),
            $actor->userId
        );

        $text = "password of '{$user['username']}' changed";
        $record = ['id' => (int) $user['id'], 'username' => $user['username'], 'must_change_password' => $mustChange];
        if ($generated) {
            $text .= "\n  password (shown only once, store it now): {$password}";
            $record['password'] = $password;
        }
        $this->record($text, $record);
        return 0;
    }

    /**
     * From exactly one of --password, --password-stdin, --generate; else asked
     * for twice, hidden, at a terminal.
     */
    private function newPassword(): string {
        $given = array_filter(['password', 'password-stdin', 'generate'], fn($o) => $this->hasOption($o));
        if (count($given) > 1) {
            throw new UsageError('give only one of --password, --password-stdin and --generate');
        }
        if ($this->hasOption('password')) {
            return (string) $this->option('password');
        }
        if ($this->hasOption('generate')) {
            return (new PasswordGenerator(20, true, requireNumber: true))->get();
        }
        if ($this->hasOption('password-stdin')) {
            return $this->readPasswordStdin();
        }
        if ( ! stream_isatty(STDIN)) {
            throw new UsageError('give one of --password, --password-stdin or --generate');
        }
        return $this->promptNewPassword();
    }
}
