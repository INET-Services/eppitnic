<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DomainService;

/**
 * Register domains, or request a transfer for any held elsewhere -- the
 * registry's answer decides, not the caller: a name somebody else holds becomes
 * a transfer request rather than an error. Shared with POST /v1/domains.
 */
final class DomainCreateCommand extends Command
{
    public function describe(): string {
        return 'register domains, or request a transfer if already held';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return [
            'file='       => "read domains from this file; either one name per line, or ';'-separated rows of domain;registrant;tech[:tech...];ns[:ns...] (the tech column is ignored)",
            'registrant=' => 'registrant contact handle (required unless every --file row supplies its own)',
            'admin='      => 'ignored: the registry no longer has administrative contacts',
            'tech='       => 'ignored: the registry no longer has technical contacts',
            'ns='         => 'nameservers, colon-separated -- 2 to 6 (required)',
            'authinfo='   => 'authinfo code (generated when omitted)',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $rows = $this->rows();
        if ($rows === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        foreach ($rows as $row) {
            if ($row['registrant'] === '') {
                throw new UsageError("no registrant for '{$row['domain']}': pass --registrant, or give one in the file row");
            }
        }

        if ( ! $this->confirm('Register or transfer ' . count($rows) . ' domain(s)? This is a chargeable operation.')) {
            $this->line('nothing done');
            return 0;
        }

        $userId = $this->userId();
        $dryRun = $this->isDryRun();

        $this->withSession(function ($nic) use ($rows, $userId, $dryRun) {
            foreach ($rows as $row) {
                // a dry run must not write the local row, so persistence is
                // switched off rather than the result being discarded after
                $result = DomainService::createOrTransfer($nic, $row, $userId, ! $dryRun);

                if ( ! $result['ok']) {
                    $this->itemFailed($row['domain'], $result['error']);
                    continue;
                }

                foreach ($result['warnings'] as $warning) {
                    $this->warn("{$row['domain']}: {$warning}");
                }

                $domain = $result['domain'];
                $this->record(
                    sprintf('%-40s %s (authinfo %s)', $row['domain'], $result['action'], $domain->get('authinfo')),
                    [
                        'domain'   => $domain->get('domain'),
                        'action'   => $result['action'],
                        'authinfo' => $domain->get('authinfo'),
                    ]
                );
            }
        });

        return $this->outcome(DOMAIN_CREATE_FAILED);
    }

    /**
     * One parameter set per domain. A --file may carry bare names, taking their
     * contacts and nameservers from the options, or ';'-separated rows giving
     * their own -- a bulk registration has no other reasonable shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array {
        $defaults = [
            'registrant' => (string) $this->option('registrant', ''),
            'ns'         => $this->splitList((string) $this->option('ns', '')),
        ];
        if ($this->hasOption('authinfo')) {
            $defaults['authinfo'] = (string) $this->option('authinfo');
        }

        $rows = [];
        foreach ($this->names() as $line) {
            if ( ! str_contains($line, ';')) {
                $rows[] = ['domain' => $line] + $defaults;
                continue;
            }

            $fields = array_map('trim', explode(';', $line));
            $rows[] = [
                'domain'     => $fields[0],
                'registrant' => $fields[1] ?? $defaults['registrant'],
                'ns'         => isset($fields[3]) ? $this->splitList($fields[3]) : $defaults['ns'],
            ] + $defaults;
        }
        return $rows;
    }

}
