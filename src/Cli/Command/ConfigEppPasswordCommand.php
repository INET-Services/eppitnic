<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Support\Validate;

/**
 * Change the shared EPP registry password, or with --force adopt one already
 * valid there. Never a plain local write -- a password the registry disagrees
 * with breaks every call -- and never through withSession(): it *is* the login.
 */
final class ConfigEppPasswordCommand extends Command
{
    public function describe(): string {
        return 'change the shared EPP registry password, or --force to adopt one already valid there';
    }

    public function arguments(): string {
        return '[<new-password>]';
    }

    public function options(): array {
        // not MUTATING_OPTIONS: its --dry-run promises the EPP XML other
        // commands print, and this never opens a session, so it can only
        // describe what it would do
        return [
            'force'   => 'do not change the password at the registry -- verify the given value is already ' .
                'accepted there, and adopt it locally as-is',
            'password-stdin' => 'read the password as one line from stdin, instead of <new-password>',
            'dry-run' => 'describe what would happen (change, or verify-and-adopt), without doing it',
            'yes'     => 'do not ask for confirmation',
        ];
    }

    public function run(): int {
        $this->database();

        $password = $this->password();

        // the registry's own rule (epp:pwType), shared with first-run setup
        // and POST /v1/session/change-password rather than stated three
        // times -- see Support\Validate::eppField()
        if ($error = Validate::eppField('password', $password)) {
            throw new UsageError($error);
        }

        $force = $this->hasOption('force');
        $verb = $force ? 'Adopt (verify only, no registry change to)' : 'Change';
        if ( ! $this->confirm("{$verb} the shared EPP registry password?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line($force
                ? 'would verify the given password against the registry and adopt it, without changing it there'
                : 'would change the registry password to the given value');
            return 0;
        }

        $outcome = $force
            ? RegistryPasswordChange::adopt($password, $this->userId())
            : RegistryPasswordChange::apply($password, true, 'manual', $this->userId());

        if ( ! $outcome['ok']) {
            $this->warn($outcome['error']);
            return CHANGE_PASSWORD_FAILED;
        }
        if (isset($outcome['warning'])) {
            $this->warn($outcome['warning']);
        }

        $epp = Config::get('epp');
        $this->record('registry password ' . ($force ? 'adopted' : 'changed'), [
            'password_set'       => ($epp['password'] ?? '') !== '',
            'rotation_pending'   => ($epp['pendingPassword'] ?? '') !== '',
            'lastPasswordUpdate' => $epp['lastPasswordUpdate'] ?? 0,
        ]);
        return 0;
    }

    /**
     * From exactly one of <new-password> and --password-stdin; else asked for
     * twice, hidden, at a terminal. Nothing is generated: --force needs the
     * live value, and a change is the caller's to choose.
     */
    private function password(): string {
        if (count($this->arguments) > 1) {
            throw new UsageError('give one password');
        }
        $positional = $this->arguments[0] ?? null;
        $stdin = $this->hasOption('password-stdin');
        if ($positional !== null && $stdin) {
            throw new UsageError('give only one of <new-password> and --password-stdin');
        }
        if ($positional !== null) {
            return $positional;
        }
        if ($stdin) {
            return $this->readPasswordStdin();
        }
        if ( ! stream_isatty(STDIN)) {
            throw new UsageError('give the password as <new-password> or --password-stdin');
        }
        return $this->promptNewPassword();
    }
}
