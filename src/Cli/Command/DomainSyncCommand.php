<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Support\Idn;
use RedBeanPHP\R;

/**
 * Periodic reconciliation of domains already known locally against the
 * registry, plus a refresh of every contact they name as linked.
 *
 * Runs in bounded phases rather than over the whole table at once: each
 * invocation advances a persisted cursor (the `domain_sync` setting) through
 * `domains.id`, wrapping back to the start once exhausted, so a
 * continuously-scheduled job eventually revisits every active domain without
 * ever making an unbounded number of registry calls in one tick. On by
 * default -- see `config domain-sync`.
 */
final class DomainSyncCommand extends Command
{
    public function describe(): string {
        return 'reconcile known domains and their linked contacts against the registry, in phases';
    }

    public function options(): array {
        return [
            'batch-size='  => 'how many active domains this run processes, overriding domain_sync.batch_size for this run only (default: that setting)',
            'report-only'  => 'check and fetch against the real registry as normal, but write nothing locally and do not advance the cursor',
        ];
    }

    public function run(): int {
        $this->database();

        $cfg = CronjobSettings::get('domain_sync');

        if ( ! $cfg['enabled']) {
            $this->line('domain sync is off (see: eppitnic config domain-sync on)');
            return 0;
        }

        $batchSize = (int) $cfg['batch_size'];
        if ($this->hasOption('batch-size')) {
            $batchSize = (int) $this->option('batch-size');
            if ($batchSize < 1 || $batchSize > 500) {
                throw new UsageError('--batch-size must be between 1 and 500');
            }
        }

        $candidates = $this->nextBatch((int) $cfg['cursor_id'], $batchSize);
        if ($candidates === []) {
            $this->line('no active domains to reconcile');
            return 0;
        }

        $reportOnly = $this->hasOption('report-only');
        $userId = $this->userId();
        $updated = 0;
        $gone = 0;
        $stored = 0;
        // handle => the reseller that should own it if it is new --
        // collected across the whole phase so a tech contact shared by many
        // of this tick's domains is only fetched and stored once
        $handles = [];

        $this->withSession(function ($nic) use ($candidates, $userId, $reportOnly, &$updated, &$gone, &$stored, &$handles) {
            foreach (array_chunk($candidates, 5) as $chunk) {
                // the registry caps a single <check> at five names; Domain::check()
                // silently truncates past that, so chunking is this loop's job
                $names = array_column($chunk, 'domain');
                $checker = new Domain($nic);
                $answer = $checker->check($names);

                if ( ! $answer->answered()) {
                    // no answer is not the same as "still registered" -- see
                    // itemFailed()'s docblock; unlike a full-run abort, the
                    // rest of the phase still deserves a chance
                    foreach ($names as $name) {
                        $this->itemFailed($name, 'availability check failed: ' . $answer->error());
                    }
                    continue;
                }
                // the registry may answer in another case or spelling than stored
                $results = [];
                foreach ($answer->all() as $answered => $result) {
                    $results[Idn::ace($answered)] = $result;
                }

                foreach ($chunk as $row) {
                    $name = $row['domain'];

                    $result = $results[Idn::ace($name)] ?? null;
                    if ($result === null) {
                        // unanswered is not "gone"
                        $this->itemFailed($name, 'missing from the availability answer');
                        continue;
                    }
                    if ($result['available']) {
                        $gone++;
                        $this->record("{$name}: no longer at the registry", [
                            'domain' => $name, 'result' => 'unregistered',
                        ]);
                        continue;
                    }

                    // a fresh object per domain: fetch() re-initialises, but
                    // reusing one across names has bitten this codebase before
                    $after = new Domain($nic);
                    if ( ! $after->fetch($name)) {
                        $this->itemFailed($name, $after->getError());
                        continue;
                    }

                    $before = new Domain($nic);
                    $before->loadDB($name, Scope::operator($userId));

                    $changes = $this->diff($before, $after, (string) $row['ex_date']);
                    if ($changes !== [] && ! $reportOnly) {
                        if ($after->updateDB($name, Scope::operator($userId), $changes)) {
                            $updated++;
                            $this->record("{$name}: reconciled (" . implode(', ', $changes) . ')', [
                                'domain' => $name, 'result' => 'updated', 'changes' => $changes,
                            ]);
                        } else {
                            $this->itemFailed($name, $after->getError());
                        }
                    } elseif ($changes !== []) {
                        $updated++;
                        $this->record("{$name}: would reconcile (" . implode(', ', $changes) . ')', [
                            'domain' => $name, 'result' => 'would_update', 'changes' => $changes,
                        ]);
                    }

                    foreach ($this->linkedHandles($after) as $handle) {
                        $handles[$handle] = (int) $row['reseller_id'];
                    }
                }
            }

            foreach ($handles as $handle => $resellerId) {
                $contact = new Contact($nic);
                if ( ! $contact->fetch($handle)) {
                    $this->itemFailed($handle, $contact->getError());
                    continue;
                }
                if ($reportOnly) {
                    $stored++;
                    $this->record("{$handle}: would refresh", ['handle' => $handle, 'result' => 'would_store']);
                    continue;
                }
                if ($contact->storeDB($resellerId, $userId)) {
                    $stored++;
                    $this->record("{$handle}: contact refreshed", ['handle' => $handle, 'result' => 'stored']);
                } else {
                    $this->itemFailed($handle, $contact->getError());
                }
            }
        });

        if ( ! $reportOnly) {
            $cfg['cursor_id'] = (int) end($candidates)['id'];
            Config::set('domain_sync', $cfg);
        }

        $this->line(sprintf(
            '%d domain(s) checked, %d reconciled, %d gone, %d contact(s) refreshed, %d failure(s)',
            count($candidates), $updated, $gone, $stored, $this->failures
        ));

        if ($this->failures > 0) {
            return DOMAIN_FETCH_FAILED;
        }
        if ($updated > 0 || $gone > 0) {
            return DATA_INCONSISTENT;
        }
        return 0;
    }

