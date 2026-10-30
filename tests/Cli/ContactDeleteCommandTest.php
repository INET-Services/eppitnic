<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ContactDeleteCommand;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `contact delete` refuses a registrant of a held domain or pending transfer
 * before the registry is asked.
 */
final class ContactDeleteCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['contacts', 'domains', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, active INTEGER DEFAULT 1)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, status TEXT DEFAULT 'pending')");
        R::exec("INSERT INTO domains (domain, registrant) VALUES ('a.it', 'HELD')");
        R::exec("INSERT INTO transfers (domain, registrant) VALUES ('c.it', 'MOVING')");
    }

    public function testAReferencedContactIsRefusedWithoutAskingTheRegistry(): void {
        $errors = fopen('php://memory', 'w+');
        $command = new ContactDeleteCommand(['--yes', 'HELD', 'MOVING']);
        $command->useClient($this->nic);
        $command->useErrorStream($errors);

        ob_start();
        $code = $command->run();
        $command->flush();
        ob_end_clean();

        rewind($errors);
        $message = stream_get_contents($errors);
        $this->assertSame(CONTACT_DELETE_FAILED, $code);
        $this->assertStringContainsString('contact HELD is still the registrant of 1 domain(s): a.it', $message);
        $this->assertStringContainsString('contact MOVING is still the registrant of 1 domain(s): c.it', $message);
        $this->assertSame([], $this->transport->requests);
    }
}
