<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\Service\PowerDnsZones;
use RedBeanPHP\R;

/**
 * Change a domain's nameservers or authinfo. The technical and admin contact
 * options are accepted and ignored: the registry no longer has those contacts.
 * Registrant changes are not here: they are a distinct EPP command requiring
 * the authinfo to change with them, which `domain set-registrant` does.
 */
final class DomainUpdateCommand extends Command
{
    public function describe(): string {
        return 'change nameservers or authinfo on domains';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='     => "read rows from this file: 'domain', or 'domain;add-ns[:ns...];remove-ns[:ns...]'",
            'add-ns='   => 'nameservers to add, colon-separated',
            'rem-ns='   => 'nameservers to remove, colon-separated',
            'add-tech=' => 'ignored: the registry no longer has technical/admin contacts',
            'rem-tech=' => 'ignored: the registry no longer has technical/admin contacts',
            'tech='     => 'ignored: the registry no longer has technical/admin contacts',
            'admin='    => 'ignored: the registry no longer has technical/admin contacts',
            'authinfo=' => 'set a new authinfo code',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $rows = $this->rows();
        if ($rows === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $hasChange = fn(array $row) => $row['add_ns'] || $row['rem_ns'] || $row['authinfo'] !== null;

        foreach ($rows as $row) {
            if ( ! $hasChange($row) && ! $row['ignored']) {
                throw new UsageError("nothing to change for '{$row['domain']}': give at least one option");
            }
        }

        $pending = [];
        foreach ($rows as $row) {
            if ($hasChange($row)) {
                $pending[] = $row;
            } else {
                $this->line("{$row['domain']}: nothing to do (technical/admin contacts are ignored)");
            }
        }
        $rows = $pending;
        if ($rows === []) {
            return 0;
        }

        if ( ! $this->confirm('Update ' . count($rows) . ' domain(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $this->withSession(function ($nic) use ($rows) {
            foreach ($rows as $row) {
                $domain = new Domain($nic);

                // update() sends the difference against what was fetched, so
                // the read is what makes an add or a removal meaningful
                if ( ! $domain->fetch($row['domain'])) {
                    $this->itemFailed($row['domain'], $domain->getError());
                    continue;
                }

                foreach ($row['add_ns'] as $ns) {
                    $domain->addNS($ns);
                }
                foreach ($row['rem_ns'] as $ns) {
                    $domain->remNS($ns);
                }
                if ($row['authinfo'] !== null) {
                    $domain->set('authinfo', $row['authinfo']);
                }

                if ( ! $domain->hasChanges()) {
                    $this->line("{$row['domain']}: already as requested");
                    continue;
                }

                $changes = $domain->changedFields();

                // the new NS set's zone first, so the registry's DNS check
                // finds it answering -- see PowerDnsZones
                $zone = null;
                if ( ! $this->isDryRun() && in_array('ns', $changes, true)) {
                    $oldNs = array_keys(SerializedColumn::toArray((string) R::getCell('SELECT ns FROM domains WHERE domain = ?', [$row['domain']])));
                    $zone = PowerDnsZones::provision($row['domain'], array_keys((array) $domain->get('ns')));
                    if ($zone['warning'] !== null) {
                        $this->warn("{$row['domain']}: {$zone['warning']}");
                    }
                }

                if ( ! $domain->update()) {
                    if ($zone !== null) {
                        PowerDnsZones::undo($row['domain'], $zone, $oldNs);
                    }
                    $this->itemFailed($row['domain'], $domain->getError());
                    continue;
                }

                if ( ! $this->isDryRun()) {
                    // update() zeroes the mask on success, so it is passed
                    // explicitly -- see Domain::updateDB()
                    if ( ! $domain->updateDB($row['domain'], Scope::operator($this->userId()), $changes)) {
                        $this->warn("{$row['domain']}: updated at the registry, but not locally: {$domain->getError()}");
                    }
                }

                $this->record("{$row['domain']} updated", [
                    'domain' => $row['domain'],
                    'ns'     => array_keys((array) $domain->get('ns')),
                ]);
            }
        });

        return $this->outcome(DOMAIN_UPDATE_FAILED);
    }

    /**
     * One change set per domain: the options, unless a file row overrides the
     * nameserver parts, in the 'domain;add;remove' form -- for rolling
     * different nameservers onto different domains in one run.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array {
        $defaults = [
            'add_ns'   => $this->splitList((string) $this->option('add-ns', '')),
            'rem_ns'   => $this->splitList((string) $this->option('rem-ns', '')),
            'ignored'  => $this->hasOption('add-tech') || $this->hasOption('rem-tech')
                || $this->hasOption('tech') || $this->hasOption('admin'),
            'authinfo' => $this->hasOption('authinfo') ? (string) $this->option('authinfo') : null,
        ];

        $rows = [];
        foreach ($this->names() as $line) {
            if ( ! str_contains($line, ';')) {
                $rows[] = ['domain' => $line] + $defaults;
                continue;
            }

            $fields = array_map('trim', explode(';', $line));
            $rows[] = [
                'domain' => $fields[0],
                'add_ns' => isset($fields[1]) ? $this->splitList($fields[1]) : $defaults['add_ns'],
                'rem_ns' => isset($fields[2]) ? $this->splitList($fields[2]) : $defaults['rem_ns'],
            ] + $defaults;
        }
        return $rows;
    }

}
