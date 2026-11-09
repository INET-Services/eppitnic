<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DomainService;

/**
 * Move domains to another reseller. Not just a `domains`.`reseller_id`
 * update: the registrant and admin contacts are copied into the new reseller
 * and the domain repointed, or the two ownerships drift. Shared with the
 * owner route.
 */
final class DomainSetOwnerCommand extends Command
{
    public function describe(): string {
        return 'move domains to another reseller';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'new-reseller=' => 'the reseller id to move the domains to (required)',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->domainNames();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        if ( ! $this->hasOption('new-reseller')) {
            throw new UsageError('--new-reseller=ID is required (the reseller to move the domains to)');
        }
        $resellerId = (int) $this->option('new-reseller');
        if ($resellerId <= 0) {
            throw new UsageError('--new-reseller must be a reseller id');
        }

        // What this sends is built from records only the registry has, so a
        // preview would show the right shape with invented contents. Checked
        // before the prompt, so nobody confirms something that will not run
        if ($this->isDryRun()) {
            throw new UsageError(
                '--dry-run does not apply to set-owner: the contacts it creates are copies of'
                . ' registry records, which a dry run cannot read'
            );
        }

        if ( ! $this->confirm(
            'Move ' . count($names) . " domain(s) to reseller {$resellerId}?"
            . ' Their registrant and admin contacts will be duplicated at the registry.'
        )) {
            $this->line('nothing done');
            return 0;
        }

        $actorId = $this->userId();
        $this->withSession(function ($nic) use ($names, $resellerId, $actorId) {
            foreach ($names as $name) {
                $result = DomainService::changeOwner($nic, $name, $resellerId, $actorId);

                if ( ! $result['ok']) {
                    $this->itemFailed($name, $result['error']);
                    continue;
                }

                $domain = $result['domain'];
                $this->record(
                    sprintf('%-40s moved to reseller %d (registrant %s)', $name, $resellerId, $domain->get('registrant')),
                    [
                        'domain'      => $name,
                        'reseller_id' => $resellerId,
                        'registrant' => $domain->get('registrant'),
                        'admin'      => $domain->get('admin'),
                    ]
                );
            }
        });

        return $this->outcome(DOMAIN_UPDATE_FAILED);
    }
}
