<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\UserRules;

/**
 * Remove MFA (TOTP) from login accounts -- DELETE /v1/users/{id}/totp, for the
 * same actors. Shell access stands in for the API's MFA-verified session.
 */
final class UserMfaResetCommand extends UserAccountCommand
{
    public function describe(): string {
        return 'remove MFA; the sign-in requirement decides whether it must be set up again';
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
            $id = (int) $user['id'];
            $own = $id === $actor->userId;
            if ( ! $own && ( ! $actor->isManager() || ! UserRules::reachable($actor, $user))) {
                $this->itemFailed($user['username'], self::NOT_AUTHORIZED);
                continue;
            }

            $record = ['id' => $id, 'username' => $user['username']];
            if (empty($user['totp_secret']) && empty($user['totp_secret_pending'])) {
                $this->record("'{$user['username']}' has no MFA", $record + ['changed' => false]);
                continue;
            }

            UserRules::resetMfa($id, $actor->userId);
            $this->record("MFA removed for '{$user['username']}'", $record + ['changed' => true]);
        }
        return $this->outcome(INVALID_INPUT);
    }
}
