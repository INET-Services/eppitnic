<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Service\SigninRequirement;
use RedBeanPHP\R;

/**
 * Set what a user's login must prove -- PUT /v1/users/{id} with
 * signin_requirement, under the same rules (UserRules): only an admin sets
 * none, and nobody loosens their own.
 */
final class UserSigninRequirementCommand extends UserAccountCommand
{
    public function describe(): string {
        return 'set how a user must sign in: passkey, totp, totp_or_passkey or none';
    }

    public function arguments(): string {
        return '<username|id> <passkey|totp|totp_or_passkey|none>';
    }

    public function run(): int {
        $this->database();

        [$ref, $mode] = [$this->names()[0] ?? '', $this->names()[1] ?? ''];
        if ($ref === '' || $mode === '') {
            throw new UsageError('give a username or id and a requirement');
        }
        if (($error = User::signinRequirementError($mode)) !== null) {
            throw new UsageError($error);
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
        if (($refusal = UserRules::signinRequirementRefusal($actor, $user, $mode)) !== null) {
            $this->warn($refusal[1]);
            return INVALID_INPUT;
        }

        $id = (int) $user['id'];
        $changed = $user['signin_requirement'] !== $mode;
        if ($changed) {
            $open = UserRules::enrollOpenAfter($user, $mode);
            R::exec('UPDATE users SET signin_requirement = ?, passkey_enroll_open = ? WHERE id = ?', [$mode, $open, $id]);
            History::record('users', $id, 'update', [
                'signin_requirement' => $mode,
            ] + ($open !== (int) $user['passkey_enroll_open'] ? ['passkey_enroll_open' => $open] : []), $actor->userId);
            if ($mode === SigninRequirement::NONE && $user['signin_requirement'] !== $mode) {
                UserRules::recordNone($actor->userId, $id, $user['username']);
            }
        }

        $this->record(
            $changed
                ? "user '{$user['username']}' now signs in with: {$mode}"
                : "user '{$user['username']}' already signs in with: {$mode}",
            ['id' => $id, 'username' => $user['username'], 'signin_requirement' => $mode, 'changed' => $changed]
        );
        return 0;
    }
}
