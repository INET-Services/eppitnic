<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
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
}
