<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\Service\DomainService;
use Eppitnic\Service\PowerDnsZones;
use RedBeanPHP\R;

/**
 * Change a domain's nameservers, technical contacts, admin contact or authinfo.
 * Registrant changes are not here: they are a distinct EPP command requiring
 * the authinfo to change with them, which `domain set-registrant` does.
 */
final class DomainUpdateCommand extends Command
{
    public function describe(): string {
        return 'change nameservers, contacts or authinfo on domains';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='     => "read rows from this file: 'domain', or 'domain;add-ns[:ns...];remove-ns[:ns...]'",
            'add-ns='   => 'nameservers to add, colon-separated',
            'glue='     => "set glue addresses, 'HOST=IPV4[,IPV6]' separated by ';' (adds the host if new; a bare 'HOST=' clears its glue)",
            'rem-ns='   => 'nameservers to remove, colon-separated',
            'add-tech=' => 'technical contacts to add, colon-separated',
            'rem-tech=' => 'technical contacts to remove, colon-separated',
            'tech='     => 'the complete technical contact list; anything else is removed',
            'admin='    => 'set the administrative contact',
            'authinfo=' => 'set a new authinfo code',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $rows = $this->rows();
        if ($rows === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $hasChange = fn(array $row) => $row['add_ns'] || $row['rem_ns'] || $row['glue'] || $row['add_tech']
            || $row['rem_tech'] || $row['tech'] !== null || $row['admin'] !== null || $row['authinfo'] !== null;

        foreach ($rows as $row) {
            if ( ! $hasChange($row)) {
                throw new UsageError("nothing to change for '{$row['domain']}': give at least one option");
            }
        }

        // bare names need the fetched domain's glue, so only --glue is checked
        foreach ($rows as $row) {
            if (($error = DomainService::glueError($row['domain'], $row['glue'])) !== null) {
                throw new UsageError("{$row['domain']}: {$error}");
            }
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

                $domain->startFromPendingNs();

                // a host given glue is not also added bare
                $glued = array_map('strtolower', array_column($row['glue'], 'name'));
                $bare = array_filter($row['add_ns'], static fn($ns) => ! in_array(strtolower($ns), $glued, true));
                $entries = array_merge(
                    array_map(static fn($ns) => ['name' => $ns, 'ip' => null], $bare),
                    $row['glue']
                );
                $glueError = DomainService::glueError($row['domain'], $entries, (array) $domain->get('ns'));
                if ($glueError !== null) {
                    $this->itemFailed($row['domain'], $glueError);
                    continue;
                }
                foreach ($entries as $ns) {
                    if ($domain->addNS($ns['name'], $ns['ip']) === false) {
                        $this->itemFailed($row['domain'], $domain->getError());
                        continue 2;
                    }
                }
                foreach ($row['rem_ns'] as $ns) {
                    $domain->remNS($ns);
                }
                foreach ($row['add_tech'] as $tech) {
                    $domain->addTECH($tech);
                }
                foreach ($row['rem_tech'] as $tech) {
                    $domain->remTECH($tech);
                }

                // --tech is a target list, not a delta: whatever the domain
                // has that is not in it goes. This is what makes syncing a set
                // of domains onto one technical contact a single command.
                if ($row['tech'] !== null) {
                    $current = array_keys((array) $domain->get('tech'));
                    foreach (array_diff($current, $row['tech']) as $gone) {
                        $domain->remTECH($gone);
                    }
                    foreach (array_diff($row['tech'], $current) as $added) {
                        $domain->addTECH($added);
                    }
                }

                if ($row['admin'] !== null) {
                    $domain->set('admin', $row['admin']);
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
                    'tech'   => array_keys((array) $domain->get('tech')),
                    'admin'  => $domain->get('admin'),
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
        $glue = DomainService::parseGlue((string) $this->option('glue', ''));
        if (is_string($glue)) {
            throw new UsageError("--glue: {$glue}");
        }
        $defaults = [
            'glue'     => $glue,
            'add_ns'   => $this->splitList((string) $this->option('add-ns', '')),
            'rem_ns'   => $this->splitList((string) $this->option('rem-ns', '')),
            'add_tech' => $this->splitList((string) $this->option('add-tech', '')),
            'rem_tech' => $this->splitList((string) $this->option('rem-tech', '')),
            'tech'     => $this->hasOption('tech') ? $this->splitList((string) $this->option('tech')) : null,
            'admin'    => $this->hasOption('admin') ? (string) $this->option('admin') : null,
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
