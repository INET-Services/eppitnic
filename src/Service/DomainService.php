<?php

namespace Eppitnic\Service;

use Eppitnic\Api\Access;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use Eppitnic\Support\Idn;
use Eppitnic\Support\Warnings;
use RedBeanPHP\R;

/**
 * Domain operations taking more than one registry command, needed by the API
 * and the CLI alike -- previously a closure in each, already drifted. All take
 * a session and return data; reporting stays with the caller.
 *
 * @category    Net
 * @package     Eppitnic\Service\DomainService
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DomainService
{
    /**
     * Register a domain, or request its transfer. The caller's input decides:
     * no AuthInfo registers it (a registrant is required), an AuthInfo asks to
     * transfer it, and with a registrant too, to trade it to that registrant.
     * The registry's <check> only guards: it must agree with the input.
     * $registerOnly is the CLI's `domain create`: always a registration, its
     * authinfo the new domain's code, and a registered name is refused.
     *
     * @param Client $nic a logged-in client
     * @param array $params domain, and optionally registrant (required
     *              without authinfo), ns[] and authinfo; admin and tech[] are
     *              accepted and ignored
     * @param Scope $scope who acts: the user for history, and the reseller a
     *              plain transfer belongs to (a registered domain and a trade
     *              belong to their registrant's, see Domain::storeDB())
     * @param bool $persist write the result to the local database: a
     *              registration as a domain, a transfer as a pending row in
     *              `transfers` (a trade's registrant stored locally first).
     *              False is a dry run, whose <check> is not the registry's
     *              and so guards nothing
     * @param bool $registerOnly never transfer: authinfo is the code to set
     *              on the new domain, not the one of a domain to take over
     * @return array{ok: bool, action?: string, domain?: Domain, error?: string, warnings?: string[]}
     *         action is created, transfer-requested or trade-requested
     */
    public static function createOrTransfer(Client $nic, array $params, Scope $scope, bool $persist = true, bool $registerOnly = false): array {
        $name = Idn::domain($params['domain']);
        $authinfo = trim((string) ($params['authinfo'] ?? ''));
        $registrant = trim((string) ($params['registrant'] ?? ''));
        $transfer = ! $registerOnly && $authinfo !== '';

        if ( ! $transfer && $registrant === '') {
            return ['ok' => false, 'error' => "A registrant is required to register '{$name}'"];
        }

        $nameservers = self::checkedNameservers($name, $params['ns'] ?? []);
        if (is_string($nameservers)) {
            return ['ok' => false, 'error' => $nameservers];
        }

        $domain = new Domain($nic);
        $availability = $domain->check($name);
        if ( ! $availability->answered()) {
            // without the answer the input cannot be checked against the registry
            return ['ok' => false, 'error' => $availability->error()];
        }
        if ($persist && $transfer && $availability->available()) {
            return ['ok' => false, 'error' => "'{$name}' is not registered; leave the AuthInfo code empty to register it"];
        }
        if ($persist && ! $transfer && ! $availability->available()) {
            $hint = $registerOnly ? 'use `domain transfer request` to transfer it' : 'give its AuthInfo code to transfer it';
            return ['ok' => false, 'error' => "'{$name}' is already registered; {$hint}"];
        }

        // transfers.registrant is a foreign key onto contacts: refused before
        // the registry is asked, which would otherwise take a request we
        // cannot record
        if ($persist && $transfer && $registrant !== ''
            && (int) R::getCell('SELECT COUNT(*) FROM contacts WHERE handle = ?', [$registrant]) === 0) {
            return ['ok' => false, 'error' => "Contact '{$registrant}' not found"];
        }

        $domain->set('domain', $name);
        if ($registrant !== '') {
            $domain->set('registrant', $registrant);
        }
        foreach ($nameservers as $ns) {
            if ($domain->addNS($ns['name'], $ns['ip']) === false) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
        }
        $domain->set('authinfo', $authinfo !== '' ? $authinfo : $domain->authinfo());
        $warnings = [];

        if ( ! $transfer) {
            // prepared first, so the registry's DNS check finds it answering;
            // never on a dry run, which persists nothing
            $zone = $persist ? PowerDnsZones::provision($name, array_keys((array) $domain->get('ns'))) : null;
            if ($zone !== null && $zone['warning'] !== null) {
                $warnings[] = $zone['warning'];
            }
            if ( ! $domain->create()) {
                if ($zone !== null) {
                    PowerDnsZones::undo($name, $zone, []);
                }
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $action = 'created';
        } else {
            if ( ! $domain->transfer($name, $authinfo, $registrant)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $action = $registrant === '' ? 'transfer-requested' : 'trade-requested';
        }

        if ($persist && $action === 'created') {
            if ( ! $domain->storeDB($scope->userId)) {
                $warnings[] = Warnings::localWrite("domain '{$name}'", $domain->getError());
            }
            // the registrant is linked now
            ContactRefresh::run($nic, [$registrant], $scope->userId);
        } elseif ($persist) {
            // not ours until PollProcessor sees the transfer complete
            try {
                self::recordTransferRequest(
                    $name,
                    $registrant === '' ? null : $registrant,
                    $scope->resellerId,
                    $nameservers,
                    $authinfo
                );
            } catch (\Throwable $e) {
                $warnings[] = Warnings::localWrite("transfer of '{$name}'", $e->getMessage());
            }
        }

        return ['ok' => true, 'action' => $action, 'domain' => $domain, 'warnings' => $warnings];
    }

    /**
     * An immediate deletion supersedes a scheduled one: its open task is
     * deactivated. Returns what the deletion's history row records of it.
     */
    public static function cancelScheduledDeletion(string $name): array {
        $where = "domain = ? AND object = 'registry' AND action = 'delete' AND active = 1";
        $id = R::getCell("SELECT id FROM tasks WHERE {$where} ORDER BY id LIMIT 1", [$name]);
        if (empty($id)) {
            return [];
        }
        R::exec("UPDATE tasks SET active = 0 WHERE {$where}", [$name]);
        return ['scheduled_deletion' => 'cancelled', 'task_id' => (int) $id];
    }

    /**
     * Record a transfer-in request as pending, for PollProcessor to complete.
     * With a registrant it is a trade, belonging to that registrant's
     * reseller; without, a plain transfer, belonging to the requester's.
     * `transfers.domain` is unique, so an earlier row for the name (a
     * cancelled request) is replaced.
     *
     * @param string|null $registrant the registrant the domain changes to, if
     *              any: a stored contact
     * @param int $requesterReseller the requesting user's reseller
     * @param array $ns nameservers to apply: nameserverEntries() output or names
     * @param string|null $authinfo the code the request was made with: the
     *              registry answers a query about the domain only with it
     */
    public static function recordTransferRequest(string $name, ?string $registrant, int $requesterReseller, array $ns = [], ?string $authinfo = null): void {
        $values = [
            ':reseller_id' => $registrant === null ? $requesterReseller : Domain::resellerOf($registrant),
            ':registrant'  => $registrant,
            ':kind'        => $registrant === null ? 'transfer' : 'trade',
            ':authinfo'    => $authinfo,
            ':techc'       => serialize([]),
            // a name alone when it carries no addresses
            ':dns'         => serialize(array_map(
                static fn(array|string $e): array|string => is_array($e) && ($e['ip'] ?? null) === null ? $e['name'] : $e,
                $ns
            )),
            ':domain'      => $name,
        ];
        if (R::getCell('SELECT id FROM transfers WHERE domain = ?', [$name])) {
            R::exec("
                UPDATE transfers SET reseller_id = :reseller_id, registrant = :registrant, kind = :kind,
                    authinfo = :authinfo, techc = :techc, dns = :dns, status = 'pending', time = CURRENT_TIMESTAMP,
                    attempts = 0, attempted_at = NULL
                WHERE domain = :domain
            ", $values);
            return;
        }
        R::exec("
            INSERT INTO transfers (reseller_id, domain, registrant, kind, authinfo, techc, dns)
            VALUES (:reseller_id, :domain, :registrant, :kind, :authinfo, :techc, :dns)
        ", $values);
    }

    /**
     * Copy domains the registry already holds into the local database, along
     * with their registrant contacts.
     *
     * @param Client $nic a logged-in client
     * @param string[] $names domains to import
     * @param Scope $scope who imports: a registrant not stored locally yet
     *              goes to their reseller; a non-admin never touches a domain
     *              or registrant another reseller holds
     * @return array<string, array{domain: string, registrant: string, contact_stored: string, domain_stored: string, error?: string}>
     *         per domain, each step's outcome: 'found'/'not found'/'held by
     *         another reseller', 'stored'/'not stored', or 'skipped' if an
     *         earlier step stopped it
     */
    public static function import(Client $nic, array $names, Scope $scope): array {
        $results = [];

        foreach ($names as $name) {
            // A step never reached says 'skipped' rather than sharing a value
            // with one that ran and failed, so the first non-'skipped' failure
            // is where it stopped
            $result = [
                'domain'         => 'skipped',
                'registrant'     => 'skipped',
                'contact_stored' => 'skipped',
                'domain_stored'  => 'skipped',
            ];

            // IT-NIC does not answer queries for "xn--..." names
            $name = Idn::domain($name);

            // a fresh object per name: fetch() re-initialises, but a failed
            // fetch would otherwise leave the previous domain's data in place
            $domain = new Domain($nic);
            $contact = new Contact($nic);

            // another reseller's domain is theirs to reconcile, not the caller's
            if (Access::domainHeldByAnotherReseller($name, $scope)) {
                $result['domain'] = 'held by another reseller';
                $results[$name] = $result;
                continue;
            }

            if ( ! $domain->fetch($name)) {
                $result['domain'] = 'not found';
                // the registry does not have it, so neither should we
                $domain->deleteDomainDB($name, $scope);
                $results[$name] = $result;
                continue;
            }
            $result['domain'] = 'found';

            if ( ! $contact->fetch($domain->get('registrant'))) {
                $result['registrant'] = 'not found';
                $results[$name] = $result;
                continue;
            }
            // the domain would follow its registrant into another reseller
            $holder = R::getCell('SELECT reseller_id FROM contacts WHERE handle = ?', [$domain->get('registrant')]);
            if ( ! $scope->isAdmin() && $holder !== null && (int) $holder !== $scope->resellerId) {
                $result['registrant'] = 'held by another reseller';
                $results[$name] = $result;
                continue;
            }
            $result['registrant'] = 'found';

            // a registrant already stored locally keeps its reseller, and the
            // domain follows it (Domain::storeDB())
            if ($contact->storeDB($scope->resellerId, $scope->userId)) {
                $result['contact_stored'] = 'stored';
            } else {
                $result['contact_stored'] = 'not stored';
                $result['error'] = $contact->getError();
            }

            if ($domain->storeDB($scope->userId)) {
                $result['domain_stored'] = 'stored';
                // whatever transfer request brought it here has completed
                R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
            } else {
                $result['domain_stored'] = 'not stored';
                $result['error'] = trim(($result['error'] ?? '') . ' ' . $domain->getError());
            }

            $results[$name] = $result;
        }

        return $results;
    }

    /**
     * Move a domain to another reseller with its own copies of the contacts:
     * both ownerships must move together. Not atomic -- the contacts exist
     * before the domain points at them, so a failure leaves them unused.
     *
     * @param Client $nic a logged-in client
     * @param string $name the domain to move
     * @param int $resellerId the reseller to move it to
     * @param int $actorId the acting user, for history
     * @param bool $persist reassign local ownership too
     * @return array{ok: bool, domain?: Domain, error?: string, status?: int}
     */
    public static function changeOwner(Client $nic, string $name, int $resellerId, int $actorId, bool $persist = true): array {
        $newOwner = R::getRow("SELECT id FROM resellers WHERE id = ?", [$resellerId]);
        if (empty($newOwner)) {
            return ['ok' => false, 'status' => 404, 'error' => "Reseller {$resellerId} not found"];
        }

        $domain = new Domain($nic);
        if ( ! $domain->fetch($name)) {
            return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
        }

        // the registrant is always duplicated under the new owner
        $oldRegistrant = new Contact($nic);
        if ( ! $oldRegistrant->fetch($domain->get('registrant'))) {
            return ['ok' => false, 'status' => 400, 'error' => 'unable to fetch current registrant: ' . $oldRegistrant->getError()];
        }
        $newRegistrantHandle = $oldRegistrant->duplicate($nic, $resellerId, $actorId);
        if ($newRegistrantHandle === false) {
            return ['ok' => false, 'status' => 400, 'error' => 'unable to duplicate registrant contact: ' . $oldRegistrant->getError()];
        }

        // the registrant change is its own EPP command, requiring authinfo to
        // change alongside it -- before anything else touches $domain
        $domain->set('registrant', $newRegistrantHandle);
        $domain->set('authinfo', $domain->authinfo());
        if ( ! $domain->updateRegistrant()) {
            return ['ok' => false, 'status' => 400, 'error' => 'registrant change failed: ' . $domain->getError()];
        }

        if ($persist) {
            R::exec("UPDATE domains SET reseller_id = ?, registrant = ? WHERE domain = ?", [$resellerId, $newRegistrantHandle, $name]);
            $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
            History::record('domains', $id, 'update', ['reseller_id' => $resellerId, 'registrant' => $newRegistrantHandle], $actorId);
            ContactRefresh::run($nic, [$oldRegistrant->get('handle'), $newRegistrantHandle], $actorId);
        }

        return ['ok' => true, 'domain' => $domain];
    }

    /**
     * Normalise the nameservers of a domain update or creation: each entry is
     * a name, or an object with glue as `{ipv4?, ipv6?}` or `ip` (one address
     * or a list). Glue left out means "keep as is" (null); any of the glue
     * keys given, even empty, sets exactly those addresses.
     *
     * @return array|string list of {name: string, ip: string[]|null}, or the
     *         error
     */
    public static function nameserverEntries(mixed $nameservers): array|string {
        if ( ! is_array($nameservers)) {
            return 'ns must be a list of nameservers';
        }
        $entries = [];
        foreach ($nameservers as $i => $ns) {
            $name = is_array($ns) ? ($ns['name'] ?? null) : $ns;
            if ( ! is_string($name) || trim($name) === '') {
                return "ns[{$i}] needs a name";
            }
            $ip = null;
            if (is_array($ns) && array_key_exists('ip', $ns) && $ns['ip'] !== null) {
                $ip = is_array($ns['ip']) ? array_values($ns['ip']) : [$ns['ip']];
                foreach ($ip as $address) {
                    if ( ! is_string($address)) {
                        return "ns[{$i}].ip must be a list of addresses";
                    }
                }
            }
            foreach (['ipv4' => FILTER_FLAG_IPV4, 'ipv6' => FILTER_FLAG_IPV6] as $key => $flag) {
                if ( ! is_array($ns) || ! array_key_exists($key, $ns)) {
                    continue;
                }
                $ip ??= [];
                $address = $ns[$key];
                if ($address === null || (is_string($address) && trim($address) === '')) {
                    continue;
                }
                if ( ! is_string($address) || filter_var(trim($address), FILTER_VALIDATE_IP, $flag) === false) {
                    return "ns[{$i}].{$key} must be an " . ($key === 'ipv4' ? 'IPv4' : 'IPv6') . ' address';
                }
                $ip[] = trim($address);
            }
            $entries[] = ['name' => trim($name), 'ip' => $ip];
        }
        return $entries;
    }

    /**
     * Check glue against the registry rules: a nameserver under the domain
     * needs exactly one IPv4 and may add one IPv6; any other has no address.
     *
     * @param array $entries nameserverEntries() output
     * @param array $held the domain's current ns (hostname => details), whose
     *              addresses stand for an entry that gives none
     * @return string|null the error, or null when the glue is fine
     */
    public static function glueError(string $domain, array $entries, array $held = []): ?string {
        $normal = static fn(string $name): string => Idn::ace(rtrim(trim($name), '.'));
        $domain = $normal($domain);
        foreach ($entries as $ns) {
            $name = $normal($ns['name']);
            $addresses = $ns['ip'] ?? array_column($held[$name]['ip'] ?? [], 'address');
            $addresses = array_values(array_filter(array_map('trim', $addresses), 'strlen'));
            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                    return "Address '{$address}' is not a valid IPv4 or IPv6 address.";
                }
            }

            if ( ! str_ends_with($name, '.' . $domain)) {
                if ($ns['ip'] !== null && $addresses !== []) {
                    return "glue addresses are only allowed for nameservers under {$domain}";
                }
                continue;
            }
            $v4 = array_filter($addresses, static fn($a) => filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
            if (count($addresses) > 2) {
                return "nameserver {$name} may have at most two addresses";
            }
            if ($v4 === []) {
                return "nameserver {$name} under {$domain} needs an IPv4 address";
            }
            if (count($v4) > 1) {
                return "nameserver {$name} may have only one IPv4 address";
            }
        }
        return null;
    }

    /**
     * nameserverEntries() followed by glueError() for a domain without
     * nameservers yet.
     *
     * @return array|string the entries, or the error
     */
    public static function checkedNameservers(string $domain, mixed $nameservers): array|string {
        $entries = self::nameserverEntries($nameservers);
        return is_string($entries) ? $entries : (self::glueError($domain, $entries) ?? $entries);
    }

    /**
     * Parse `HOST=IPV4[,IPV6]` entries separated by `;`; an empty address
     * list (`HOST=`) clears the host's glue.
     *
     * @return array|string nameserverEntries() output, or the error
     */
    public static function parseGlue(string $glue): array|string {
        $entries = [];
        foreach (array_filter(array_map('trim', explode(';', $glue)), 'strlen') as $item) {
            if ( ! str_contains($item, '=')) {
                return "glue '{$item}' must be HOST=IPV4[,IPV6]";
            }
            [$name, $addresses] = explode('=', $item, 2);
            $entries[] = ['name' => $name, 'ip' => array_values(array_filter(array_map('trim', explode(',', $addresses)), 'strlen'))];
        }
        return self::nameserverEntries($entries);
    }

    /**
     * A domain's nameservers as the API shows them.
     *
     * @param array $ns the ns shape of Domain (hostname => details)
     * @return array list of {name, ipv4: string|null, ipv6: string|null}
     */
    public static function nameserverList(array $ns): array {
        $list = [];
        foreach ($ns as $name => $details) {
            $row = ['name' => (string) $name, 'ipv4' => null, 'ipv6' => null];
            foreach ($details['ip'] ?? [] as $ip) {
                $row[$ip['type'] === 'v6' ? 'ipv6' : 'ipv4'] ??= $ip['address'];
            }
            $list[] = $row;
        }
        return $list;
    }

    /**
     * The nameservers a transfer-in will apply, as the API shows them:
     * `transfers.dns` holds names, or {name, ip: string[]|null} entries.
     *
     * @return array list of {name, ipv4: string|null, ipv6: string|null}
     */
    public static function requestedNameserverList(array $stored): array {
        $list = [];
        foreach ($stored as $entry) {
            $row = ['name' => (string) (is_array($entry) ? ($entry['name'] ?? '') : $entry), 'ipv4' => null, 'ipv6' => null];
            foreach ((array) (is_array($entry) ? ($entry['ip'] ?? []) : []) as $address) {
                $key = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'ipv6' : 'ipv4';
                $row[$key] ??= (string) $address;
            }
            if ($row['name'] !== '') {
                $list[] = $row;
            }
        }
        return $list;
    }

    /**
     * The DS records as a list of {keytag, algorithm, digesttype, digest}:
     * the shape PATCH accepts, not Domain's digest-keyed storage.
     */
    public static function dnssecList(array $keyed): array {
        $list = [];
        foreach ($keyed as $digest => $ds) {
            $list[] = [
                'keytag'     => (string) ($ds['keytag'] ?? ''),
                'algorithm'  => (string) ($ds['algorithm'] ?? ''),
                'digesttype' => (string) ($ds['digesttype'] ?? ''),
                'digest'     => (string) $digest,
            ];
        }
        return $list;
    }

    /**
     * Validate the DS records of a domain update: each needs keytag,
     * algorithm, digesttype and digest as non-empty strings or numbers.
     *
     * @return array|string the records with string values, or the error
     */
    public static function dnssecRecords(mixed $records): array|string {
        if ( ! is_array($records)) {
            return 'dnssec must be a list of DS records';
        }
        $entries = [];
        foreach ($records as $i => $ds) {
            if ( ! is_array($ds)) {
                return "dnssec[{$i}] must be an object";
            }
            $entry = [];
            foreach (['keytag', 'algorithm', 'digesttype', 'digest'] as $key) {
                $value = $ds[$key] ?? null;
                if ( ! is_scalar($value) || is_bool($value) || (string) $value === '') {
                    return "dnssec[{$i}].{$key} is required and must be a non-empty string or number";
                }
                $entry[$key] = (string) $value;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Make the domain's DS set equal to $records (from dnssecRecords()).
     * Removals come first: the registry holds at most two, so a key rollover
     * has to free the slots before it fills them.
     *
     * @return string|null the error, or null when the set is as requested
     */
    public static function applyDnssec(Domain $domain, array $records): ?string {
        $current = (array) $domain->get('dnssec');
        $target = [];
        foreach ($records as $ds) {
            $target[$ds['digest']] = $ds;
        }

        foreach (array_diff_key($current, $target) as $digest => $unused) {
            if ($domain->remDNSSEC((string) $digest) === false) {
                return $domain->getError();
            }
        }
        foreach ($target as $digest => $ds) {
            $held = $current[$digest] ?? null;
            if ($held !== null
                && (string) $held['keytag'] === $ds['keytag']
                && (string) $held['algorithm'] === $ds['algorithm']
                && (string) $held['digesttype'] === $ds['digesttype']) {
                continue;
            }
            if ($domain->addDNSSEC($ds['keytag'], $ds['algorithm'], $ds['digesttype'], $ds['digest']) === false) {
                return $domain->getError();
            }
        }
        return null;
    }
}
