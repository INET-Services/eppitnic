<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\Command\DomainCheckCommand;
use Eppitnic\Cli\Command\DomainInfoCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;

/**
 * The two read commands, driven end to end against canned registry responses.
 * The transport is substituted through the Client the commands build for
 * themselves, so this is the real path from argv to output, not a
 * rearrangement.
 */
final class DomainCommandTest extends EppTestCase
{
    /**
     * Point every Client built during $fn at a transport whose queue starts
     * with the greeting and login the session needs.
     *
     * @param string[] $responses what the registry answers, after login
     */
    private function withRegistry(Command $command, array $responses, callable $fn): string {
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);   // hello()
        $transport->queue(CommandCatalog::OK_RESPONSE);         // login()
        foreach ($responses as $response) {
            $transport->queue($response);
        }
        $transport->queue(CommandCatalog::OK_RESPONSE);         // logout()

        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testCheckReportsAvailability(): void {
        $command = new DomainCheckCommand(['example-one.it', 'example-two.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_CHECK_RESPONSE], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        $this->assertStringContainsString('example-one.it', $output);
        $this->assertStringContainsString('available', $output);
        $this->assertStringContainsString('example-two.it', $output);
        $this->assertStringContainsString('taken', $output);
    }

    /** the registry is asked in Unicode, once per name however it was typed */
    public function testCheckAsksForEachNameOnceInUnicode(): void {
        $command = new DomainCheckCommand(['Sesamstraße.IT', 'xn--sesamstrae-e4a.it', 'SESAMSTRAẞE.it.']);
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $transport->queue(CommandCatalog::DOMAIN_CHECK_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $command->run();
        ob_end_clean();

        $check = implode('', array_filter($transport->requests, static fn($r) => str_contains($r, '<domain:check')));
        $this->assertSame(1, substr_count($check, '<domain:name>'));
        $this->assertStringContainsString('<domain:name>sesamstraße.it</domain:name>', $check);
    }

    public function testInfoAsksInUnicodeForAPunycodeName(): void {
        $command = new DomainInfoCommand(['XN--SESAMSTRAE-E4A.IT']);
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $command->run();
        ob_end_clean();

        $this->assertStringContainsString('>sesamstraße.it</domain:name>', implode('', $transport->requests));
    }

    public function testCheckJsonOutputIsParseable(): void {
        $command = new DomainCheckCommand(['--json', 'example-one.it', 'example-two.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_CHECK_RESPONSE], function () use ($command) {
            $command->run();
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "not JSON: {$output}");
        $this->assertCount(2, $decoded);
        $this->assertSame('example-one.it', $decoded[0]['domain']);
        $this->assertTrue($decoded[0]['available']);
        $this->assertFalse($decoded[1]['available']);
    }

    public function testInfoPrintsTheRegistryRecord(): void {
        $command = new DomainInfoCommand(['example-one.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_INFO_RESPONSE], function () use ($command) {
            $this->assertSame(0, $command->run());
        });

        foreach (['example-one.it', 'REGI1234REGI5678', 'ADMIN123ADMIN456', 'ns1.example-one.it', 'expires'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    public function testInfoPrintsEachNameserverWithItsAddresses(): void {
        $command = new DomainInfoCommand(['example-one.it']);
        $response = str_replace(
            '<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>',
            '<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr><domain:hostAddr ip="v6">2001:db8::1</domain:hostAddr>',
            CommandCatalog::DOMAIN_INFO_RESPONSE
        );

        $output = $this->withRegistry($command, [$response], function () use ($command) {
            $command->run();
        });

        $this->assertMatchesRegularExpression('/^  ns +ns1\.example-one\.it  192\.0\.2\.1  2001:db8::1$/m', $output);
        $this->assertMatchesRegularExpression('/^  ns +ns2\.example\.net$/m', $output);
    }

    public function testInfoShowsTheNameserversPendingValidation(): void {
        $command = new DomainInfoCommand(['example-one.it']);

        $output = $this->withRegistry($command, [CommandCatalog::domainInfoWithPendingNs()], function () use ($command) {
            $command->run();
        });

        $this->assertMatchesRegularExpression(
            '/^  ns \(pending validation\) +ns1\.example-one\.it  192\.0\.2\.1  2001:db8:1234:5678::1$/m',
            $output
        );
        $this->assertDoesNotMatchRegularExpression('/^  ns +ns/m', $output);
    }

    public function testInfoJsonCarriesThePendingSet(): void {
        $command = new DomainInfoCommand(['--json', 'example-one.it']);

        $output = $this->withRegistry($command, [CommandCatalog::domainInfoWithPendingNs()], function () use ($command) {
            $command->run();
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertSame([], $decoded[0]['ns']);
        $this->assertSame('ns2.example.net', $decoded[0]['ns_pending'][0]['name']);
        $this->assertSame('2001:db8:1234:5678::1', $decoded[0]['ns_pending'][1]['ipv6']);
    }

    public function testInfoJsonCarriesTheStructuredRecord(): void {
        $command = new DomainInfoCommand(['--json', 'example-one.it']);

        $output = $this->withRegistry($command, [CommandCatalog::DOMAIN_INFO_RESPONSE], function () use ($command) {
            $command->run();
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('example-one.it', $decoded[0]['domain']);
        $this->assertSame(['TECH1234TECH5678'], $decoded[0]['tech']);
        $this->assertSame([
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.1', 'ipv6' => null],
            ['name' => 'ns2.example.net', 'ipv4' => null, 'ipv6' => null],
        ], $decoded[0]['ns']);
    }

    /**
     * A domain the registry does not know must be reported and counted, not
     * silently skipped -- the exit code is what a cron job reads.
     */
    public function testInfoFailsWhenTheRegistryRejectsTheLookup(): void {
        $command = new DomainInfoCommand(['nonexistent.it']);

        // a real captured refusal, not a success response with the data left
        // out: the registry answers a failed domain:info with a 2xxx code, and
        // that is the path worth exercising
        $error = file_get_contents(__DIR__ . '/../fixtures/responses/domain-info-error.xml');

        $this->withRegistry($command, [$error], function () use ($command) {
            $this->assertSame(DOMAIN_FETCH_FAILED, $command->run());
        });
    }

    public function testCheckRejectsAnEmptyNameList(): void {
        $this->expectException(\Eppitnic\Cli\UsageError::class);
        (new DomainCheckCommand([]))->run();
    }

    public function testInfoRejectsAnInvalidContactsOption(): void {
        $this->expectException(\Eppitnic\Cli\UsageError::class);
        (new DomainInfoCommand(['--contacts=nonsense', 'x.it']))->run();
    }
}
