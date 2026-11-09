<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\SigninRequirement;

/**
 * Remove one passkey, or all of a user's. A sysop tool: any user, whatever
 * --user says, and the minimum the requirement asks for does not hold it
 * back. A user left with too few can register again with the password alone,
 * until they have enough.
 */
final class UserPasskeyRevokeCommand extends UserAccountCommand
{
    public function describe(): string {
        return 'remove a passkey, or all with --all, even below the minimum (any user; ignores --user)';
    }

    public function arguments(): string {
        return '<username|id> [<passkey id>]';
    }

    public function options(): array {
        return ['all' => 'remove every passkey of the user'];
    }

    public function run(): int {
        $this->database();

        // not names(): user 5 and passkey 5 are two arguments
        $ref = $this->arguments[0] ?? '';
        $pid = $this->arguments[1] ?? null;
        if ($ref === '' || ($pid === null) === ! $this->hasOption('all') || count($this->arguments) > 2) {
            throw new UsageError('give a username or id and either a passkey id or --all');
        }
        if ($pid !== null && ! ctype_digit($pid)) {
            throw new UsageError('the passkey id is a number');
        }
        $user = $this->findUser($ref);
        if ($user === null) {
            $this->warn("no user '{$ref}'");
            return INVALID_INPUT;
        }

        $id = (int) $user['id'];
        $keys = $pid === null
            ? Passkeys::listForUser($id)
            : array_filter([Passkeys::find((int) $pid)], static fn(?array $key): bool => $key !== null && (int) $key['user_id'] === $id);
        if ($keys === []) {
            $this->warn($pid === null ? "'{$user['username']}' has no passkeys" : "'{$user['username']}' has no passkey {$pid}");
            return INVALID_INPUT;
        }

        foreach ($keys as $key) {
            Passkeys::delete((int) $key['id']);
        }
        $removed = array_map(static fn(array $key): array => ['id' => (int) $key['id'], 'name' => $key['name']], array_values($keys));
        History::record('users', $id, 'update', ['passkeys_removed' => $removed, 'by' => UserRules::SYSOP], null);
        History::record('security', 0, 'secread', [
            'event' => 'passkey_removed', 'target_id' => $id, 'target' => $user['username'],
            'credentials' => array_column($removed, 'id'), 'by' => UserRules::SYSOP,
        ], null);

        $minimum = SigninRequirement::minimumKeys((string) $user['signin_requirement']);
        $remaining = PasskeyPolicy::compliantCount($id);
        $opened = $remaining < $minimum;
        if ($opened) {
            UserRules::setEnrollOpen($id, true, null, 'passkeys revoked by a sysop');
        }

        $text = count($removed) . " passkey(s) of '{$user['username']}' removed, {$remaining} left";
        if ($opened) {
            $text .= "\n  below the minimum of {$minimum}: the password alone may register keys until they have enough";
        }
        $this->record($text, [
            'id' => $id, 'username' => $user['username'], 'removed' => array_column($removed, 'id'),
            'remaining' => $remaining, 'minimum' => $minimum, 'enroll_open' => $opened || (bool) (int) $user['passkey_enroll_open'],
        ]);
        return 0;
    }
}
