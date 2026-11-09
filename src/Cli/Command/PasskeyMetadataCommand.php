<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\MetadataFailure;
use Eppitnic\Service\PasskeyMetadata;

/**
 * Fetch the FIDO MDS3 metadata and store what it verifies. Also what the
 * scheduled job `passkey_metadata` runs, unless passkeys are off (`cron run`
 * skips it then); by hand it always runs, whatever that job's `enabled` says.
 * --file imports a BLOB downloaded elsewhere, for a server that cannot reach
 * FIDO, under the same checks.
 */
final class PasskeyMetadataCommand extends Command
{
    public function describe(): string {
        return 'fetch the FIDO metadata device-bound passkeys are checked against';
    }

    public function options(): array {
        return [
            'file=' => 'import this BLOB (' . PasskeyMetadata::URL . ' as downloaded) instead of fetching it',
        ];
    }

    public function run(): int {
        $this->database();

        try {
            if ($this->hasOption('file')) {
                $path = (string) $this->option('file');
                $jwt = $path === '' ? false : @file_get_contents($path);
                if ($jwt === false) {
                    throw new UsageError("cannot read '{$path}'");
                }
                $result = PasskeyMetadata::import($jwt);
            } else {
                $result = PasskeyMetadata::refresh();
            }
        } catch (MetadataFailure $e) {
            $this->warn('metadata not updated (' . $e->reason . '): ' . $e->getMessage());
            return $e->reason === MetadataFailure::FETCH ? METADATA_FETCH_FAILED : METADATA_REFUSED;
        }

        $this->record(
            "metadata BLOB {$result['serial']}: {$result['entries']} authenticator(s), next update {$result['next_update']}",
            $result
        );
        return 0;
    }
}
