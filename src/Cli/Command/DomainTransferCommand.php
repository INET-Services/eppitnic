<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Api\Access;
use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\DomainService;
use Eppitnic\Support\Idn;
use RedBeanPHP\R;

/**
 * The four sides of a domain transfer.
 *
 *   request  claim a domain held by another registrar; with a registrant, also
 *            trade it to that registrant (transfer-trade)
 *   approve  agree to somebody else's claim on a domain we hold
 *   reject   refuse it
 *   cancel   withdraw a request we made ourselves
 */
final class DomainTransferCommand extends Command
{
    private const OPERATIONS = ['request', 'approve', 'reject', 'cancel'];

    public function describe(): string {
        return 'request, approve, reject or cancel a domain transfer';
    }

    public function arguments(): string {
        return 'request|approve|reject|cancel <domain>...';
    }

    public function options(): array {
        return [
            'file='       => "read rows from this file: 'domain', 'domain;authinfo' or 'domain;authinfo;registrant' per line",
            'authinfo='   => 'the authinfo code, when it is the same for every domain',
            'registrant=' => 'makes a request a transfer-trade: the domain changes to this registrant contact (a row may carry its own); without, it keeps its registrant',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $names = $this->names();
        $operation = array_shift($names);

        if ( ! in_array($operation, self::OPERATIONS, true)) {
            throw new UsageError('first argument must be one of: ' . implode(', ', self::OPERATIONS));
        }
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        // 'domain;authinfo;registrant' rows, or a shared --authinfo/--registrant
        $shared = (string) $this->option('authinfo', '');
        $sharedRegistrant = (string) $this->option('registrant', '');
        $targets = [];
        $registrants = [];
        foreach ($names as $line) {
            $fields = array_map('trim', explode(';', $line));
            $name = Idn::domain($fields[0]);
            $targets[$name] = ($fields[1] ?? '') !== '' ? $fields[1] : $shared;
            $registrants[$name] = ($fields[2] ?? '') !== '' ? $fields[2] : $sharedRegistrant;
        }

        // a request is the one operation the registry will not accept without
        // it; the others authorise against a domain we already sponsor
        if ($operation === 'request') {
            foreach ($targets as $name => $authinfo) {
                if ($authinfo === '') {
                    throw new UsageError("no authinfo for '{$name}': pass --authinfo, or use 'domain;authinfo' rows");
                }
            }
        }

        if ( ! $this->confirm(ucfirst($operation) . ' transfer of ' . count($targets) . ' domain(s)?')) {
            $this->line('nothing done');
            return 0;
        }

        $dryRun = $this->isDryRun();
        // a dry run records nothing locally, so it needs no database either
        $scope = $dryRun ? Scope::operator($this->userId()) : $this->scope();

        // refused before the registry hears of it: the row is stored under it
        if ($operation === 'request' && ! $dryRun) {
            foreach (array_unique(array_filter($registrants, 'strlen')) as $handle) {
                if ((int) R::getCell('SELECT COUNT(*) FROM contacts WHERE handle = ?', [$handle]) === 0) {
                    throw new UsageError("registrant '{$handle}' is not a stored contact");
                }
                if ( ! Access::canUseAsRegistrant($handle, $scope)) {
                    throw new UsageError("contact '{$handle}' is not yours to use as registrant");
                }
            }
        }

        $this->withSession(function ($nic) use ($targets, $registrants, $operation, $scope, $dryRun) {
            foreach ($targets as $name => $authinfo) {
                $domain = new Domain($nic);

                $ok = match ($operation) {
                    'request' => $domain->transfer($name, $authinfo, $registrants[$name]),
                    'approve' => $domain->transferApprove($name, $authinfo),
                    'reject'  => $domain->transferReject($name, $authinfo),
                    'cancel'  => $domain->transferCancel($name, $authinfo),
                };

                if ( ! $ok) {
                    $this->itemFailed($name, $domain->getError());
                    continue;
                }

                if ( ! $dryRun) {
                    $this->recordLocally($operation, $name, $registrants[$name], $scope, $authinfo);
                }

                $this->record("{$name} transfer {$operation} accepted",
                    ['domain' => $name, 'operation' => $operation]);
            }
        });

        return $this->outcome(DOMAIN_TRANSFER_FAILED);
    }

    /**
     * Keep `transfers` in step: a request creates the pending row PollProcessor
     * acts on, approve and reject settle someone else's claim and clear it.
     * Cancel keeps the row, as `cancelled` -- the record that we wanted the
     * domain.
     */
    private function recordLocally(string $operation, string $name, string $registrant, Scope $scope, string $authinfo): void {
        if ($operation === 'request') {
            DomainService::recordTransferRequest($name, $registrant === '' ? null : $registrant, $scope->resellerId, [], $authinfo);
            // what the reseller's daily quota counts, as the API writes it
            History::record('domains', 0, 'request', ['domain' => $name, 'kind' => 'transfer'], $scope->userId);
            return;
        }

        if ($operation === 'cancel') {
            R::exec("UPDATE transfers SET status = 'cancelled' WHERE domain = ?", [$name]);
        } elseif ($operation === 'approve' || $operation === 'reject') {
            R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
        }
    }
}
