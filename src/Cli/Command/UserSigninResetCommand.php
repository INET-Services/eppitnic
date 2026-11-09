<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Service\SigninRequirement;
use Eppitnic\Support\PasswordPolicy;

/**
 * Start a user's sign-in over: passkeys and TOTP are removed and the password
 * becomes a temporary one that must be changed. The way back in when every
 * admin is locked out. A sysop tool: any user, whatever --user says, and no
 * one needs to be able to sign in to run it.
 */
final class UserSigninResetCommand extends UserAccountCommand
{
    public function describe(): string {
        return 'remove a user\'s passkeys and TOTP and set a temporary password (any user; ignores --user)';
    }

    public function arguments(): string {
        return '<username|id>';
    }

    public function options(): array {
        return [
            'password='      => 'the temporary password',
            'password-stdin' => 'read the temporary password as one line from stdin',
            'generate'       => 'create a random temporary password and print it once',
            'requirement='   => 'also set passkey, totp, totp_or_passkey or none',
        ];
    }

    public function run(): int {
        $this->database();

        $ref = $this->names()[0] ?? '';
        if ($ref === '') {
            throw new UsageError('give a username or id');
        }
        $mode = $this->hasOption('requirement') ? (string) $this->option('requirement') : null;
        if ($mode !== null && ($error = User::signinRequirementError($mode)) !== null) {
            throw new UsageError('--requirement: ' . $error);
        }
        $password = $this->newPassword();
        if ( ! PasswordPolicy::isAcceptable($password)) {
            throw new UsageError(PasswordPolicy::explain($password));
        }

        $user = $this->findUser($ref);
        if ($user === null) {
            $this->warn("no user '{$ref}'");
            return INVALID_INPUT;
        }

        if ($mode !== null && UserRules::keysWantedButOff($mode, (string) $user['signin_requirement'])) {
            $this->warn(UserRules::PASSKEYS_OFF);
            return INVALID_INPUT;
        }

        $id = (int) $user['id'];
        $result = UserRules::resetSignin(
            $user, password_hash($password, PASSWORD_DEFAULT), null, $mode, ['by' => UserRules::SYSOP]
        );
        History::record('security', 0, 'secread', [
            'event' => 'signin_reset', 'target_id' => $id, 'target' => $user['username'],
            'passkeys_revoked' => $result['passkeys'], 'totp_removed' => $result['totp'], 'by' => UserRules::SYSOP,
        ], null);
        if ($mode === SigninRequirement::NONE && $user['signin_requirement'] !== $mode) {
            UserRules::recordNone(null, $id, $user['username']);
        }

        $text = "sign-in of '{$user['username']}' reset: {$result['passkeys']} passkey(s) removed, TOTP "
            . ($result['totp'] ? 'removed' : 'was not set') . "; the password must be changed at the next sign-in"
            . ($result['enroll_open'] ? ', and the password alone may register passkeys' : '');
        $record = ['id' => $id, 'username' => $user['username'], 'passkeys_removed' => $result['passkeys'],
                   'totp_removed' => $result['totp'], 'enroll_open' => $result['enroll_open']];
        if ($this->hasOption('generate')) {
            $text .= "\n  temporary password (shown only once, store it now): {$password}";
            $record['password'] = $password;
        }
        $this->record($text, $record);
        return 0;
    }
}
