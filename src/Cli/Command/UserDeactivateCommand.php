<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Persistence\UserRules;

/**
 * Disable login accounts -- PUT /v1/users/{id} with active=0, under the same
 * rules (UserRules): never the last active admin or manager, never oneself.
 */
final class UserDeactivateCommand extends UserActivateCommand
{
    protected const ACTIVE = 0;

    public function describe(): string {
        return 'disable login accounts';
    }

    protected function refusal(array $user, int $actorId): ?string {
        if ((int) $user['active'] === 0) {
            return null;
        }
        return UserRules::lastStandingError($user, false, true)
            ?? ((int) $user['id'] === $actorId ? UserRules::SELF_DEACTIVATION : null);
    }
}
