<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Epp\XmlBuilder;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Technical Guidelines v4.0: no admin/tech contacts, no fax, every contact a
 * registrant, name equal to org and fixed after creation. None of these may
 * reach the wire, whatever the caller asks for.
 */
final class RegistryV4Test extends EppTestCase
{
    private function newContact(): Contact {
        $contact = new Contact($this->nic);
        $contact->set('handle', 'ABCD1234EFGH5678');
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

    /** a contact as fetch() leaves it, nothing pending */
    private function fetchedContact(): Contact {
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);
        $contact = new Contact($this->nic);
        $this->assertTrue($contact->fetch('ABCD1234EFGH5678'));
        $this->transport->requests = [];

        return $contact;
    }

    private function fetchedDomain(): Domain {
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $domain = new Domain($this->nic);
        $this->assertTrue($domain->fetch('example-one.it'));
        $this->transport->requests = [];

        return $domain;
    }

    // ---------------------------------------------------------------
    // contact create
    // ---------------------------------------------------------------

    public function testContactCreateNeverCarriesFaxButAlwaysTheRegistrantBlock(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact = $this->newContact();
        $contact->set('fax', '+39.0471000001');

        $this->assertTrue($contact->create());

        $sent = $this->transport->lastRequest();
        $this->assertStringNotContainsString('contact:fax', $sent);
        $this->assertStringContainsString('<extcon:registrant>', $sent);
        $this->assertStringContainsString('<extcon:entityType>1</extcon:entityType>', $sent);
    }

    public function testBuilderEmitsTheRegistrantBlockForEveryCreate(): void {
        $xml = XmlBuilder::contactCreate('TEST-1', [
            'id' => 'X', 'name' => 'N', 'org' => 'N', 'street' => ['S'], 'city' => 'C', 'sp' => 'BZ',
            'pc' => '1', 'cc' => 'IT', 'voice' => '+39.1', 'fax' => '+39.2', 'email' => 'a@example.it',
            'authinfo' => 'pw', 'consentForPublishing' => 1, 'nationalityCode' => 'IT',
            'entityType' => 2, 'regCode' => '01234567890', 'schoolCode' => '',
        ]);

        $this->assertStringContainsString('<extcon:registrant>', $xml);
        $this->assertStringNotContainsString('contact:fax', $xml);
    }

    public function testEntityTypeZeroIsRefusedLocally(): void {
        $contact = $this->newContact();
        $contact->set('entitytype', 0);

        $this->assertFalse($contact->create());
        $this->assertStringContainsString('entitytype', $contact->getError());
        $this->assertSame([], $this->transport->requests, 'nothing was sent');
    }

    public function testAnOutOfRangeEntityTypeIsRefusedLocally(): void {
        $contact = $this->newContact();
        $contact->set('entitytype', 9);

        $this->assertFalse($contact->create());
        $this->assertSame([], $this->transport->requests);
    }

    public function testOrgIsFilledInFromName(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->assertTrue($this->newContact()->create());

        $this->assertStringContainsString('<contact:org>Mario Rossi</contact:org>', $this->transport->lastRequest());
    }

    public function testNameIsFilledInFromOrg(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact = $this->newContact();
        $contact->set('name', '');
        $contact->set('org', 'Rossi S.r.l.');

        $this->assertTrue($contact->create());

        $sent = $this->transport->lastRequest();
        $this->assertStringContainsString('<contact:name>Rossi S.r.l.</contact:name>', $sent);
        $this->assertStringContainsString('<contact:org>Rossi S.r.l.</contact:org>', $sent);
    }

    public function testDifferingNameAndOrgAreRefusedLocally(): void {
        $contact = $this->newContact();
        $contact->set('org', 'Rossi S.r.l.');

        $this->assertFalse($contact->create());
        $this->assertSame('name and org must be the same', $contact->getError());
        $this->assertSame([], $this->transport->requests);
    }

    // ---------------------------------------------------------------
    // contact update
    // ---------------------------------------------------------------

    public function testContactUpdateNeverCarriesNameOrgOrFax(): void {
        $contact = $this->fetchedContact();
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact->set('city', 'Merano');
        $contact->set('email', 'new@example.it');
        $contact->set('fax', '+39.0471000009');

        $this->assertTrue($contact->update());

        $sent = $this->transport->lastRequest();
        $this->assertStringContainsString('<contact:city>Merano</contact:city>', $sent);
        $this->assertStringContainsString('<contact:email>new@example.it</contact:email>', $sent);
        $this->assertStringNotContainsString('contact:fax', $sent);
        $this->assertStringNotContainsString('contact:name', $sent);
        $this->assertStringNotContainsString('contact:org', $sent);
    }

