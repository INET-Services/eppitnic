<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Epp\Session;

/**
 * Every EPP request this codebase can generate, and how to make it generate one
 * -- the single list the wire snapshot test iterates. Each entry drives the
 * real API with fixed inputs and leaves the request in $xmlQuery.
 */
final class CommandCatalog
{
    /** a refused <login>, as the .it registry answers it */
    public static function loginRefusal(string $code, string $msg, string $reasonCode, string $reason): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:extepp="http://www.nic.it/ITNIC-EPP/extepp-2.0">'
            . "<response><result code=\"{$code}\"><msg lang=\"en\">{$msg}</msg><extValue><value>"
            . "<extepp:reasonCode>{$reasonCode}</extepp:reasonCode></value><reason lang=\"en\">{$reason}</reason>"
            . '</extValue></result><trID><svTRID>x</svTRID></trID></response></epp>';
    }

    /** a response ExecuteQuery() can parse without complaint */
    public const OK_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    /** what the server answers a <hello> with */
    public const GREETING_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <greeting>
        <svID>ITNIC EPP Registry</svID>
        <svDate>2026-01-01T00:00:00.000+01:00</svDate>
        <svcMenu><version>1.0</version><lang>en</lang></svcMenu>
      </greeting>
    </epp>
    XML;

    /**
     * Reconstructions, not captures: enough to drive the commands, but not
     * evidence the parsers handle what the registry sends -- that is
     * ResponseParsingTest's job, against tests/fixtures/responses/.
     */
    public const DOMAIN_CHECK_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <resData>
          <domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:cd><domain:name avail="true">example-one.it</domain:name></domain:cd>
            <domain:cd><domain:name avail="false">example-two.it</domain:name><domain:reason lang="en">Domain already registered</domain:reason></domain:cd>
          </domain:chkData>
        </resData>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public const CONTACT_CHECK_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <resData>
          <contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
            <contact:cd><contact:id avail="true">ABCD1234EFGH5678</contact:id></contact:cd>
            <contact:cd><contact:id avail="false">IJKL9012MNOP3456</contact:id></contact:cd>
          </contact:chkData>
        </resData>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public const DOMAIN_INFO_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <resData>
          <domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name>example-one.it</domain:name>
            <domain:roid>EXAMPLE-ONE-ITNIC</domain:roid>
            <domain:status s="ok"/>
            <domain:registrant>REGI1234REGI5678</domain:registrant>
            <domain:contact type="admin">ADMIN123ADMIN456</domain:contact>
            <domain:contact type="tech">TECH1234TECH5678</domain:contact>
            <domain:ns>
              <domain:hostAttr>
                <domain:hostName>ns1.example-one.it</domain:hostName>
                <domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>
              </domain:hostAttr>
              <domain:hostAttr><domain:hostName>ns2.example.net</domain:hostName></domain:hostAttr>
            </domain:ns>
            <domain:clID>TEST-REG</domain:clID>
            <domain:crDate>2020-01-01T00:00:00.000+01:00</domain:crDate>
            <domain:exDate>2027-01-01T00:00:00.000+01:00</domain:exDate>
            <domain:authInfo><domain:pw>AUTHINFO12345678</domain:pw></domain:authInfo>
          </domain:infData>
        </resData>
        <extension>
          <extdom:infData xmlns:extdom="http://www.nic.it/ITNIC-EPP/extdom-2.0">
            <extdom:ownStatus s="ok"/>
          </extdom:infData>
        </extension>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public const CONTACT_INFO_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <resData>
          <contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
            <contact:id>ABCD1234EFGH5678</contact:id>
            <contact:roid>ABCD1234EFGH5678-ITNIC</contact:roid>
            <contact:status s="ok"/>
            <contact:postalInfo type="loc">
              <contact:name>Mario Rossi</contact:name>
              <contact:org>Rossi &amp; Figli S.r.l.</contact:org>
              <contact:addr>
                <contact:street>Via Roma 1</contact:street>
                <contact:street>Scala B</contact:street>
                <contact:city>Bolzano</contact:city>
                <contact:sp>BZ</contact:sp>
                <contact:pc>39100</contact:pc>
                <contact:cc>IT</contact:cc>
              </contact:addr>
            </contact:postalInfo>
            <contact:voice>+39.0471000000</contact:voice>
            <contact:fax>+39.0471000001</contact:fax>
            <contact:email>mario.rossi@example.it</contact:email>
            <contact:clID>TEST-REG</contact:clID>
          </contact:infData>
        </resData>
        <extension>
          <extcon:infData xmlns:extcon="http://www.nic.it/ITNIC-EPP/extcon-1.0">
            <extcon:consentForPublishing>true</extcon:consentForPublishing>
            <extcon:registrant>
              <extcon:nationalityCode>IT</extcon:nationalityCode>
              <extcon:entityType>2</extcon:entityType>
              <extcon:regCode>01234567890</extcon:regCode>
            </extcon:registrant>
          </extcon:infData>
        </extension>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public const TRANSFER_QUERY_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <resData>
          <domain:trnData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name>example-one.it</domain:name>
            <domain:trStatus>pending</domain:trStatus>
            <domain:reID>TEST-REG</domain:reID>
            <domain:reDate>2026-01-01T00:00:00.000+01:00</domain:reDate>
            <domain:acID>OTHER-REG</domain:acID>
            <domain:acDate>2026-01-06T00:00:00.000+01:00</domain:acDate>
          </domain:trnData>
        </resData>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public const POLL_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1301"><msg lang="en">Command completed successfully; ack to dequeue</msg></result>
        <msgQ count="3" id="4711"><msg>Domain status changed</msg></msgQ>
        <extension>
          <extdom:chgStatusMsgData xmlns:extdom="http://www.nic.it/ITNIC-EPP/extdom-2.0">
            <extdom:name>example-one.it</extdom:name>
            <extdom:targetStatus>
              <domain:status xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" s="ok"/>
            </extdom:targetStatus>
          </extdom:chgStatusMsgData>
        </extension>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public const LOGIN_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="1000"><msg lang="en">Command completed successfully</msg></result>
        <extension>
          <extepp:creditMsgData xmlns:extepp="http://www.nic.it/ITNIC-EPP/extepp-2.0">
            <extepp:credit>1234.56</extepp:credit>
          </extepp:creditMsgData>
        </extension>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    /**
     * fixed DNSSEC material -- real-shaped, but not anybody's actual key
     */
    private const DS_DIGEST = '2BB183AF5F22588179A53B0A98631FAD1A292118';

    /**
     * @return array<string, callable(Client, FakeTransport): object> fixture name => driver
     */
    public static function all(): array {
        return [
            'session-hello' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::GREETING_RESPONSE);
                $s = new Session($nic);
                $s->hello();
                return $s;
            },

            'session-login' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::LOGIN_RESPONSE);
                $s = new Session($nic);
                $s->login();
                return $s;
            },

            'session-login-newpw' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::LOGIN_RESPONSE);
                $s = new Session($nic);
                $s->login('new-password-42');
                return $s;
            },

            'session-logout' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::LOGIN_RESPONSE);
                $s = new Session($nic);
                $s->logout();
                return $s;
            },

            'session-poll-req' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::POLL_RESPONSE);
                $s = new Session($nic);
                $s->poll(false, 'req');
                return $s;
            },

            'session-poll-ack' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::POLL_RESPONSE);
                $s = new Session($nic);
                $s->poll(false, 'ack', 4711);
                return $s;
            },

            'contact-check' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::CONTACT_CHECK_RESPONSE);
                $c = new Contact($nic);
                $c->check(['ABCD1234EFGH5678', 'IJKL9012MNOP3456']);
                return $c;
            },

            'contact-create' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $c = self::populateContact(new Contact($nic));
                $c->create();
                return $c;
            },

            'contact-info' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::CONTACT_INFO_RESPONSE);
                $c = new Contact($nic);
                $c->fetch('ABCD1234EFGH5678');
                return $c;
            },

            'contact-delete' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $c = new Contact($nic);
                $c->delete('ABCD1234EFGH5678');
                return $c;
            },

            'contact-update' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $c = self::populateContact(new Contact($nic), false);
                $c->update();
                return $c;
            },

            // Only the email changes: nothing else may appear on the wire.
            'contact-update-email-only' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $c = new Contact($nic);
                $c->set('handle', 'ABCD1234EFGH5678');
                $c->set('email', 'nuovo@example.it');
                $c->update();
                return $c;
            },

            'contact-status' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $c = new Contact($nic);
                $c->set('handle', 'ABCD1234EFGH5678');
                $c->updateStatus('clientDeleteProhibited', 'add');
                return $c;
            },

            'domain-check' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::DOMAIN_CHECK_RESPONSE);
                $d = new Domain($nic);
                $d->check(['example-one.it', 'example-two.it']);
                return $d;
            },

            'domain-create' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = self::populateDomain(new Domain($nic));
                $d->create();
                return $d;
            },

            'domain-create-dnssec' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = self::populateDomain(new Domain($nic));
                $d->addDNSSEC('12345', '10', '2', self::DS_DIGEST);
                $d->create();
                return $d;
            },

            'domain-info' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::DOMAIN_INFO_RESPONSE);
                $d = new Domain($nic);
                $d->fetch('example-one.it');
                return $d;
            },

            'domain-info-authinfo-contacts' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::DOMAIN_INFO_RESPONSE);
                $d = new Domain($nic);
                $d->fetch('example-one.it', 'AUTHINFO12345678', 'all');
                return $d;
            },

            'domain-delete' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->delete('example-one.it');
                return $d;
            },

            'domain-update' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->set('domain', 'example-one.it');
                $d->addNS('ns1.example.it', ['192.0.2.1']);
                $d->addTECH('TECH1234TECH5678');
                $d->set('admin', 'ADMIN123ADMIN456');
                $d->set('authinfo', 'NEWAUTH123456789');
                $d->update();
                return $d;
            },

            'domain-update-dnssec' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->set('domain', 'example-one.it');
                $d->addDNSSEC('12345', '10', '2', self::DS_DIGEST);
                $d->update();
                return $d;
            },

            'domain-update-registrant' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->set('domain', 'example-one.it');
                $d->set('registrant', 'REGI1234REGI5678');
                $d->set('authinfo', 'NEWAUTH123456789');
                $d->updateRegistrant();
                return $d;
            },

            'domain-status' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->set('domain', 'example-one.it');
                $d->updateStatus('clientTransferProhibited', 'add');
                return $d;
            },

            'domain-restore' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->restore('example-one.it');
                return $d;
            },

            'domain-transfer-request' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->transfer('example-one.it', 'AUTHINFO12345678', '', 'NEWAUTH123456789');
                return $d;
            },

            'domain-transfer-trade' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->transfer('example-one.it', 'AUTHINFO12345678', 'REGI1234REGI5678', 'NEWAUTH123456789');
                return $d;
            },

            'domain-transfer-approve' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::OK_RESPONSE);
                $d = new Domain($nic);
                $d->transferApprove('example-one.it', 'AUTHINFO12345678');
                return $d;
            },

            'domain-transfer-query' => function (Client $nic, FakeTransport $t): object {
                $t->queue(self::TRANSFER_QUERY_RESPONSE);
                $d = new Domain($nic);
                $d->transferStatus('example-one.it', 'AUTHINFO12345678');
                return $d;
            },
        ];
    }

    /**
     * a registrant contact with every field populated, including the optional
     * ones -- a fixture that only covers required fields protects only the
     * paths that were never in doubt
     */
    private static function populateContact(Contact $c, bool $withNames = true): Contact {
        $c->set('handle', 'ABCD1234EFGH5678');
        if ($withNames) {
            $c->set('name', 'Rossi & Figli S.r.l.');
            $c->set('org', 'Rossi & Figli S.r.l.');
        }
        $c->set('street', 'Via Roma 1');
        $c->set('street2', 'Scala B');
        $c->set('street3', 'Interno 3');
        $c->set('city', 'Bolzano');
        $c->set('province', 'BZ');
        $c->set('postalcode', '39100');
        $c->set('countrycode', 'IT');
        $c->set('voice', '+39.0471000000');
        $c->set('fax', '+39.0471000001');
        $c->set('email', 'mario.rossi@example.it');
        $c->set('authinfo', 'AUTHINFO12345678');
        $c->set('consentforpublishing', 1);
        $c->set('nationalitycode', 'IT');
        $c->set('entitytype', 2);
        $c->set('regcode', '01234567890');
        return $c;
    }

    /**
     * a domain with two nameservers (one glued, one not), an admin and two
     * technical contacts, which never reach the wire
     */
    private static function populateDomain(Domain $d): Domain {
        $d->set('domain', 'example-one.it');
        $d->set('registrant', 'REGI1234REGI5678');
        $d->set('admin', 'ADMIN123ADMIN456');
        $d->addTECH('TECH1234TECH5678');
        $d->addTECH('TECH9012TECH3456');
        $d->addNS('ns1.example-one.it', ['192.0.2.1', '2001:db8::1']);
        $d->addNS('ns2.example.net');
        $d->set('authinfo', 'AUTHINFO12345678');
        return $d;
    }
}
