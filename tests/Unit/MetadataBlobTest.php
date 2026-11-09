<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\MetadataBlob;
use Eppitnic\Service\MetadataFailure;
use Eppitnic\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Verifying an MDS3 BLOB: signature, chain to the FIDO root, nextUpdate, and
 * what is kept of each entry.
 */
final class MetadataBlobTest extends TestCase
{
    private const AAGUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private array $root;
    private array $signer;
    private \DateTimeImmutable $now;

    protected function setUp(): void {
        $this->root = TestPki::root('Test FIDO Root');
        $this->signer = TestPki::signer($this->root);
        $this->now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function jwt(array $payload, ?array $signer = null, ?array $x5c = null): string {
        $signer ??= $this->signer;
        return TestPki::jwt($payload, $signer, $x5c ?? [$signer]);
    }

    private function failure(string $jwt, ?string $root = null, ?\DateTimeImmutable $now = null): string {
        try {
            MetadataBlob::verify($jwt, $root ?? $this->root['pem'], $now ?? $this->now);
        } catch (MetadataFailure $e) {
            return $e->reason;
        }
        $this->fail('the BLOB was accepted');
    }

    private function payload(array $more = [], int $serial = 7, ?string $next = null): array {
        return TestPki::payload([self::AAGUID => $more + ['description' => 'Test Key', 'roots' => [$this->root['pem']]]], $serial, $next);
    }

    public function testAGenuineBlobVerifiesAndKeepsWhatAttestationNeeds(): void {
        $next = $this->now->modify('+20 days')->format('Y-m-d');

        $blob = MetadataBlob::verify($this->jwt($this->payload([], 7, $next)), $this->root['pem'], $this->now);

        $this->assertSame(7, $blob->serial);
        $this->assertSame($next, $blob->nextUpdate);
        $this->assertSame([self::AAGUID], array_keys($blob->entries));
        $entry = $blob->entries[self::AAGUID];
        $this->assertSame('Test Key', $entry['description']);
        $this->assertSame('FIDO_CERTIFIED', $entry['status']);
        $this->assertSame(
            ['attestationRootCertificates', 'attestationTypes', 'keyProtection', 'matcherProtection', 'statusReports'],
            array_keys($entry['entry'])
        );
        $this->assertSame([TestPki::der($this->root)], $entry['entry']['attestationRootCertificates']);
        $this->assertSame(['basic_full'], $entry['entry']['attestationTypes']);
        $this->assertSame(['hardware'], $entry['entry']['keyProtection']);
        $this->assertStringNotContainsString('icon', json_encode($entry));
    }

    public function testASignatureThatDoesNotVerifyIsRefused(): void {
        [$header, $payload, $signature] = explode('.', $this->jwt($this->payload()));
        $other = explode('.', $this->jwt($this->payload([], 99)))[1];

        $this->assertSame(MetadataFailure::SIGNATURE, $this->failure("{$header}.{$other}.{$signature}"));
        $this->assertSame(MetadataFailure::SIGNATURE, $this->failure("{$header}.{$payload}." . strrev($signature)));
    }

    public function testASigningCertificateOfAnotherRootIsRefused(): void {
        $this->assertSame(MetadataFailure::CHAIN, $this->failure($this->jwt($this->payload()), TestPki::root('Other Root')['pem']));
    }

    public function testASignerWithoutACertificateChainToTheRootIsRefused(): void {
        $stranger = TestPki::signer(TestPki::root('Stranger'));

        $this->assertSame(MetadataFailure::CHAIN, $this->failure($this->jwt($this->payload(), $stranger)));
    }

    public function testAnIntermediateInTheChainIsFollowed(): void {
        $intermediate = TestPki::intermediate($this->root);
        $signer = TestPki::signer($intermediate);

        $blob = MetadataBlob::verify($this->jwt($this->payload(), $signer, [$signer, $intermediate]), $this->root['pem'], $this->now);

        $this->assertSame(7, $blob->serial);
    }

    public function testASignerCertificateThatIsExpiredIsRefused(): void {
        $this->assertSame(MetadataFailure::CHAIN, $this->failure($this->jwt($this->payload()), null, $this->now->modify('+90 days')));
    }

    public function testOnlyRs256IsAccepted(): void {
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure(TestPki::jwt($this->payload(), $this->signer, [$this->signer], 'ES256')));
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure(TestPki::jwt($this->payload(), $this->signer, [$this->signer], 'none')));
    }

    public function testWhatIsNotASignedJwtWithAChainIsRefused(): void {
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure('not a jwt'));
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure('a.b.c.d'));
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure(TestPki::jwt($this->payload(), $this->signer, [])));
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure(''));
    }

    public function testAPayloadWithoutSerialNextUpdateOrEntriesIsRefused(): void {
        foreach (['no', 'nextUpdate', 'entries'] as $field) {
            $payload = $this->payload();
            unset($payload[$field]);

            $this->assertSame(MetadataFailure::MALFORMED, $this->failure($this->jwt($payload)), $field);
        }
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure($this->jwt(['nextUpdate' => 'tomorrow'] + $this->payload())));
        $this->assertSame(MetadataFailure::MALFORMED, $this->failure($this->jwt(['no' => '7'] + $this->payload())));
    }

    public function testABlobPastItsNextUpdateAndTheGraceIsStale(): void {
        $today = $this->now->setTime(0, 0);

        $lastDay = $this->jwt($this->payload([], 7, $today->modify('-7 days')->format('Y-m-d')));
        $this->assertSame(7, MetadataBlob::verify($lastDay, $this->root['pem'], $this->now)->serial, 'the last day of the grace');

        $this->assertSame(MetadataFailure::STALE, $this->failure($this->jwt($this->payload([], 7, $today->modify('-8 days')->format('Y-m-d')))));
        $this->assertSame(MetadataFailure::STALE, $this->failure($this->jwt($this->payload([], 7, '2020-01-01'))));
    }

    public function testFreshnessIsTheSameRuleWithoutTheBlob(): void {
        $utc = new \DateTimeZone('UTC');

        $this->assertTrue(MetadataBlob::fresh('2026-06-01', new \DateTimeImmutable('2026-06-08 23:59:59', $utc)));
        $this->assertFalse(MetadataBlob::fresh('2026-06-01', new \DateTimeImmutable('2026-06-09', $utc)));
        $this->assertFalse(MetadataBlob::fresh('', new \DateTimeImmutable('2026-06-01', $utc)));
        $this->assertFalse(MetadataBlob::fresh('2026-02-30', new \DateTimeImmutable('2026-03-01', $utc)));
    }

    public function testEntriesWithoutAnAaguidOrStatementAreSkipped(): void {
        $payload = $this->payload();
        $payload['entries'][] = ['aaidx' => '1234#5678', 'metadataStatement' => ['description' => 'UAF']];
        $payload['entries'][] = ['attestationCertificateKeyIdentifiers' => ['aa'], 'metadataStatement' => ['description' => 'U2F']];
        $payload['entries'][] = ['aaguid' => 'not-a-uuid', 'metadataStatement' => ['description' => 'broken']];
        $payload['entries'][] = ['aaguid' => '11111111-2222-4333-8444-555555555555', 'statusReports' => []];
        $payload['entries'][] = 'junk';

        $blob = MetadataBlob::verify($this->jwt($payload), $this->root['pem'], $this->now);

        $this->assertSame([self::AAGUID], array_keys($blob->entries));
    }

    public function testAnAaguidIsKeptInLowerCase(): void {
        $blob = MetadataBlob::verify(
            $this->jwt(TestPki::payload([strtoupper(self::AAGUID) => ['roots' => []]])),
            $this->root['pem'],
            $this->now
        );

        $this->assertSame([self::AAGUID], array_keys($blob->entries));
    }

    public function testTheLatestStatusIsTheOneInForce(): void {
        $blob = MetadataBlob::verify(
            $this->jwt($this->payload(['statuses' => ['FIDO_CERTIFIED', 'USER_VERIFICATION_BYPASS', 'UPDATE_AVAILABLE']])),
            $this->root['pem'],
            $this->now
        );

        $entry = $blob->entries[self::AAGUID];
        $this->assertSame('UPDATE_AVAILABLE', $entry['status']);
        $this->assertSame(
            ['FIDO_CERTIFIED', 'USER_VERIFICATION_BYPASS', 'UPDATE_AVAILABLE'],
            array_column($entry['entry']['statusReports'], 'status')
        );
    }

    public function testAReportNotYetInEffectDoesNotCount(): void {
        $payload = $this->payload();
        $payload['entries'][0]['statusReports'] = [
            ['status' => 'FIDO_CERTIFIED', 'effectiveDate' => '2020-01-01'],
            ['status' => 'REVOKED', 'effectiveDate' => $this->now->modify('+3 days')->format('Y-m-d')],
        ];

        $entry = MetadataBlob::verify($this->jwt($payload), $this->root['pem'], $this->now)->entries[self::AAGUID];

        $this->assertSame('FIDO_CERTIFIED', $entry['status']);
        $this->assertCount(1, $entry['entry']['statusReports']);
    }

    public function testReportsAreOrderedByDateAndTheLaterListedWinsATie(): void {
        $payload = $this->payload();
        $payload['entries'][0]['statusReports'] = [
            ['status' => 'REVOKED', 'effectiveDate' => '2024-05-01'],
            ['status' => 'FIDO_CERTIFIED_L1', 'effectiveDate' => '2022-05-01'],
            ['status' => 'FIDO_CERTIFIED', 'effectiveDate' => '2022-05-01'],
        ];

        $entry = MetadataBlob::verify($this->jwt($payload), $this->root['pem'], $this->now)->entries[self::AAGUID];

        $this->assertSame('REVOKED', $entry['status']);
        $this->assertSame(['FIDO_CERTIFIED_L1', 'FIDO_CERTIFIED', 'REVOKED'], array_column($entry['entry']['statusReports'], 'status'));
    }

    public function testAnEntryWithoutReportsHasNoStatus(): void {
        $payload = $this->payload();
        $payload['entries'][0]['statusReports'] = [];

        $this->assertNull(MetadataBlob::verify($this->jwt($payload), $this->root['pem'], $this->now)->entries[self::AAGUID]['status']);
    }

    public function testALongDescriptionIsCutToTheColumn(): void {
        $blob = MetadataBlob::verify($this->jwt($this->payload(['description' => str_repeat('é', 400)])), $this->root['pem'], $this->now);

        $this->assertSame(255, mb_strlen($blob->entries[self::AAGUID]['description']));
    }
}
