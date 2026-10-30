<?php

namespace Eppitnic\Selftest;

use Eppitnic\Epp\Contact;

/**
 * The contacts a self-test run creates -- fabricated, but plausible enough for
 * nic.it's extcon validation: every contact is a registrant, with nationality,
 * entity type and reg code, and its org equal to its name.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\ContactFactory
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ContactFactory
{
    /** an Italian company: the entity type that needs a registration code */
    public const ENTITY_COMPANY = 2;

    /**
     * The entity types that may stay out of the public whois: 1 (natural
     * person) and 3 (freelancer). Anyone else is published by law, and the
     * registry refuses consentForPublishing = 0 with 2308 / 8028.
     */
    public const MAY_WITHHOLD_CONSENT = [1, 3];

    /**
     * How many digits a partita IVA has, the last of which is a check digit.
     */
    private const VAT_LENGTH = 11;

    /**
     * Fill in a contact, ready to be created.
     *
     * @param Contact $contact a fresh contact
     * @param string $handle the handle this run gave it
     */
    public static function fill(Contact $contact, string $handle): void {
        $entityType = self::ENTITY_COMPANY;

        $contact->set('handle', $handle);

        $contact->set('name', 'Selftest ' . $handle);
        $contact->set('org', 'Selftest ' . $handle);
        $contact->set('street', 'Via Roma 1');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39100');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.0471000000');
        // example.it is reserved for documentation, so nothing a self-test
        // writes can reach a real mailbox even if the registry mails it
        $contact->set('email', strtolower($handle) . '@example.it');

        $contact->set('nationalitycode', 'IT');
        $contact->set('entitytype', $entityType);
        $contact->set('regcode', self::registrationCode($handle));

        // A test contact has no business in the public whois -- but only the
        // entity types that are allowed to withhold consent may say so, and
        // the registrant here is a company.
        if (self::mayWithholdConsent($entityType)) {
            $contact->unsetConsent();
        } else {
            $contact->setConsent();
        }
    }

    /**
     * A partita IVA for entity type 2, computed rather than picked: nic.it
     * verifies the checksum, which `01234567890` fails. Derived from the
     * handle, so the run's two registrants differ and a re-run asks the same
     * twice.
     *
     * @param string $handle the contact this code is for
     * @return string eleven digits
     */
    public static function registrationCode(string $handle): string {
        // no range of VAT numbers is reserved for testing, so this may well
        // collide with a real company's. Harmless on the test registry, where
        // the rest of the contact is plainly invented anyway.
        $body = substr(str_pad((string) crc32($handle), self::VAT_LENGTH - 1, '0', STR_PAD_LEFT), -(self::VAT_LENGTH - 1));

        return $body . self::vatCheckDigit($body);
    }

    /**
     * The Luhn-style check digit closing a partita IVA: odd positions added as
     * they stand, even ones doubled less nine if that exceeds nine, and the
     * digit is whatever brings the total to a multiple of ten.
     *
     * @param string $body the first ten digits
     */
    private static function vatCheckDigit(string $body): int {
        $sum = 0;

        for ($i = 0; $i < self::VAT_LENGTH - 1; $i++) {
            $digit = (int) $body[$i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * Whether this entity type is allowed to keep its details unpublished.
     */
    public static function mayWithholdConsent(int $entityType): bool {
        return in_array($entityType, self::MAY_WITHHOLD_CONSENT, true);
    }

    /**
     * What a self-test changes on an update and expects to read back -- one
     * address and one plain contact field, since moving only one would not
     * notice a builder that dropped the other. name and org are fixed at
     * creation and never part of it.
     *
     * @return array<string, string> field => new value
     */
    public static function changes(string $handle): array {
        return [
            'city'  => 'Merano',
            'voice' => '+39.0473000000',
        ];
    }
}
