<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\Command\ContactUpdateCommand;
use Eppitnic\Cli\Command\DomainInfoCommand;
use Eppitnic\Cli\Command\DomainUpdateCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;

/**
 * Registry v4.0 on the command line: the contact options of the old model are
 * accepted and ignored, and what the registry would refuse is refused first.
 */
final class RegistryV4CommandsTest extends EppTestCase
{
    /** @param string[] $responses what the registry answers after login */
    private function run_(Command $command, array $responses = []): string {
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        foreach ($responses as $response) {
            $transport->queue($response);
        }
        $transport->queue(CommandCatalog::OK_RESPONSE);

        $this->transport = $transport;
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        try {
            $command->run();
            $command->flush();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function testInfoRefusesAdminAndTechAsContactsValues(): void {
        foreach (['admin', 'tech'] as $value) {
            try {
                (new DomainInfoCommand(["--contacts={$value}", 'example-one.it']))->run();
                $this->fail("--contacts={$value} was accepted");
            } catch (UsageError $e) {
                $this->assertStringContainsString('all, registrant', $e->getMessage());
            }
        }
    }

    public function testUpdateWithOnlyContactOptionsHasNothingToDo(): void {
        $command = new DomainUpdateCommand(['--tech=TECH1234TECH5678', '--admin=ADMIN123ADMIN456', '--add-tech=T2', '--rem-tech=T3', '--yes', 'example-one.it']);

        $output = $this->run_($command);

        $this->assertStringContainsString('nothing to do', $output);
        $this->assertSame([], $this->transport->requests, 'no session, no update');
    }

    public function testUpdateWithNoOptionAtAllIsStillAUsageError(): void {
        $this->expectException(UsageError::class);
        (new DomainUpdateCommand(['--yes', 'example-one.it']))->run();
    }

    public function testUpdateSendsTheNameserverChangeWithoutTheContactOnes(): void {
        $command = new DomainUpdateCommand(['--dry-run', '--tech=TECH-NEW', '--admin=ADMIN-NEW', '--add-ns=ns9.example.net', 'example-one.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $command->run();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('<domain:update', $output);
        $this->assertStringContainsString('ns9.example.net', $output);
        $this->assertStringNotContainsString('domain:contact', $output);
    }

    public function testContactUpdateRefusesANameChange(): void {
        $command = new ContactUpdateCommand(['--name=Someone Else', '--yes', 'ABCD1234EFGH5678']);

        $this->run_($command, [CommandCatalog::CONTACT_INFO_RESPONSE]);

        $updates = array_filter($this->transport->requests, fn($r) => str_contains($r, '<contact:update'));
        $this->assertSame([], $updates);
    }

    public function testContactUpdateIgnoresFax(): void {
        $command = new ContactUpdateCommand(['--fax=+39.0471000009', '--voice=+39.0471999999', '--yes', 'ABCD1234EFGH5678']);

        $this->run_($command, [CommandCatalog::CONTACT_INFO_RESPONSE, CommandCatalog::OK_RESPONSE]);

        $updates = array_values(array_filter($this->transport->requests, fn($r) => str_contains($r, '<contact:update')));
        $this->assertCount(1, $updates);
        $this->assertStringNotContainsString('contact:fax', $updates[0]);
    }
}
