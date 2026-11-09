<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;
use Eppitnic\Service\ContactRefresh;
use RedBeanPHP\R;

/**
 * Delete domains at the registry, and deactivate them locally.
 */
final class DomainDeleteCommand extends Command
{
    public function describe(): string {
        return 'delete domains at the registry';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'all-resellers' => 'operate on any reseller\'s domains, not just --user\'s reseller\'s',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->domainNames();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        if ( ! $this->confirm('Delete ' . count($names) . ' domain(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $scope = $this->scope($this->hasOption('all-resellers'));

        $this->withSession(function ($nic) use ($names, $scope) {
            foreach ($names as $name) {
                $domain = new Domain($nic);
                $registrant = R::getCell('SELECT registrant FROM domains WHERE domain = ?', [$name]);

                if ( ! $domain->delete($name)) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                // a dry run reached this point on a synthetic success, so the
                // local row must not be touched
                if ( ! $this->isDryRun()) {
                    $domain->deleteDomainDB($name, $scope);
                    ContactRefresh::run($nic, [$registrant ?: null], $this->userId());
                }

                $this->record("{$name} deleted", ['domain' => $name, 'deleted' => true]);
            }
        });

        return $this->outcome(DOMAIN_DELETE_FAILED);
    }
}
