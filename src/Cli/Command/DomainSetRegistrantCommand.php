<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\ContactRefresh;

/**
 * Change a domain's registrant. Its own verb because it is its own EPP command:
 * the registry requires the authinfo to change with it, and ignores nameserver
 * or technical-contact changes sent alongside.
 */
final class DomainSetRegistrantCommand extends Command
{
    public function describe(): string {
        return 'change the registrant of domains';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'registrant=' => 'the new registrant contact handle (required)',
            'authinfo='   => 'the new authinfo code (generated when omitted)',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->domainNames();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }
        if ( ! $this->hasOption('registrant')) {
            throw new UsageError('--registrant=HANDLE is required');
        }
        $registrant = (string) $this->option('registrant');

        if ( ! $this->confirm(
            'Change the registrant of ' . count($names) . " domain(s) to {$registrant}?"
            . ' Their authinfo codes will be rotated.'
        )) {
            $this->line('nothing done');
            return 0;
        }

        $this->withSession(function ($nic) use ($names, $registrant) {
            foreach ($names as $name) {
                $domain = new Domain($nic);

                if ( ! $domain->fetch($name)) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                $previous = $domain->get('registrant');
                $domain->set('registrant', $registrant);
                // the registry rejects a registrant change that does not also
                // change the authinfo, so one is always set
                $domain->set('authinfo', (string) $this->option('authinfo', $domain->authinfo()));

                $changes = $domain->changedFields();
                if ( ! $domain->updateRegistrant()) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                if ( ! $this->isDryRun()) {
                    // updateDB() moves the domain to the new registrant's reseller
                    if ( ! $domain->updateDB($name, Scope::operator($this->userId()), $changes)) {
                        $this->failures++;
                        $this->warn("{$name}: updated at the registry, not stored (" . $domain->getError() . ')');
                    }
                    ContactRefresh::run($nic, [$previous, $registrant], $this->userId());
                }

                $this->record(
                    sprintf('%-40s registrant %s (authinfo %s)', $name, $registrant, $domain->get('authinfo')),
                    ['domain' => $name, 'registrant' => $registrant, 'authinfo' => $domain->get('authinfo')]
                );
            }
        });

        return $this->outcome(DOMAIN_UPDATE_FAILED);
    }
}
