<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Service\MetadataFailure;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Tests\Support\PasskeyTestCase;
use Eppitnic\Tests\Support\StaticHttpClient;
use Eppitnic\Tests\Support\TestPki;
use RedBeanPHP\R;
use Symfony\Component\Clock\MockClock;

/**
 * Fetching, importing and keeping the MDS3 BLOB, and when what was kept is
 * still good enough to hold a registration to.
 */
final class PasskeyMetadataTest extends PasskeyTestCase
{
    private const ONE = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';
    private const TWO = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000002';

    /** @return string the date $days from now, in UTC */
    private static function day(int $days): string {
        return (new \DateTimeImmutable("+{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    private static function midnight(string $date): \DateTimeImmutable {
        return new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    private function failure(callable $do): MetadataFailure {
        try {
            $do();
        } catch (MetadataFailure $e) {
            return $e;
        }
        $this->fail('nothing was refused');
    }

    private function authenticators(): array {
        return [
            self::ONE => ['description' => 'Key One', 'roots' => [$this->fidoRoot()['pem']]],
            self::TWO => ['description' => 'Key Two', 'statuses' => ['FIDO_CERTIFIED', 'REVOKED']],
        ];
    }

    // -----------------------------------------------------------------
    // importing
    // -----------------------------------------------------------------

    public function testAnImportStoresTheEntriesAndWhichBlobItWas(): void {
        $next = date('Y-m-d', time() + 20 * 86400);

        $result = PasskeyMetadata::import($this->blob($this->authenticators(), 5, $next));

        $this->assertSame(['serial' => 5, 'next_update' => $next, 'entries' => 2], $result);
        $this->assertSame(2, AuthenticatorMetadata::count());
        $one = AuthenticatorMetadata::find(self::ONE);
        $this->assertSame('Key One', $one['description']);
        $this->assertSame('FIDO_CERTIFIED', $one['status']);
        $this->assertSame([TestPki::der($this->fidoRoot())], $one['entry']['attestationRootCertificates']);
        $this->assertSame('REVOKED', AuthenticatorMetadata::find(self::TWO)['status']);
        $this->assertSame(['serial' => 5, 'next_update' => $next], array_intersect_key(AuthenticatorMetadata::blob(), ['serial' => 1, 'next_update' => 1]));
        $this->assertNotNull(AuthenticatorMetadata::blob()['fetched_at']);
    }

    public function testANewerBlobReplacesWhatWasStored(): void {
        PasskeyMetadata::import($this->blob($this->authenticators(), 5));
        $three = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000003';

        PasskeyMetadata::import($this->blob([
            self::ONE => ['description' => 'Key One v2', 'statuses' => ['FIDO_CERTIFIED', 'USER_KEY_PHYSICAL_COMPROMISE']],
            $three    => ['description' => 'Key Three'],
        ], 6));

        $this->assertSame(2, AuthenticatorMetadata::count());
        $this->assertNull(AuthenticatorMetadata::find(self::TWO), 'gone from the BLOB, gone from the table');
        $this->assertSame('Key One v2', AuthenticatorMetadata::find(self::ONE)['description']);
        $this->assertSame('USER_KEY_PHYSICAL_COMPROMISE', AuthenticatorMetadata::find(self::ONE)['status']);
        $this->assertNotNull(AuthenticatorMetadata::find($three));
        $this->assertSame(6, AuthenticatorMetadata::blob()['serial']);
    }

    public function testTheSameBlobAgainChangesNothingButWhenItWasFetched(): void {
        $jwt = $this->blob($this->authenticators(), 5, self::day(10));
        PasskeyMetadata::import($jwt);
        PasskeyMetadata::useClock(new MockClock(new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'))));

        $result = PasskeyMetadata::import($jwt);

        $this->assertSame(2, $result['entries']);
        $this->assertSame(2, AuthenticatorMetadata::count());
        $this->assertSame(
            PasskeyMetadata::now()->format('Y-m-d H:i'),
            substr(AuthenticatorMetadata::blob()['fetched_at'], 0, 16)
        );
    }

    public function testALowerSerialIsARollbackAndLeavesEverythingAlone(): void {
        PasskeyMetadata::import($this->blob($this->authenticators(), 9));

        $failure = $this->failure(fn() => PasskeyMetadata::import($this->blob([self::ONE => ['description' => 'Old']], 8)));

        $this->assertSame(MetadataFailure::ROLLBACK, $failure->reason);
        $this->assertSame(9, AuthenticatorMetadata::blob()['serial']);
        $this->assertSame('Key One', AuthenticatorMetadata::find(self::ONE)['description']);
        $this->assertNotNull(AuthenticatorMetadata::find(self::TWO));
    }

    public function testABadSignatureStoresNothing(): void {
        $jwt = $this->blob($this->authenticators());
        [$header, , $signature] = explode('.', $jwt);
        $forged = explode('.', $this->blob([self::ONE => ['description' => 'Forged']], 50))[1];

        $failure = $this->failure(fn() => PasskeyMetadata::import("{$header}.{$forged}.{$signature}"));

        $this->assertSame(MetadataFailure::SIGNATURE, $failure->reason);
        $this->assertSame(0, AuthenticatorMetadata::count());
        $this->assertNull(AuthenticatorMetadata::blob()['serial']);
    }

    public function testABlobSignedUnderAnotherRootStoresNothing(): void {
        $jwt = $this->blob($this->authenticators());
        PasskeyMetadata::useRoot(TestPki::root('The Real FIDO Root')['pem']);

        $failure = $this->failure(fn() => PasskeyMetadata::import($jwt));

        $this->assertSame(MetadataFailure::CHAIN, $failure->reason);
        $this->assertSame(0, AuthenticatorMetadata::count());
    }

    public function testAStaleBlobStoresNothing(): void {
        $failure = $this->failure(fn() => PasskeyMetadata::import($this->blob($this->authenticators(), 5, date('Y-m-d', time() - 30 * 86400))));

        $this->assertSame(MetadataFailure::STALE, $failure->reason);
        $this->assertSame(0, AuthenticatorMetadata::count());
    }

    public function testWithoutTheRootCertificateNothingIsTrusted(): void {
        $jwt = $this->blob($this->authenticators());
        PasskeyMetadata::useRoot(null);
        $missing = PasskeyMetadata::ROOT_FILE;

        $this->assertFileExists($missing, 'the real FIDO root ships with the code');
        $failure = $this->failure(fn() => PasskeyMetadata::import($jwt));

        $this->assertSame(MetadataFailure::CHAIN, $failure->reason, 'a test signer does not chain to the real root');
    }

    public function testTheBundledRootIsGlobalSignRootR3(): void {
        $parsed = openssl_x509_parse((string) file_get_contents(PasskeyMetadata::ROOT_FILE));

        $this->assertSame('GlobalSign Root CA - R3', $parsed['subject']['OU']);
        $this->assertSame(
            'cbb522d7b7f127ad6a0113865bdf1cd4102e7d0759af635a7cf4720dc963c53b',
            openssl_x509_fingerprint((string) file_get_contents(PasskeyMetadata::ROOT_FILE), 'sha256')
        );
    }

    // -----------------------------------------------------------------
    // fetching
    // -----------------------------------------------------------------

    public function testRefreshFetchesTheFidoUrlAndImports(): void {
        $http = new StaticHttpClient(200, $this->blob($this->authenticators(), 3));
        PasskeyMetadata::useHttpClient($http);

        $result = PasskeyMetadata::refresh();

        $this->assertSame(3, $result['serial']);
        $this->assertCount(1, $http->calls);
        $this->assertSame(['GET', 'https://mds3.fidoalliance.org/'], [$http->calls[0]['method'], $http->calls[0]['url']]);
        $this->assertSame(2, AuthenticatorMetadata::count());
    }

    public function testAFetchThatFailsIsAFailureAndKeepsTheOldData(): void {
        PasskeyMetadata::import($this->blob($this->authenticators(), 5));

        foreach ([
            [500, '', ''],
            [404, 'Not Found', ''],
            [200, '', ''],
            [0, '', 'Could not resolve host'],
        ] as [$status, $body, $error]) {
            PasskeyMetadata::useHttpClient(new StaticHttpClient($status, $body, $error));

            $failure = $this->failure(fn() => PasskeyMetadata::refresh());

            $this->assertSame(MetadataFailure::FETCH, $failure->reason, "{$status}");
        }
        $this->assertStringContainsString('Could not resolve host', $failure->getMessage());
        $this->assertSame(5, AuthenticatorMetadata::blob()['serial']);
        $this->assertSame(2, AuthenticatorMetadata::count());
    }

    public function testAnAnswerThatIsNoBlobIsRefused(): void {
        PasskeyMetadata::useHttpClient(new StaticHttpClient(200, '<html>maintenance</html>'));

        $this->assertSame(MetadataFailure::MALFORMED, $this->failure(fn() => PasskeyMetadata::refresh())->reason);
    }

    // -----------------------------------------------------------------
    // when it is good enough
    // -----------------------------------------------------------------

    public function testWithoutAnyDataItIsMissingAndUnusable(): void {
        $status = PasskeyMetadata::status();

        $this->assertSame('missing', $status['state']);
        $this->assertNull($status['serial']);
        $this->assertFalse($status['overdue']);
        $this->assertFalse(PasskeyMetadata::usable());
    }

    public function testABlobRowWithoutEntriesIsStillMissing(): void {
        AuthenticatorMetadata::saveBlob(5, date('Y-m-d', time() + 86400), date('Y-m-d H:i:s'));

        $this->assertSame('missing', PasskeyMetadata::status()['state']);
        $this->assertFalse(PasskeyMetadata::usable());
    }

    public function testAnInstallationBeforeTheUpgradeHasNoRowAndIsMissing(): void {
        Config::loadForTesting(static::SETTINGS + ['jwt_psk' => 'x']);

        $this->assertSame('missing', PasskeyMetadata::status()['state']);
    }

    public function testFreshDataIsUsableAndStaleOnlyAfterNextUpdateAndTheGrace(): void {
        $next = self::day(5);
        PasskeyMetadata::import($this->blob($this->authenticators(), 5, $next));
        $clock = new MockClock(self::midnight($next));
        PasskeyMetadata::useClock($clock);

        $this->assertSame('ok', PasskeyMetadata::status()['state']);
        $this->assertFalse(PasskeyMetadata::status()['overdue']);
        $this->assertTrue(PasskeyMetadata::usable());

        $clock->modify(self::midnight($next)->modify('+1 day')->format('Y-m-d H:i:s'));
        $this->assertSame('ok', PasskeyMetadata::status()['state'], 'overdue, within the grace');
        $this->assertTrue(PasskeyMetadata::status()['overdue']);
        $this->assertTrue(PasskeyMetadata::usable());

        $clock->modify(self::midnight($next)->modify('+7 days 23:59:59')->format('Y-m-d H:i:s'));
        $this->assertTrue(PasskeyMetadata::usable(), 'the last day of the grace');

        $clock->modify(self::midnight($next)->modify('+8 days')->format('Y-m-d H:i:s'));
        $this->assertSame('stale', PasskeyMetadata::status()['state']);
        $this->assertFalse(PasskeyMetadata::usable());
        $this->assertSame(2, PasskeyMetadata::status()['entries'], 'what was stored stays, for the status of its models');
    }

    public function testAFetchThatFailsLetsTheDataGoStaleOnItsOwn(): void {
        $next = self::day(3);
        PasskeyMetadata::import($this->blob($this->authenticators(), 5, $next));
        PasskeyMetadata::useHttpClient(new StaticHttpClient(0, '', 'timeout'));

        PasskeyMetadata::useClock(new MockClock(self::midnight($next)->modify('+2 days')));
        $this->failure(fn() => PasskeyMetadata::refresh());
        $this->assertTrue(PasskeyMetadata::usable(), 'a day or two of silence does no harm');

        PasskeyMetadata::useClock(new MockClock(self::midnight($next)->modify('+8 days')));
        $this->failure(fn() => PasskeyMetadata::refresh());
        $this->assertFalse(PasskeyMetadata::usable(), 'after a week past nextUpdate it is closed');
    }

    public function testTheSystemClockIsUsedByDefault(): void {
        $this->assertEqualsWithDelta(time(), PasskeyMetadata::now()->getTimestamp(), 2);
        $this->assertSame('UTC', PasskeyMetadata::now()->getTimezone()->getName());
        $this->assertEqualsWithDelta(time(), PasskeyMetadata::clock()->now()->getTimestamp(), 2);
    }
}
