<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * Until the delegation is validated the registry reports the nameservers
 * only as extdom:nsToValidate, apart from domain:ns.
 */
final class DomainNsToValidateTest extends EppTestCase
{
    private function fetch(string $response): Domain {
        $this->transport->queue($response);

        $domain = new Domain($this->nic);
        $this->assertTrue($domain->fetch('example-one.it'), $domain->getError());
        return $domain;
    }

    public function testDnsHoldHasOnlyThePendingSet(): void {
        $domain = $this->fetch(CommandCatalog::domainInfoWithPendingNs());

        $this->assertSame([], (array) $domain->get('ns'));
        $this->assertSame([
            ['name' => 'ns2.example.net', 'ipv4' => null, 'ipv6' => null],
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8:1234:5678::1'],
        ], DomainService::nameserverList((array) $domain->get('nsToValidate')));
    }

    public function testPendingUpdateKeepsBothSetsApart(): void {
        $domain = $this->fetch(CommandCatalog::domainInfoWithPendingNs(true));

        $this->assertSame(['ns1.example-one.it', 'ns2.example.net'], array_keys((array) $domain->get('ns')));
        $this->assertSame(['ns2.example.net', 'ns1.example-one.it'], array_keys((array) $domain->get('nsToValidate')));
        $this->assertSame('192.0.2.1', DomainService::nameserverList((array) $domain->get('ns'))[0]['ipv4']);
        $this->assertNull(DomainService::nameserverList((array) $domain->get('ns'))[0]['ipv6']);
    }

    public function testADomainWithoutAPendingSetHasNone(): void {
        $domain = $this->fetch(CommandCatalog::DOMAIN_INFO_RESPONSE);

        $this->assertSame([], (array) $domain->get('nsToValidate'));
    }
}
