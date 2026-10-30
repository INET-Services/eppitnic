<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;

/**
 * Everything the registry knows about a domain.
 */
final class DomainInfoCommand extends Command
{
    public function describe(): string {
        return 'show registry information for domains';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + [
            'authinfo=' => 'authinfo code, for a domain sponsored by another registrar',
            'contacts=' => "also fetch linked contacts: all or registrant",
            'store'     => 'write what was fetched to the local database',
        ];
    }

    public function run(): int {
        $names = $this->names();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $contacts = (string) $this->option('contacts', '');
        if ($contacts !== '' && ! in_array($contacts, ['all', 'registrant'], true)) {
            throw new UsageError("--contacts must be one of: all, registrant");
        }

        $authinfo = (string) $this->option('authinfo', '');
        $store = $this->hasOption('store');
        $userId = $this->userId();

        $this->withSession(function ($nic) use ($names, $authinfo, $contacts, $store, $userId) {
            foreach ($names as $name) {
                // a fresh object per domain: fetch() re-initialises, but
                // reusing one across names has bitten this codebase before
                $domain = new Domain($nic);

                if ( ! $domain->fetch($name, $authinfo ?: null, $contacts)) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                $record = [
                    'domain'     => $domain->get('domain'),
                    'registrant' => $domain->get('registrant'),
                    'admin'      => $domain->get('admin'),
                    'tech'       => array_keys((array) $domain->get('tech')),
                    'ns'         => array_keys((array) $domain->get('ns')),
                    'status'     => $domain->get('status'),
                    'authinfo'   => $domain->get('authinfo'),
                    'cr_date'    => $domain->get('crDate'),
                    'ex_date'    => $domain->get('exDate'),
                    'dnssec'     => $domain->get('dnssec'),
                ];
                if ($contacts !== '') {
                    $record['contacts'] = $domain->get('infcontacts');
                }

                $this->record($this->render($record), $record);

                if ($store) {
                    if ($domain->storeDB($userId)) {
                        $this->line('  stored locally');
                    } else {
                        $this->failures++;
                        $this->warn("{$name}: not stored (" . $domain->getError() . ')');
                    }
                }
            }
        });

        return $this->outcome(DOMAIN_FETCH_FAILED);
    }

    /**
     * @param array $d the record built above
     * @return string the human-readable rendering
     */
    private function render(array $d): string {
        $out = $d['domain'] . "\n";
        $out .= sprintf("  %-12s %s\n", 'registrant', $d['registrant']);
        if ($d['admin'] !== '') {
            $out .= sprintf("  %-12s %s\n", 'admin', $d['admin']);
        }
        foreach ($d['tech'] as $tech) {
            $out .= sprintf("  %-12s %s\n", 'tech', $tech);
        }
        foreach ($d['ns'] as $ns) {
            $out .= sprintf("  %-12s %s\n", 'ns', $ns);
        }
        $out .= sprintf("  %-12s %s\n", 'status', implode(', ', (array) $d['status']));
        $out .= sprintf("  %-12s %s\n", 'created', $d['cr_date']);
        $out .= sprintf("  %-12s %s\n", 'expires', $d['ex_date']);
        $out .= sprintf("  %-12s %s", 'authinfo', $d['authinfo']);

        foreach ((array) $d['dnssec'] as $digest => $key) {
            $out .= sprintf("\n  %-12s keytag %s, alg %s, digest %s",
                'dnssec', $key['keytag'], $key['algorithm'], $digest);
        }
        return $out;
    }
}
