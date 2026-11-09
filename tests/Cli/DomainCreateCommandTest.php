<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DomainCreateCommand;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `domain create` only registers: a name the registry already holds is
 * refused, pointing at `domain transfer request`, whatever --authinfo says.
 */
final class DomainCreateCommandTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS transfers');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT)');
    }

    private function create(array $args): array {
        $command = new DomainCreateCommand(['--yes', '--user=5', ...$args]);
        $errors = fopen('php://memory', 'w+');
        $command->useErrorStream($errors);

        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // login
        // example-two.it is taken; the answer lists only that name
        $this->sent->queue(preg_replace(
            '#<domain:cd><domain:name avail="true">.*?</domain:cd>#s', '', CommandCatalog::DOMAIN_CHECK_RESPONSE
        ));
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // logout
        $this->nic->setTransport($this->sent);
        $command->useClient($this->nic);

        ob_start();
        try {
            $status = $command->run();
        } finally {
            ob_end_clean();
        }
        rewind($errors);
        return [$status, (string) stream_get_contents($errors)];
    }

    public function testARegisteredNameIsRefusedEvenWithAnAuthinfo(): void {
        [$status, $errors] = $this->create([
            '--registrant=REGI1234REGI5678', '--authinfo=SECRET1234567890', 'example-two.it',
        ]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString("'example-two.it' is already registered", $errors);
        $this->assertStringContainsString('domain transfer request', $errors);
        $requests = implode('', $this->sent->requests);
        $this->assertStringNotContainsString('<transfer', $requests);
        $this->assertStringNotContainsString('<domain:create', $requests);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }
}
