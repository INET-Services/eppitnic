<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Contact;
use Eppitnic\Service\ContactRemoval;
use RedBeanPHP\R;

/**
 * Delete contacts at the registry, and deactivate them locally.
 *
 * With --prefix, deletes every locally active contact whose handle starts with
 * it -- the bulk cleanup for the 'DUP' handles Contact::duplicate() leaves
 * behind.
 */
final class ContactDeleteCommand extends Command
{
    public function describe(): string {
        return 'delete contacts at the registry';
    }

    public function arguments(): string {
        return '[<handle>...]';
    }

    public function options(): array {
        return self::fileOption('handles') + [
            'prefix=' => 'delete every locally active contact whose handle starts with this',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $handles = $this->names();

        if ($prefix = $this->option('prefix')) {
            $this->database();
            $handles = array_merge($handles, R::getCol(
                "SELECT handle FROM contacts WHERE active = 1 AND handle LIKE :prefix ORDER BY handle",
                [':prefix' => $prefix . '%']
            ));
            $handles = array_values(array_unique($handles));

            if ($handles === []) {
                $this->line("no active contacts with the prefix '{$prefix}'");
                return 0;
            }
        }

        if ($handles === []) {
            throw new UsageError('give at least one handle, --file=PATH or --prefix=PREFIX');
        }

        if ( ! $this->confirm('Delete ' . count($handles) . ' contact(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $dryRun = $this->isDryRun();

        $this->withSession(function ($nic) use ($handles, $dryRun) {
            $contact = new Contact($nic);

            foreach ($handles as $handle) {
                $outcome = ContactRemoval::delete($contact, $handle, ! $dryRun);
                if ($outcome !== ContactRemoval::DELETED) {
                    $this->itemFailed($handle, $contact->getError());
                    continue;
                }

                $this->record("{$handle} deleted", ['handle' => $handle, 'deleted' => true]);
            }
        });

        return $this->outcome(CONTACT_DELETE_FAILED);
    }
}
