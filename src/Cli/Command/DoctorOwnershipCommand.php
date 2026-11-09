<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use RedBeanPHP\R;

/**
 * Report rows where `domains`/`transfers`.`reseller_id` and the registrant
 * `contacts`.`reseller_id` disagree. A domain always belongs to its
 * registrant's reseller, so a disagreement is a domain listed under one
 * reseller while its registrant belongs to another.
 */
final class DoctorOwnershipCommand extends Command
{
    public function describe(): string {
        return 'report domains whose reseller and registrant\'s reseller disagree';
    }

    public function options(): array {
        return [
            'quiet' => 'report the rows only, without the explanation of how to resolve them',
        ];
    }

    public function run(): int {
        $this->database();

        $domains = $this->mismatches('domains', 'd');
        $transfers = $this->mismatches('transfers', 't');

        foreach (['domain' => $domains, 'pending transfer-in' => $transfers] as $label => $rows) {
            foreach ($rows as $row) {
                $this->record(
                    sprintf('%-18s %-32s owned by %s (%d), registrant %s owned by %s (%d)',
                        $label, $row['object'],
                        $row['owner_name'], $row['owner_id'],
                        $row['registrant'],
                        $row['registrant_owner_name'], $row['registrant_owner_id']),
                    ['kind' => $label] + $row
                );
            }
        }

        if ($domains === [] && $transfers === []) {
            $this->line('domain/registrant ownership is coherent');
            return 0;
        }

        if ( ! $this->hasOption('quiet')) {
            $this->line('');
            $this->line('Per row, decide which reseller should own it, then either:');
            $this->line('  (a) give that reseller its own copy of the registrant contact --');
            $this->line('      `domain set-owner --new-reseller=ID` does exactly this; or');
            $this->line('  (b) hand the domain to the registrant\'s reseller --');
            $this->line('      UPDATE domains SET reseller_id = <registrant_owner> WHERE domain = \'<domain>\';');
            $this->line('');
            $this->line('(b) is one statement but moves the domain out of its current reseller\'s');
            $this->line('listings. A pending transfer-in has not gone wrong yet: it will create a');
            $this->line('mismatched row when it completes, so fix its registrant before then.');
        }

        return DATA_INCONSISTENT;
    }

    /**
     * @param string $table domains or transfers
     * @param string $alias its alias in the query
     * @return array<int, array<string, mixed>>
     */
    private function mismatches(string $table, string $alias): array {
        return R::getAll("
            SELECT
              {$alias}.domain      AS object,
              {$alias}.reseller_id AS owner_id,
              owner.name           AS owner_name,
              {$alias}.registrant  AS registrant,
              c.reseller_id        AS registrant_owner_id,
              reg.name             AS registrant_owner_name
            FROM {$table} {$alias}
            -- a plain transfer has no registrant: nothing to compare
            JOIN contacts c      ON c.handle = {$alias}.registrant
            JOIN resellers owner ON owner.id = {$alias}.reseller_id
            JOIN resellers reg   ON reg.id   = c.reseller_id
            WHERE {$alias}.reseller_id <> c.reseller_id
            ORDER BY {$alias}.domain
        ");
    }
}
