<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DomainService;

/**
 * Copy domains the registry already holds into the local database.
 * Reconciliation, not registration: nothing is created there, and a name it
 * does not have is deactivated locally. Shared with POST /v1/domains/import.
 */
final class DomainImportCommand extends Command
{
    public function describe(): string {
        return 'import registry domains into the local database';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'all-resellers' => 'also reconcile other resellers\' domains, not just --user\'s reseller\'s',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->domainNames();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        if ($this->isDryRun()) {
            // import's writes are all local, so there is no request to preview
            // and nothing that --dry-run could usefully show
            throw new UsageError('--dry-run does not apply to import: it changes nothing at the registry');
        }

        $userId = $this->userId();

        $results = $this->withSession(
            fn($nic) => DomainService::import($nic, $names, $this->scope($this->hasOption('all-resellers')))
        );

        foreach ($results as $name => $steps) {
            $ok = $steps['domain_stored'] === 'stored' && $steps['contact_stored'] === 'stored';
            if ( ! $ok) {
                $this->failures++;
            }

            if (isset($steps['error'])) {
                $this->warn("{$name}: " . $steps['error']);
            }

            $this->record(
                sprintf('%-40s domain %s, registrant %s, contact %s, domain %s',
                    $name,
                    $steps['domain'],
                    $steps['registrant'],
                    $steps['contact_stored'],
                    $steps['domain_stored']),
                // 'name' rather than 'domain': within a result, 'domain' is
                // the outcome of the domain lookup, not which domain it was
                ['name' => $name] + $steps
            );
        }

        return $this->outcome(DOMAIN_IMPORT_FAILED);
    }
}