    public function testChangingTheNameIsRefusedAndNothingIsSent(): void {
        $contact = $this->fetchedContact();
        $contact->set('name', 'Someone Else');

        $this->assertFalse($contact->update());
        $this->assertSame('name and org cannot be changed once the contact exists', $contact->getError());
        $this->assertSame([], $this->transport->requests);
    }

    public function testChangingTheOrgIsRefusedAndNothingIsSent(): void {
        $contact = $this->fetchedContact();
        $contact->set('org', 'Someone Else');

        $this->assertFalse($contact->update());
        $this->assertSame([], $this->transport->requests);
    }

    public function testSettingTheStoredNameAgainIsNotAChange(): void {
        $contact = $this->fetchedContact();
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $contact->set('name', $contact->get('name'));
        $contact->set('voice', '+39.0471999999');

        $this->assertTrue($contact->update());
    }

    public function testAFaxOnlyUpdateSendsNothing(): void {
        $contact = $this->fetchedContact();
        $contact->set('fax', '+39.0471000009');

        $this->assertTrue($contact->update());
        $this->assertSame([], $this->transport->requests);
    }

    // ---------------------------------------------------------------
    // domains
    // ---------------------------------------------------------------

    public function testDomainCreateNeverCarriesAdminOrTech(): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $domain = new Domain($this->nic);
        $domain->set('domain', 'example-one.it');
        $domain->set('registrant', 'REGI1234REGI5678');
        $domain->set('admin', 'ADMIN123ADMIN456');
        $domain->addTECH('TECH1234TECH5678');
        $domain->addNS('ns1.example.it');
        $domain->addNS('ns2.example.it');

        $this->assertTrue($domain->create());

        $sent = $this->transport->lastRequest();
        $this->assertStringNotContainsString('domain:contact', $sent);
        $this->assertStringContainsString('<domain:registrant>REGI1234REGI5678</domain:registrant>', $sent);
    }

    public function testDomainUpdateWithOnlyContactChangesIsASilentSuccess(): void {
        $domain = $this->fetchedDomain();
        $domain->set('admin', 'ADMIN123ADMIN456');
        $domain->addTECH('TECH-NEW');
        $domain->remTECH('TECH1234TECH5678');

        $this->assertTrue($domain->update());
        $this->assertSame([], $this->transport->requests, 'no update reaches the registry');
        $this->assertFalse($domain->hasChanges(), 'the change set is settled');
    }

    public function testDomainUpdateWithContactAndNameserverChangesSendsOnlyTheNameserver(): void {
        $domain = $this->fetchedDomain();
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $domain->set('admin', 'ADMIN123ADMIN456');
        $domain->addTECH('TECH-NEW');
        $domain->addNS('ns9.example.net');

        $this->assertTrue($domain->update());

        $sent = $this->transport->lastRequest();
        $this->assertStringContainsString('ns9.example.net', $sent);
        $this->assertStringNotContainsString('domain:contact', $sent);
    }

    public function testDomainUpdateRegistrantNeverCarriesAdmin(): void {
        $domain = $this->fetchedDomain();
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $domain->set('admin', 'ADMIN123ADMIN456');
        $domain->set('registrant', 'REGI9999REGI9999');
        $domain->set('authinfo', 'NEWAUTH123456789');

        $this->assertTrue($domain->updateRegistrant());

        $sent = $this->transport->lastRequest();
        $this->assertStringContainsString('<domain:registrant>REGI9999REGI9999</domain:registrant>', $sent);
        $this->assertStringNotContainsString('domain:contact', $sent);
    }

    public function testBuilderIgnoresAdminAndTechInAnUpdateSet(): void {
        $xml = XmlBuilder::domainUpdate(
            'TEST-1', 'example-one.it',
            ['admin' => 'A', 'tech' => ['T1'], 'ns' => ['ns1.example.it' => ['name' => 'ns1.example.it', 'ip' => []]]],
            ['tech' => ['T2']],
            []
        );

        $this->assertStringNotContainsString('domain:contact', $xml);
        $this->assertStringContainsString('ns1.example.it', $xml);
    }

    public function testInfContactsAcceptsOnlyAllAndRegistrant(): void {
        foreach (['admin', 'tech'] as $op) {
            $xml = XmlBuilder::domainInfo('TEST-1', 'example-one.it', 'AUTHINFO12345678', $op);
            $this->assertStringNotContainsString('infContacts', $xml, "op '{$op}' is refused by the registry");
        }
        foreach (['all', 'registrant'] as $op) {
            $xml = XmlBuilder::domainInfo('TEST-1', 'example-one.it', 'AUTHINFO12345678', $op);
            $this->assertStringContainsString("op=\"{$op}\"", $xml);
        }
    }
}
