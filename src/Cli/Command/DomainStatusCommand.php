<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;

/**
 * Add or remove a client-side status on domains.
 */
final class DomainStatusCommand extends Command
{
    private const STATES = [
        'clientDeleteProhibited',
        'clientUpdateProhibited',
        'clientTransferProhibited',
        'clientHold',
    ];

    public function describe(): string {
        return 'add or remove a client status on domains';
    }

    public function arguments(): string {
        return 'add|rem <state> <domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names') + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();

        // the first two positional arguments are the operation and the state;
        // names() has already folded --file in, so take them off the front
        $action = array_shift($names);
        $state = array_shift($names);

        if ( ! in_array($action, ['add', 'rem'], true)) {
            throw new UsageError("first argument must be 'add' or 'rem'");
        }
        if ( ! in_array($state, self::STATES, true)) {
            throw new UsageError('state must be one of: ' . implode(', ', self::STATES));
        }
        $names = self::domainList($names);
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $verb = $action === 'add' ? 'Set' : 'Clear';
        if ( ! $this->confirm("{$verb} {$state} on " . count($names) . ' domain(s)?')) {
            $this->line('nothing done');
            return 0;
        }

        $this->withSession(function ($nic) use ($names, $action, $state) {
            foreach ($names as $name) {
                $domain = new Domain($nic);

                // updateStatus() works off the object's own status list, so the
                // domain has to be read before it can be changed
                if ( ! $domain->fetch($name)) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                if ( ! $domain->updateStatus($state, $action)) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                $this->record(
                    "{$name} " . ($action === 'add' ? 'now has ' : 'no longer has ') . $state,
                    ['domain' => $name, 'state' => $state, 'action' => $action]
                );
            }
        });

        return $this->outcome(DOMAIN_UPDATE_FAILED);
    }
}
