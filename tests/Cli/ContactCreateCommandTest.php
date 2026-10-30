<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ContactCreateCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;

/**
 * `contact create` without an explicit --authinfo. run() used to read
 * $contact->authinfo, a protected property from outside the class, which PHP
 * makes fatal. Nothing exercised the verb end to end, so it surfaced live.
 */
final class ContactCreateCommandTest extends EppTestCase
{
    private const FIELDS = [
        '--name=Mario Rossi',
        '--street=Via Roma 1',
        '--city=Bolzano',
        '--province=BZ',
        '--postalcode=39100',
        '--countrycode=IT',
        '--voice=+39.0471000000',
        '--email=mario.rossi@example.it',
        '--nationalitycode=IT',
        '--entitytype=1',
        '--regcode=RSSMRA80A01A952L',
    ];

    private function withRegistry(callable $fn): string {
        $transport = $this->transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::LOGIN_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);   // the create itself
        $transport->queue(CommandCatalog::LOGIN_RESPONSE); // logout, same shape

        $this->nic->setTransport($transport);

        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testCreateWithNoAuthinfoGeneratesOneRatherThanCrashing(): void {
        $command = new ContactCreateCommand([...self::FIELDS, '--yes', '--json', 'SELFTEST12345678']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->withRegistry(function () use ($command) {
            $this->assertSame(0, $command->run());
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "output was not valid JSON: {$output}");
        $this->assertSame('SELFTEST12345678', $decoded[0]['handle']);
        $this->assertSame(16, strlen($decoded[0]['authinfo']), 'expected a generated 16-character authinfo');
    }

    public function testCreateStillHonoursAnExplicitAuthinfo(): void {
        $command = new ContactCreateCommand([...self::FIELDS, '--authinfo=My-Own-Secret1', '--yes', '--json', 'SELFTEST12345678']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->withRegistry(function () use ($command) {
            $this->assertSame(0, $command->run());
            $command->flush();
        });

        $decoded = json_decode($output, true);
        $this->assertSame('My-Own-Secret1', $decoded[0]['authinfo']);
    }

    public function testAMissingEntityTypeIsAUsageError(): void {
        $fields = array_values(array_filter(self::FIELDS, fn($f) => ! str_starts_with($f, '--entitytype')));
        $command = new ContactCreateCommand([...$fields, '--yes', 'SELFTEST12345678']);
        $command->useClient($this->nic);

        $this->expectException(UsageError::class);
        $command->run();
    }

    public function testEntityTypeZeroIsAUsageError(): void {
        $fields = array_values(array_filter(self::FIELDS, fn($f) => ! str_starts_with($f, '--entitytype')));
        $command = new ContactCreateCommand([...$fields, '--entitytype=0', '--yes', 'SELFTEST12345678']);
        $command->useClient($this->nic);

        $this->expectException(UsageError::class);
        $command->run();
    }

    public function testDifferingNameAndOrgAreAUsageError(): void {
        $command = new ContactCreateCommand([...self::FIELDS, '--org=Other Name', '--yes', 'SELFTEST12345678']);
        $command->useClient($this->nic);

        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('name and org must be the same');
        $command->run();
    }

    public function testOrgIsCopiedFromNameAndFaxNeverSent(): void {
        $command = new ContactCreateCommand([...self::FIELDS, '--fax=+39.0471000001', '--yes', '--json', 'SELFTEST12345678']);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->withRegistry(function () use ($command) {
            $this->assertSame(0, $command->run());
            $command->flush();
        });

        $sent = implode("\n", array_filter($this->transport->requests, fn($r) => str_contains($r, 'contact:create')));
        $this->assertStringContainsString('<contact:name>Mario Rossi</contact:name>', $sent);
        $this->assertStringContainsString('<contact:org>Mario Rossi</contact:org>', $sent);
        $this->assertStringNotContainsString('contact:fax', $sent);
        $this->assertStringContainsString('<extcon:registrant>', $sent);
    }
}
