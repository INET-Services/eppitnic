<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Session;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * Session::poll() stores the message of a poll request that succeeded. A
 * refused one may still carry the queue's counters in its msgQ, but holds no
 * message to store.
 */
final class SessionPollStoreTest extends EppTestCase
{
    private const REFUSED_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2306"><msg lang="en">Parameter value policy error</msg>
          <extValue><value/><reason lang="en">Message ID is not allowed</reason></extValue></result>
        <msgQ count="3" id="4711"><msg lang="en">Domain status changed</msg></msgQ>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['messages', 'msgqueue'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, cl_trid TEXT, sv_trid TEXT, type TEXT,
                 domain TEXT, ac_id TEXT, re_id TEXT, data TEXT)');
        R::exec('CREATE TABLE msgqueue (id INTEGER PRIMARY KEY, cl_trid TEXT, sv_trid TEXT, sv_code TEXT,
                 status INTEGER, sv_httpcode INTEGER, sv_httpheaders TEXT, sv_httpdata TEXT)');
    }

    public function testASuccessfulRequestStoresItsMessage(): void {
        $this->transport->queue(CommandCatalog::POLL_RESPONSE);
        $session = new Session($this->nic);

        $this->assertTrue($session->poll(true, 'req'));

        $this->assertSame(['chgStatusMsgData', 'example-one.it'], array_values(R::getRow('SELECT type, domain FROM messages')));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM messages'));
    }

    public function testAFailedRequestStoresNoMessage(): void {
        $this->transport->queue(self::REFUSED_RESPONSE);
        $session = new Session($this->nic);

        $this->assertFalse($session->poll(true, 'req', 4711));

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM messages'));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM msgqueue'));
        $this->assertSame(3, $session->pollMessageCount(), 'the queue counters are still read');
    }
}
