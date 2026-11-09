<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\SigninRequirement;

/**
 * List a user's passkeys -- GET /v1/users/{id}/passkeys without the session.
 * A sysop tool: any user, whatever --user says.
 */
final class UserPasskeysCommand extends UserAccountCommand
{
    public function describe(): string {
        return 'list a user\'s passkeys (any user; ignores --user)';
    }

    public function arguments(): string {
        return '<username|id>';
    }

    public function run(): int {
        $this->database();

        $ref = $this->names()[0] ?? '';
        if ($ref === '') {
            throw new UsageError('give a username or id');
        }
        $user = $this->findUser($ref);
        if ($user === null) {
            $this->warn("no user '{$ref}'");
            return INVALID_INPUT;
        }

        $settings = PasskeySettings::get();
        $keys = [];
        foreach (Passkeys::listForUser((int) $user['id']) as $row) {
            $keys[] = [
                'id'                   => (int) $row['id'],
                'name'                 => $row['name'],
                'aaguid'               => $row['aaguid'],
                'compliant'            => PasskeyPolicy::complies($row, $settings),
                'backup_eligible'      => (bool) (int) $row['backup_eligible'],
                'backup_state'         => (bool) (int) $row['backup_state'],
                'attestation_format'   => $row['attestation_format'],
                'attestation_verified' => (bool) (int) $row['attestation_verified'],
                'created'              => $row['created_at'],
                'last_used'            => $row['last_used_at'],
            ];
        }

        $minimum = SigninRequirement::minimumKeys((string) $user['signin_requirement']);
        $compliant = count(array_filter($keys, static fn(array $key): bool => $key['compliant']));
        $open = (bool) (int) $user['passkey_enroll_open'];

        $text = "{$user['username']}: {$user['signin_requirement']}"
            . ", minimum {$minimum}, {$compliant} compliant of " . count($keys)
            . ', enrolment ' . ($open ? 'open' : 'closed')
            . (PasskeySettings::enabled() ? '' : ', passkeys are off');
        foreach ($keys as $key) {
            $text .= sprintf(
                "\n  %-5d %-24s %-36s %-4s created %s, last used %s",
                $key['id'], mb_strimwidth((string) $key['name'], 0, 24, '…'), $key['aaguid'],
                $key['compliant'] ? 'ok' : 'NO', $key['created'], $key['last_used'] ?? 'never'
            );
        }

        $this->record($text, [
            'id'                 => (int) $user['id'],
            'username'           => $user['username'],
            'signin_requirement' => $user['signin_requirement'],
            'minimum'            => $minimum,
            'compliant_count'    => $compliant,
            'enroll_open'        => $open,
            'passkeys_available' => PasskeySettings::enabled(),
            'passkeys'           => $keys,
        ]);
        return 0;
    }
}
