<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Epp\Contact;
use Eppitnic\Persistence\History;
use Eppitnic\Service\ContactRemoval;
use RedBeanPHP\R;

/**
 * Drop the administrative and technical contacts the registry no longer has.
 * First clears domains.admin and domains.tech locally, then deletes every
 * active contact that no active domain or pending transfer has as registrant.
 * A sysop tool: it covers every reseller and ignores --user.
 */
final class ContactCleanupCommand extends Command
{
    private const HAS_LEGACY = '(admin IS NOT NULL OR tech IS NOT NULL)';

    public function describe(): string {
        return 'clear admin/tech contacts from domains, delete the contacts left unused (all resellers; ignores --user)';
    }

    public function options(): array {
        return [
            'dry-run' => 'list what would be cleared and deleted, and change nothing',
            'yes'     => 'do not ask for confirmation',
        ];
    }

    public function run(): int {
        $this->database();

        $domains = $this->domainsToClear();
        $candidates = $this->candidates();

        if ($this->isDryRun()) {
            $this->record(
                "{$domains} domain(s) would lose their admin and tech contacts",
                ['dry_run' => true, 'domains' => $domains]
            );
            foreach ($candidates as $row) {
                $this->record(
                    "{$row['handle']} {$row['name']} ({$row['reseller']}) would be deleted",
                    $row + ['dry_run' => true]
                );
            }
            return 0;
        }

        if ( ! $this->confirm("Clear admin/tech on {$domains} domain(s) and delete "
            . count($candidates) . ' contact(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        if ($domains > 0) {
            R::exec('UPDATE domains SET admin = NULL, tech = NULL WHERE ' . self::HAS_LEGACY);
        }

        $counts = [ContactRemoval::DELETED => 0, ContactRemoval::GONE => 0, ContactRemoval::LINKED => 0];
        try {
            if ($candidates !== []) {
                $this->withSession(function ($nic) use ($candidates, &$counts) {
                    $contact = new Contact($nic);
                    foreach ($candidates as $row) {
                        $counts = $this->removeOne($contact, $row, $counts);
                    }
                });
            }
        } finally {
            $this->recordHistory($domains, $counts);
        }

        $this->record(
            "domains cleared {$domains}; contacts deleted {$counts['deleted']}, already gone {$counts['gone']},"
            . " still linked {$counts['linked']}, failed {$this->failures}",
            [
                'summary'         => true,
                'domains_cleared' => $domains,
                'deleted'         => $counts['deleted'],
                'gone'            => $counts['gone'],
                'linked'          => $counts['linked'],
                'failed'          => $this->failures,
            ]
        );

        return $this->outcome(CONTACT_DELETE_FAILED);
    }

    /**
     * @param array $row the candidate's handle, name and reseller
     * @param array<string, int> $counts per-outcome tallies so far
     * @return array<string, int> $counts, updated
     */
    private function removeOne(Contact $contact, array $row, array $counts): array {
        $handle = $row['handle'];
        $outcome = ContactRemoval::delete($contact, $handle);

        if ($outcome === ContactRemoval::FAILED) {
            $this->itemFailed($handle, $contact->getError());
            return $counts;
        }

        if ($outcome === ContactRemoval::GONE) {
            ContactRemoval::deactivate($handle);
        }
        $text = [
            ContactRemoval::DELETED => 'deleted',
            ContactRemoval::GONE    => 'already gone at the registry',
            ContactRemoval::LINKED  => 'still linked at the registry, kept',
        ][$outcome];

        $this->record("{$handle} {$text}", $row + ['outcome' => $outcome]);
        $counts[$outcome]++;
        return $counts;
    }

    private function domainsToClear(): int {
        return (int) R::getCell('SELECT COUNT(1) FROM domains WHERE ' . self::HAS_LEGACY);
    }

    /** One system row (no user, object_id 0) for the whole run. */
    private function recordHistory(int $domains, array $counts): void {
        if ($domains + array_sum($counts) + $this->failures === 0) {
            return;
        }
        History::record('domains', 0, 'update', [
            'cleanup'         => true,
            'by'              => 'executed from CLI by a sysop',
            'domains_cleared' => $domains,
            'contacts_deleted' => $counts[ContactRemoval::DELETED],
            'already_gone'    => $counts[ContactRemoval::GONE],
            'still_linked'    => $counts[ContactRemoval::LINKED],
            'failed'          => $this->failures,
        ], null);
    }

    /**
     * Active contacts no active domain or pending transfer names as registrant.
     *
     * @return array<int, array{handle: string, name: string, reseller: string}>
     */
    private function candidates(): array {
        return R::getAll("
            SELECT c.handle, c.name, r.name AS reseller
            FROM contacts c LEFT JOIN resellers r ON r.id = c.reseller_id
            WHERE c.active = 1
              AND NOT EXISTS (SELECT 1 FROM domains d WHERE d.registrant = c.handle AND d.active = 1)
              AND NOT EXISTS (SELECT 1 FROM transfers t WHERE t.registrant = c.handle AND t.status = 'pending')
            ORDER BY c.handle
        ");
    }
}
