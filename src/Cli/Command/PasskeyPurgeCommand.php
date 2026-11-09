<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Service\WebAuthnChallenges;

/**
 * Delete the WebAuthn challenges that can no longer be used. Also what the
 * scheduled job `passkey_challenge_purge` runs; by hand it always runs,
 * whatever that job's `enabled` says.
 */
final class PasskeyPurgeCommand extends Command
{
    public function describe(): string {
        return 'delete expired WebAuthn challenges';
    }

    public function run(): int {
        $this->database();

        $purged = WebAuthnChallenges::purge();
        $this->record("purged {$purged} challenge(s)", ['purged' => $purged]);
        return 0;
    }
}
