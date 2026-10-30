<?php

namespace Eppitnic\Tests\Wire;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Values reach the registry as themselves. The object layer used to run
 * everything through htmlspecialchars() and emit it raw, filling the database
 * with entities. These assert what replaced it: escaped once, by the
 * serializer.
 */
final class EscapingTest extends EppTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function awkwardValues(): array {
        return [
            'ampersand'      => ['Rossi & Figli S.r.l.'],
            'angle brackets' => ['A <b> company'],
            'quotes'         => ['The "Big" Company'],
            'apostrophe'     => ["O'Brien e Figli"],
            'already an entity' => ['Literally &amp; typed'],
            'accents'        => ['Möbel Graf & C. KG'],
            'mixed'          => ['<a href="x">A & B</a>'],
        ];
    }

    /**
     * @param string $value what the caller set
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardValues')]
    public function testContactOrgSurvivesUnchanged(string $value): void {
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $contact = new Contact($this->nic);
        $contact->set('handle', 'ABCD1234EFGH5678');
        $contact->set('name', $value);
        $contact->set('org', $value);
        $contact->set('street', 'Via Roma 1');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39100');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.0471000000');
        $contact->set('email', 'mario.rossi@example.it');
        $contact->set('nationalitycode', 'IT');
        $contact->set('entitytype', 2);
        $contact->set('regcode', '01234567890');
        $contact->create();

        // what the registry's parser will see, not what the bytes look like
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($this->transport->lastRequest()), 'generated malformed XML');

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:contact-1.0');
        $org = $xpath->query('//c:org')->item(0);

        $this->assertNotNull($org);
        $this->assertSame($value, $org->textContent);
    }

    /**
     * get() must answer with what was set, too: the value is stored as given,
     * so a caller reading it back -- or a CSV export, or the REST API -- sees
     * the real thing rather than entities to undo.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardValues')]
    public function testGetReturnsWhatWasSet(string $value): void {
        $contact = new Contact($this->nic);
        $contact->set('org', $value);

        $this->assertSame($value, $contact->get('org'));
    }

    /**
     * An authinfo is a password: it may contain anything, and it goes into an
     * element the registry reads as a credential.
     */
    public function testDomainAuthinfoSurvivesUnchanged(): void {
        $value = 'p&w<"x>d';

        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $domain = new Domain($this->nic);
        $domain->set('domain', 'example-one.it');
        $domain->set('authinfo', $value);
        $domain->update();

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($this->transport->lastRequest()));

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', 'urn:ietf:params:xml:ns:domain-1.0');

        $this->assertSame($value, $xpath->query('//d:pw')->item(0)->textContent);
    }
}