    /**
     * The next batch of active domains after the cursor, wrapping around to
     * the start once the table's tail is exhausted -- the two WHERE clauses
     * partition the id-space, so the merge can never produce a duplicate row.
     *
     * @return array<int, array{id: int, domain: string, reseller_id: int, ex_date: string}>
     */
    private function nextBatch(int $cursor, int $batchSize): array {
        $rows = R::getAll(
            'SELECT id, domain, reseller_id, ex_date FROM domains WHERE active = 1 AND id > ? ORDER BY id LIMIT ?',
            [$cursor, $batchSize]
        );
        if (count($rows) < $batchSize) {
            $rows = array_merge($rows, R::getAll(
                'SELECT id, domain, reseller_id, ex_date FROM domains WHERE active = 1 AND id <= ? ORDER BY id LIMIT ?',
                [$cursor, $batchSize - count($rows)]
            ));
        }
        return $rows;
    }

    /**
     * What actually needs writing back. `$localExDate` is read straight from
     * the row rather than through `$before->get('exDate')`: `loadDB()` never
     * populates `crDate`/`exDate` at all -- `storageHydrate()` matches DB
     * columns to properties by exact name, and those two are `cr_date`/
     * `ex_date` in the table against `$crDate`/`$exDate` on the object, so
     * they silently stay at `initValues()`'s today/+1-year placeholders. A
     * real fix belongs in Domain::loadDB(), not here.
     *
     * @return string[] Domain::FIELDS keys changedFields() should see, plus
     *         a harmless forcing entry when only status/expiry moved
     */
    private function diff(Domain $before, Domain $after, string $localExDate): array {
        $changed = [];
        foreach (['registrant', 'admin', 'authinfo'] as $field) {
            if ((string) $before->get($field) !== (string) $after->get($field)) {
                $changed[] = $field;
            }
        }
        foreach (['ns', 'tech'] as $field) {
            if ($this->normalizeKeys($before->get($field)) !== $this->normalizeKeys($after->get($field))) {
                $changed[] = $field;
            }
        }
        if ($this->normalizeDnssec($before->get('dnssec')) !== $this->normalizeDnssec($after->get('dnssec'))) {
            $changed[] = 'dnssec';
        }

        // status and the expiry date carry no FIELDS slot of their own --
        // updateDB() writes both unconditionally once $changes is non-empty,
        // so a status- or renewal-only drift needs a harmless forcing entry
        // to get past its "$changes === [] -> did not change" guard.
        // 'authinfo' is picked deliberately when nothing else already
        // qualifies: re-writing the same authinfo value is a no-op, and
        // unlike 'ns' it never enqueues the DNS-sync reminder.
        if (
            $changed === []
            && (
                $this->normalizeStatus($before->get('status')) !== $this->normalizeStatus($after->get('status'))
                || $localExDate !== (string) $after->get('exDate')
            )
        ) {
            $changed[] = 'authinfo';
        }

        return $changed;
    }

    /** @return string[] */
    private function normalizeKeys(mixed $value): array {
        $keys = array_keys((array) $value);
        sort($keys);
        return $keys;
    }

    /** @return string[] */
    private function normalizeStatus(mixed $value): array {
        $status = (array) $value;
        sort($status);
        return $status;
    }

    private function normalizeDnssec(mixed $value): array {
        $dnssec = (array) $value;
        ksort($dnssec);
        return $dnssec;
    }

    /**
     * The registrant, admin and every tech contact a fetched domain names --
     * a domain naming a handle as linked is itself sufficient reason to
     * fetch and store it (contact info, never contact check).
     *
     * @return string[]
     */
    private function linkedHandles(Domain $after): array {
        $handles = [];
        if ((string) $after->get('registrant') !== '') {
            $handles[] = (string) $after->get('registrant');
        }
        if ((string) $after->get('admin') !== '') {
            $handles[] = (string) $after->get('admin');
        }
        foreach (array_keys((array) $after->get('tech')) as $tech) {
            $handles[] = $tech;
        }
        return $handles;
    }
}
