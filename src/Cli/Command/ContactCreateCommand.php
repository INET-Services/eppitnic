<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Contact;

/**
 * Create a contact at the registry.
 */
final class ContactCreateCommand extends Command
{
    /** option name => the Contact property it sets */
    private const FIELDS = [
        'name'            => 'name',
        'org'             => 'org',
        'street'          => 'street',
        'street2'         => 'street2',
        'street3'         => 'street3',
        'city'            => 'city',
        'province'        => 'province',
        'postalcode'      => 'postalcode',
        'countrycode'     => 'countrycode',
        'voice'           => 'voice',
        'fax'             => 'fax',
        'email'           => 'email',
        'authinfo'        => 'authinfo',
        'nationalitycode' => 'nationalitycode',
        'entitytype'      => 'entitytype',
        'regcode'         => 'regcode',
        'schoolcode'      => 'schoolcode',
    ];

    /** what the registry will not accept a contact without */
    private const REQUIRED = ['name', 'street', 'city', 'province', 'postalcode', 'countrycode', 'voice', 'email', 'nationalitycode', 'entitytype'];

    /** entitytype value => what it means, for hints()'s multi-line breakdown */
    private const ENTITY_TYPES = [
        1 => 'natural person',
        2 => 'company',
        3 => 'individual enterprise or freelancer',
        4 => 'non-profit',
        5 => 'public entity',
        6 => 'other',
        7 => 'foreign entity other than a natural person',
    ];

    /**
     * Format hints for fields whose name is not enough, from nic.it's technical
     * guidelines rather than a preference here. A method, not a const:
     * entitytype's lines are aligned with str_repeat().
     */
    private static function hints(): array {
        $pad = str_repeat(' ', 25);
        $entityLines = [];
        foreach (self::ENTITY_TYPES as $value => $meaning) {
            $entityLines[] = "{$pad}{$value} {$meaning}";
        }

        return [
            'entitytype' => "every contact is a registrant:\n"
                . implode("\n", $entityLines),
            'name' => 'must equal --org; either one alone is copied into the other',
            'org' => 'must equal --name; either one alone is copied into the other',
            'fax' => 'ignored: the registry no longer supports fax',
            'province' => 'two-letter Italian province code (e.g. MI, RM); required by the registry when --countrycode=IT',
            'voice' => 'ISO international phone format, e.g. +39.0503139811; optional extension as x1234 ' .
                '(max 10 digits)',
            'countrycode' => 'ISO 3166-1 country code (e.g. IT, FR, NL)',
            'nationalitycode' => "ISO 3166-1 country code of the registrant's citizenship (e.g. IT, FR, NL); " .
                'for a non-natural-person registrant (--entitytype != 1) this matches --countrycode',
        ];
    }

    public function describe(): string {
        return 'create a contact at the registry';
    }

    public function arguments(): string {
        return '[<handle>]';
    }

    public function options(): array {
        $options = [
            'publish' => 'consent to publishing this contact in the public whois',
        ];
        $hints = self::hints();
        foreach (array_keys(self::FIELDS) as $field) {
            $label = $hints[$field] ?? $field;
            $options[$field . '='] = in_array($field, self::REQUIRED, true) ? "{$label} (required)" : $label;
        }
        $options['store'] = 'also write the contact to the local database';
        $options['reseller='] = "with --store, the reseller to own it (default: --user's)";

        return $options + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        $missing = array_values(array_filter(self::REQUIRED, fn($f) => (string) $this->option($f, '') === ''));
        if ($missing !== []) {
            throw new UsageError('missing required field(s): --' . implode(', --', $missing));
        }

        $entityType = (int) $this->option('entitytype', 0);
        if ($entityType < 1 || $entityType > 7) {
            throw new UsageError('--entitytype must be 1 to 7');
        }
        if (Contact::equalizeNameOrg((string) $this->option('name', ''), (string) $this->option('org', '')) === null) {
            throw new UsageError(Contact::NAME_ORG_RULE);
        }

        $names = $this->names();
        $handle = $names[0] ?? null;

        if ( ! $this->confirm('Create a contact at the registry?')) {
            $this->line('nothing done');
            return 0;
        }

        $store = $this->hasOption('store');
        $userId = $this->userId();
        $resellerId = $this->hasOption('reseller') ? (int) $this->option('reseller') : $this->scope()->resellerId;
        $dryRun = $this->isDryRun();
        $created = null;

        $this->withSession(function ($nic) use ($handle, $store, $userId, $resellerId, $dryRun, &$created) {
            $contact = new Contact($nic);

            // a handle has to be unique at the registry, so one is generated
            // and checked unless the caller insists on a particular value
            $contact->set('handle', $handle ?? $contact->generateHandle());

            foreach (self::FIELDS as $option => $property) {
                if ($option !== 'fax' && $this->hasOption($option)) {
                    $contact->set($property, (string) $this->option($option));
                }
            }
            $contact->set('consentforpublishing', $this->hasOption('publish'));

            if ( ! $contact->get('authinfo')) {
                $contact->set('authinfo', $contact->authinfo());
            }

            if ( ! $contact->create()) {
                $this->warn('create failed: ' . $contact->getError());
                return;
            }

            $created = $contact->get('handle');

            if ($store && ! $dryRun) {
                if ( ! $contact->storeDB($resellerId, $userId)) {
                    $this->failures++;
                    $this->warn("{$created}: created at the registry, not stored (" . $contact->getError() . ')');
                }
            }

            $this->record(
                'contact ' . $created . ' created',
                ['handle' => $created, 'authinfo' => $contact->get('authinfo')]
            );
        });

        return (($created === null && ! $dryRun) || $this->failures > 0) ? CONTACT_CREATE_FAILED : 0;
    }
}
