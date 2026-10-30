<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Contact;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\RegistrySchemas;

/**
 * A contact built without an explicit authinfo still has one. It did not:
 * initValues() blanked every FIELDS entry after the constructor generated one.
 * Not a validation failure, so nothing caught it: a credential shipped blank.
 */
final class ContactAuthinfoTest extends EppTestCase
{
    private function filled(): Contact {
        $contact = new Contact($this->nic);
        $contact->set('handle', 'SELFTEST12345678');
        $contact->set('name', 'Mario Rossi');
        $contact->set('street', 'Via Roma 1');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39100');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.0471000000');
        $contact->set('email', 'mario.rossi@example.it');
        $contact->set('nationalitycode', 'IT');
        $contact->set('entitytype', 1);
        $contact->set('regcode', 'RSSMRA80A01A952L');

        return $contact;
    }

    /**
     * 16 characters by choice, not by rule: it matches the registry password's
     * ceiling and is what Domain has always generated. The schema would take
     * any length.
     */
    public function testTheGeneratedAuthinfoIsTheUsualSixteenCharacters(): void {
        $this->assertSame(16, strlen((new Contact($this->nic))->get('authinfo')));
    }

    public function testTwoContactsDoNotShareAnAuthinfo(): void {
        $this->assertNotSame(
            (new Contact($this->nic))->get('authinfo'),
            (new Contact($this->nic))->get('authinfo')
        );
    }

    /**
     * The defect as it reached the registry.
     */
    public function testTheCreateRequestCarriesANonEmptyPassword(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->filled()->create();

        $this->assertDoesNotMatchRegularExpression(
            '#<contact:pw\s*/>|<contact:pw>\s*</contact:pw>#',
            $this->transport->lastRequest(),
            'the contact was created with an empty authinfo'
        );
        $this->assertMatchesRegularExpression(
            '#<contact:pw>.{6,16}</contact:pw>#',
            $this->transport->lastRequest()
        );
    }

    public function testAnExplicitAuthinfoStillWins(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact = $this->filled();
        $contact->set('authinfo', 'CHOSEN1234567890');
        $contact->create();

        $this->assertStringContainsString(
            '<contact:pw>CHOSEN1234567890</contact:pw>',
            $this->transport->lastRequest()
        );
    }

    // ---------------------------------------------------------------
    // what the schema actually says
    // ---------------------------------------------------------------

    /**
     * The element is mandatory, so leaving it out was never an option --
     * `contact:createType` declares `authInfo` with no minOccurs, which means
     * one. Whatever a contact is created with, something has to be sent.
     */
    public function testTheSchemaRequiresAnAuthInfoElement(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->filled()->create();

        $without = preg_replace(
            '#<contact:authInfo>.*?</contact:authInfo>#s',
            '',
            $this->transport->lastRequest()
        );

        $this->assertNotSame([], RegistrySchemas::validate($without), 'authInfo turned out to be optional');
    }

    /**
     * And an empty one is valid, which is what makes this the kind of defect
     * only a live run finds: `pwAuthInfoType` is unrestricted, the min-6/max-16
     * rule belonging to `epp:pwType` and the `<login>` password.
     */
    public function testAnEmptyAuthInfoIsSchemaValid(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact = $this->filled();
        $contact->set('authinfo', '');
        $contact->create();

        $this->assertStringContainsString('<contact:pw></contact:pw>', $this->transport->lastRequest());
        $this->assertSame([], RegistrySchemas::validate($this->transport->lastRequest()));
    }

    public function testTheGeneratedAuthInfoIsSchemaValidToo(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->filled()->create();

        $this->assertSame([], RegistrySchemas::validate($this->transport->lastRequest()));
    }

    // ---------------------------------------------------------------
    // and it stays out of the way
    // ---------------------------------------------------------------

    /**
     * The generated default is not a change. If it were, update() would send
     * an authinfo rotation nobody asked for every time a contact was fetched
     * and saved.
     */
    public function testTheGeneratedDefaultIsNotTreatedAsAChange(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact = $this->filled();
        $contact->set('name', 'Mario Rossi Updated');
        $contact->update();

        $this->assertStringNotContainsString('<contact:authInfo>', $this->transport->lastRequest());
    }
}
