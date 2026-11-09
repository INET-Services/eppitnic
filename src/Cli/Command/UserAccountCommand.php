<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Support\PasswordGenerator;
use RedBeanPHP\R;

/**
 * Shared by the `user` verbs that change an existing account: finding the
 * target by name or id.
 */
abstract class UserAccountCommand extends Command
{
    protected const NOT_AUTHORIZED = 'You are not authorized to perform this operation';

    /**
     * @param string $ref a username, else a numeric id
     * @return array|null the users row, or null when there is none
     */
    protected function findUser(string $ref): ?array {
        $row = R::getRow('SELECT * FROM users WHERE username = ?', [$ref]);
        if (empty($row) && ctype_digit($ref)) {
            $row = R::getRow('SELECT * FROM users WHERE id = ?', [(int) $ref]);
        }
        return empty($row) ? null : $row;
    }

    /**
     * From exactly one of --password, --password-stdin, --generate; else asked
     * for twice, hidden, at a terminal.
     */
    protected function newPassword(): string {
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
