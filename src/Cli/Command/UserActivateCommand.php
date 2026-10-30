<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\UserRules;
use RedBeanPHP\R;

/**
 * Enable login accounts -- PUT /v1/users/{id} with active=1, under the same
 * rules (UserRules).
 */
class UserActivateCommand extends UserAccountCommand
{
    /** the value of users.active this command sets */
    protected const ACTIVE = 1;

    public function describe(): string {
        return 'enable login accounts';
    }

    public function arguments(): string {
        return '<username|id>...';
    }

    public function run(): int {
        $this->database();

        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one username or id');
        }
        $actor = $this->actor();

        foreach ($names as $ref) {
            $user = $this->findUser($ref);
            if ($user === null) {
                $this->itemFailed($ref, 'no such user');
                continue;
            }
            $error = ! $actor->isManager() || ! UserRules::reachable($actor, $user)
                ? self::NOT_AUTHORIZED
                : $this->refusal($user, $actor->userId);
            if ($error !== null) {
                $this->itemFailed($user['username'], $error);
                continue;
            }

            $id = (int) $user['id'];
            $word = static::ACTIVE === 1 ? 'active' : 'inactive';
            $changed = (int) $user['active'] !== static::ACTIVE;
            if ($changed) {
                R::exec('UPDATE users SET active = ? WHERE id = ?', [static::ACTIVE, $id]);
                History::record('users', $id, 'update', ['active' => static::ACTIVE], $actor->userId);
            }
            $this->record(
                $changed ? "user '{$user['username']}' is now {$word}" : "user '{$user['username']}' is already {$word}",
                ['id' => $id, 'username' => $user['username'], 'active' => static::ACTIVE, 'changed' => $changed]
            );
        }
        return $this->outcome(INVALID_INPUT);
    }

    /**
     * @param array $user the target's users row
     * @return string|null why this change is refused, or null
     */
    protected function refusal(array $user, int $actorId): ?string {
        return null;
    }
}
