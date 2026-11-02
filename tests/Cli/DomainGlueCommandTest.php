<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Config;
use Eppitnic\Cli\Command\DomainCreateCommand;
use Eppitnic\Cli\Command\DomainUpdateCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * --glue on `domain update` and `domain create`, under --dry-run: the honest
 * test is the request that would go out.
 */
final class DomainGlueCommandTest extends EppTestCase
{
    private function preview(Command $command, ?string &$errors = null): string {
        $stream = fopen('php://memory', 'w+');
        $command->useErrorStream($stream);

        ob_start();
        $command->run();
        $output = (string) ob_get_clean();
        rewind($stream);
        $errors = (string) stream_get_contents($stream);
        return $output;
    }

    public function testUpdateAddsAHostWithBothAddresses(): void {
        $output = $this->preview(new DomainUpdateCommand([
            '--dry-run', '--glue=ns1.example-one.it=192.0.2.1,2001:db8::1', 'example-one.it',
        ]));

        $this->assertStringContainsString('<domain:hostName>ns1.example-one.it</domain:hostName>', $output);
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>', $output);
        $this->assertStringContainsString('<domain:hostAddr ip="v6">2001:db8::1</domain:hostAddr>', $output);
    }

    public function testUpdateTakesSeveralEntriesSeparatedBySemicolons(): void {
        $output = $this->preview(new DomainUpdateCommand([
            '--dry-run', '--add-ns=ns3.example.net',
            '--glue=ns1.example-one.it=192.0.2.1,2001:db8::1;ns2.example-one.it=192.0.2.2', 'example-one.it',
        ]));

        $this->assertStringContainsString('192.0.2.2', $output);
        $this->assertStringContainsString('ns3.example.net', $output);
        $this->assertSame(3, substr_count($output, '<domain:hostAddr'));
    }

    private function refusal(Command $command, string $message): void {
        $transport = new FakeTransport();
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        try {
            $command->run();
            $this->fail('expected a usage error');
        } catch (UsageError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame([], $transport->requests, 'the registry heard nothing');
    }

    public function testUpdateRefusesGlueOutsideTheDomain(): void {
        $this->refusal(
            new DomainUpdateCommand(['--yes', '--glue=ns1.example.net=192.0.2.1', 'example-one.it']),
            'glue addresses are only allowed for nameservers under example-one.it'
        );
    }

    public function testUpdateRefusesAHostUnderTheDomainWithoutAnIpv4(): void {
        $this->refusal(
            new DomainUpdateCommand(['--yes', '--glue=ns1.example-one.it=2001:db8::1', 'example-one.it']),
            'needs an IPv4 address'
        );
    }

    public function testUpdateRefusesABareHostUnderTheDomain(): void {
        $this->preview(new DomainUpdateCommand([
            '--dry-run', '--add-ns=ns1.example-one.it', 'example-one.it',
        ]), $errors);

        $this->assertStringContainsString('needs an IPv4 address', $errors);
    }

    public function testAMalformedGlueOptionIsAUsageError(): void {
        $this->expectException(UsageError::class);
        (new DomainUpdateCommand(['--dry-run', '--glue=ns1.example-one.it', 'example-one.it']))->run();
    }

    public function testCreateSendsTheGlueWithTheNameservers(): void {
        $output = $this->preview(new DomainCreateCommand([
            '--dry-run', '--registrant=REGI1234REGI5678', '--ns=ns2.example.net',
            '--glue=ns1.example-one.it=192.0.2.1,2001:db8::1', 'example-one.it',
        ]));

        $this->assertStringContainsString('<domain:create', $output);
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>', $output);
        $this->assertStringContainsString('<domain:hostAddr ip="v6">2001:db8::1</domain:hostAddr>', $output);
        $this->assertStringContainsString('<domain:hostName>ns2.example.net</domain:hostName>', $output);
    }

    public function testCreateRefusesGlueOutsideTheDomain(): void {
        $this->refusal(
            new DomainCreateCommand(['--yes', '--registrant=REGI1234REGI5678', '--glue=ns1.example.net=192.0.2.1', 'example-one.it']),
            'glue addresses are only allowed'
        );
    }

    public function testCreateRefusesABareHostUnderTheDomain(): void {
        $this->refusal(
            new DomainCreateCommand(['--yes', '--registrant=REGI1234REGI5678', '--ns=ns1.example-one.it:ns2.example.it', 'example-one.it']),
            'needs an IPv4 address'
        );
    }

    /** the update the registry would get for a dnsHold domain with two pending hosts */
    private function dnsHoldUpdate(array $argv): string {
        Config::loadForTesting(static::SETTINGS + [
            'pdns' => ['enabled' => false, 'apis' => [], 'nameservers' => [], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
        ]);
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, authinfo TEXT, ns TEXT, registrant TEXT, admin TEXT, tech TEXT,
                 cr_date TEXT, ex_date TEXT, dnssec TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('example-one.it', 2, 'REGI1234REGI5678')");

        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $transport->queue(CommandCatalog::domainInfoWithPendingNs());
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        $this->nic->setTransport($transport);

        $command = new DomainUpdateCommand(array_merge(['--yes'], $argv, ['example-one.it']));
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        try {
            $command->run();
        } finally {
            ob_end_clean();
        }

        $updates = array_values(array_filter($transport->requests, fn($r) => str_contains($r, '<domain:update')));
        $this->assertCount(1, $updates, 'one update was sent');
        return $updates[0];
    }

    public function testGlueOnADnsHoldDomainSendsTheWholeConfigurationInOneAdd(): void {
        $update = $this->dnsHoldUpdate(['--glue=ns1.example-one.it=192.0.2.7,2001:db8:1234:5678::1']);

        $this->assertStringNotContainsString('<domain:rem>', $update);
        $this->assertStringContainsString('<domain:hostName>ns2.example.net</domain:hostName>', $update);
        $this->assertStringContainsString('<domain:hostName>ns1.example-one.it</domain:hostName>', $update);
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.7</domain:hostAddr>', $update);
        $this->assertStringNotContainsString('192.0.2.1<', $update);
    }

    public function testRemovingAndAddingOnADnsHoldDomainSendsTheResultingSet(): void {
        $update = $this->dnsHoldUpdate(['--rem-ns=ns2.example.net', '--add-ns=ns3.example.net']);

        $this->assertStringNotContainsString('<domain:rem>', $update);
        $this->assertStringNotContainsString('ns2.example.net', $update);
        $this->assertStringContainsString('ns3.example.net', $update);
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>', $update, 'the kept host keeps its glue');
    }
}
