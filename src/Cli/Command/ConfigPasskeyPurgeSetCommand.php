<?php

namespace Eppitnic\Cli\Command;

/**
 * Set (or reset to its default) one `passkey_challenge_purge` field: enabled,
 * or frequency_minutes. See AbstractCronjobSetCommand and CronjobSettings.
 */
final class ConfigPasskeyPurgeSetCommand extends AbstractCronjobSetCommand
{
    protected function job(): string {
        return 'passkey_challenge_purge';
    }

    public function describe(): string {
        return 'set or reset a passkey_challenge_purge.* setting: enabled, frequency_minutes';
    }
}
