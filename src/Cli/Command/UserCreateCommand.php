<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UsernameTaken;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Service\SigninRequirement;
use RedBeanPHP\R;

/**
 * Create a local login account. Once config/config.php exists this is the only
 * way to make further ones: `eppitnic setup` and the installers only ever
 * create the first.
 */
final class UserCreateCommand extends Command
{
    public function describe(): string {
        return 'create a local login account';
    }

    public function arguments(): string {
        return '<username>';
    }

    public function options(): array {
        return [
            'password='       => 'plaintext password, hashed before storing (required)',
            'email='          => 'e-mail address, unique among users; password resets are sent to it (required)',
            'description='    => 'free-text description',
            'role='           => 'admin, manager or user (default: user); admin only in reseller 1',
            'reseller='       => 'the reseller id the user belongs to, fixed from now on (default: 1)',
            'must-change-password' => 'refuse login until the user has changed the password',
            'signin-requirement='  => 'passkey, totp, totp_or_passkey or none (default: totp); none is a weaker login',
        ];
    }

    public function run(): int {
        $this->database();

        $names = $this->names();
        $username = $names[0] ?? '';
        if ($username === '') {
            throw new UsageError('give a username');
        }
        if ( ! $this->hasOption('password')) {
            throw new UsageError('--password is required');
        }
        $email = $this->option('email');
        if (($error = User::emailError($email)) !== null) {
            throw new UsageError('--email: ' . $error);
        }

        $mode = (string) $this->option('signin-requirement', SigninRequirement::TOTP);
        if ( ! in_array($mode, SigninRequirement::MODES, true)) {
            throw new UsageError('--signin-requirement must be one of: ' . implode(', ', SigninRequirement::MODES));
        }

        if (UserRules::keysWantedButOff($mode)) {
            throw new UsageError('--signin-requirement: ' . UserRules::PASSKEYS_OFF);
        }

        $role = (string) $this->option('role', 'user');
        $resellerId = (int) $this->option('reseller', 1);
        if (($error = User::roleError($role, $resellerId)) !== null) {
            throw new UsageError($error);
        }
        if ((int) R::getCell('SELECT COUNT(*) FROM resellers WHERE id = ?', [$resellerId]) === 0) {
            throw new UsageError("no reseller with id {$resellerId}");
        }

        try {
            $id = User::create(
                username: $username,
                password: (string) $this->option('password'),
                email: trim($email),
                description: $this->option('description'),
                resellerId: $resellerId,
                role: $role,
                mustChangePassword: $this->hasOption('must-change-password'),
                actorId: $this->userId(),
                signinRequirement: $mode,
            );
        } catch (UsernameTaken $e) {
            $this->warn($e->getMessage());
            return INVALID_INPUT;
        }

        if ($mode === SigninRequirement::NONE) {
            UserRules::recordNone($this->userId(), $id, $username);
        }

        $this->record(
            "user '{$username}' created (id {$id}, {$role} of reseller {$resellerId})",
            ['id' => $id, 'username' => $username, 'role' => $role, 'reseller_id' => $resellerId, 'signin_requirement' => $mode]
        );
        return 0;
    }
}
