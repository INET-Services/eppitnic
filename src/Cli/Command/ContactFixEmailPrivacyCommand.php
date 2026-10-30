<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;

/**
 * Withdraw whois publication consent from every registrant, and give those
 * with no usable e-mail address one derived from a domain they hold.
 */
final class ContactFixEmailPrivacyCommand extends Command
{
    /** placeholders that older records use in place of a real address */
    private const NOT_AN_ADDRESS = ['', 'n.a.', 'na', '-'];

    public function describe(): string {
        return 'unpublish registrants, and repair placeholder e-mail addresses';
    }

    public function options(): array {
        return [
            'all-resellers' => "every reseller's registrants, not just --user's reseller's",
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        if ($this->isDryRun()) {
            throw new UsageError(
                '--dry-run does not apply here: which contacts need changing, and what'
                . ' address each would get, are read from the registry and the database'
            );
        }

        $this->database();
        $scope = $this->scope($this->hasOption('all-resellers'));

        // registrant => the domains it holds, so a contact with no address can
        // be given one at a domain that is actually its own
        $client = new \Eppitnic\Epp\Client();
        $domains = (new Domain($client))->listDomains($scope);

        $registrants = [];
        foreach ($domains as $row) {
            $registrants[$row['registrant']][] = $row['domain'];
        }

        if ($registrants === []) {
            $this->line('no registrants found');
            return 0;
        }

        if ( ! $this->confirm('Unpublish ' . count($registrants) . ' registrant contact(s) at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $this->withSession(function ($nic) use ($registrants, $scope) {
            foreach ($registrants as $handle => $theirDomains) {
                $contact = new Contact($nic);

                if ( ! $contact->fetch($handle)) {
                    $this->itemFailed($handle, $contact->getError());
                    continue;
                }

                $contact->set('consentforpublishing', false);

                $email = strtolower(trim((string) $contact->get('email')));
                if (in_array($email, self::NOT_AN_ADDRESS, true)) {
                    // a '(transfer-in...)' suffix means the domain is not ours yet
                    $usable = array_values(array_filter($theirDomains, fn($d) => ! str_contains($d, ' ')));
                    if ($usable === []) {
                        $this->warn("{$handle}: no usable domain to derive an address from");
                        continue;
                    }
                    $contact->set('email', 'info@' . $usable[0]);
                }

                if ( ! $contact->hasChanges()) {
                    continue;
                }

                if ( ! $contact->update()) {
                    $this->itemFailed($handle, $contact->getError());
                    continue;
                }
                if ( ! $contact->updateDB($handle, Scope::operator($scope->userId))) {
                    $this->failures++;
                    $this->warn("{$handle}: updated at the registry, not stored (" . $contact->getError() . ')');
                }

                $this->record(
                    sprintf('%-24s unpublished%s', $handle,
                        $contact->get('email') !== $email ? ', e-mail set to ' . $contact->get('email') : ''),
                    ['handle' => $handle, 'email' => $contact->get('email')]
                );
            }
        });

        return $this->outcome(CONTACT_UPDATE_FAILED);
    }
}
