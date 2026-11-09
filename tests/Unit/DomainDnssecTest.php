<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Domain;
use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;

/** DS records: the key rollover, entry validation and the `dnssec` switch. */
final class DomainDnssecTest extends EppTestCase
{
    private const OLD_A = 'AAAA0000AAAA0000AAAA0000AAAA0000AAAA0000';
    private const OLD_B = 'BBBB0000BBBB0000BBBB0000BBBB0000BBBB0000';
    private const NEW_A = 'CCCC0000CCCC0000CCCC0000CCCC0000CCCC0000';
    private const NEW_B = 'DDDD0000DDDD0000DDDD0000DDDD0000DDDD0000';

    private static function ds(string $digest, int $tag = 1): array {
        return ['keytag' => $tag, 'algorithm' => 10, 'digesttype' => 2, 'digest' => $digest];
    }

    /** a domain whose registry copy holds two DS records */
    private function fetchedWithTwoDnskeys(): Domain {
        $dsData = '';
        foreach ([self::OLD_A => 11, self::OLD_B => 12] as $digest => $tag) {
            $dsData .= "<secDNS:dsData><secDNS:keyTag>{$tag}</secDNS:keyTag><secDNS:alg>10</secDNS:alg>"
                . "<secDNS:digestType>2</secDNS:digestType><secDNS:digest>{$digest}</secDNS:digest></secDNS:dsData>";
        }
        $info = str_replace(
            '</extension>',
            '<secDNS:infData xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">' . $dsData . '</secDNS:infData></extension>',
            CommandCatalog::DOMAIN_INFO_RESPONSE
        );
        $this->transport->queue($info);

        $domain = new Domain($this->nic);
        $this->assertTrue($domain->fetch('example-one.it'), $domain->getError());
        $this->assertCount(2, $domain->get('dnssec'));
        return $domain;
    }

    public function testReplacingBothKeysRemovesThenAddsAndSendsBoth(): void {
        $domain = $this->fetchedWithTwoDnskeys();

        $records = DomainService::dnssecRecords([self::ds(self::NEW_A, 21), self::ds(self::NEW_B, 22)]);
        $this->assertNull(DomainService::applyDnssec($domain, $records));

        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->assertTrue($domain->update(), $domain->getError());

        $xml = $this->transport->lastRequest();
        $this->assertStringContainsString(self::NEW_A, $xml);
        $this->assertStringContainsString(self::NEW_B, $xml);
        $this->assertStringContainsString(self::OLD_A, $xml);
        $this->assertLessThan(strpos($xml, 'secDNS:add'), strpos($xml, 'secDNS:rem'));
        $this->assertSame([self::NEW_A, self::NEW_B], array_map('strval', array_keys($domain->get('dnssec'))));
    }

    public function testAnUnchangedKeyIsKeptAndNotResent(): void {
        $domain = $this->fetchedWithTwoDnskeys();

        $records = DomainService::dnssecRecords([self::ds(self::OLD_A, 11), self::ds(self::NEW_A, 21)]);
        $this->assertNull(DomainService::applyDnssec($domain, $records));

        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->assertTrue($domain->update(), $domain->getError());
        $this->assertStringNotContainsString(self::OLD_A, $this->transport->lastRequest());
    }

    public function testAnAddTheRegistryCannotHoldIsReportedNotDropped(): void {
        $domain = $this->fetchedWithTwoDnskeys();
        $records = DomainService::dnssecRecords([
            self::ds(self::OLD_A, 11), self::ds(self::OLD_B, 12), self::ds(self::NEW_A, 21),
        ]);

        $this->assertStringContainsString('Only two', (string) DomainService::applyDnssec($domain, $records));
    }

    public function testRemDnssecNamesTheDigest(): void {
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->remDNSSEC(self::OLD_A));
        $this->assertStringContainsString('digest', $domain->getError());
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidRecords(): array {
        return [
            'not a list'      => ['x'],
            'entry not array' => [['x']],
            'missing keytag'  => [[['algorithm' => 10, 'digesttype' => 2, 'digest' => 'AB']]],
            'empty digest'    => [[['keytag' => 1, 'algorithm' => 10, 'digesttype' => 2, 'digest' => '']]],
            'array digest'    => [[['keytag' => 1, 'algorithm' => 10, 'digesttype' => 2, 'digest' => ['AB']]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRecords')]
    public function testInvalidEntriesAreRefusedWithAMessage(mixed $records): void {
        $this->assertIsString(DomainService::dnssecRecords($records));
    }

    public function testValidEntriesComeBackAsStrings(): void {
        $this->assertSame(
            [['keytag' => '1', 'algorithm' => '10', 'digesttype' => '2', 'digest' => 'AB']],
            DomainService::dnssecRecords([['keytag' => 1, 'algorithm' => 10, 'digesttype' => 2, 'digest' => 'AB']])
        );
    }

    /** what the API returns is what PATCH accepts, so it can be sent back */
    public function testAFetchedSetListsInThePatchShape(): void {
        $list = DomainService::dnssecList($this->fetchedWithTwoDnskeys()->get('dnssec'));

        $this->assertSame([
            ['keytag' => '11', 'algorithm' => '10', 'digesttype' => '2', 'digest' => self::OLD_A],
            ['keytag' => '12', 'algorithm' => '10', 'digesttype' => '2', 'digest' => self::OLD_B],
        ], $list);
        $this->assertSame($list, DomainService::dnssecRecords($list));
    }

    private function withDnssecOff(): Domain {
        Config::loadForTesting(['dnssec' => ['active' => 0]] + static::SETTINGS);
        $this->nic = new Client();
        $this->nic->setTransport($this->transport);

        $domain = new Domain($this->nic);
        $domain->set('domain', 'example-one.it');
        return $domain;
    }

    public function testCreateRefusesDsRecordsWhileDnssecIsOff(): void {
        $domain = $this->withDnssecOff();
        $domain->addDNSSEC('12345', '10', '2', self::NEW_A);

        $this->assertFalse($domain->create());
        $this->assertStringContainsString('config dnssec on', $domain->getError());
        $this->assertCount(0, $this->transport->requests);
    }

    public function testUpdateRefusesDsRecordsWhileDnssecIsOff(): void {
        $domain = $this->withDnssecOff();
        $domain->addDNSSEC('12345', '10', '2', self::NEW_A);

        $this->assertFalse($domain->update());
        $this->assertStringContainsString('config dnssec on', $domain->getError());
        $this->assertCount(0, $this->transport->requests);
    }

    public function testCreateWithoutDsRecordsStillWorksWhileDnssecIsOff(): void {
        $domain = $this->withDnssecOff();
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->assertTrue($domain->create(), $domain->getError());
    }
}
