<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Persistence\PasskeyExists;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\WebAuthnAssertion;
use Eppitnic\Service\WebAuthnRegistration;
use Eppitnic\Tests\Support\PasskeyTestCase;
use RedBeanPHP\R;

/**
 * The `passkeys` table through Persistence\Passkeys: raw-byte credential ids,
 * the length limits, and a credential id that can be registered only once.
 */
final class PasskeysStorageTest extends PasskeyTestCase
{
    private function registration(?string $credentialId = null, array $overrides = []): WebAuthnRegistration {
        return new WebAuthnRegistration(
            credentialId: $credentialId ?? random_bytes(32),
            source: '{"publicKeyCredentialId":"x"}',
            aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            signCount: 0,
            backupEligible: false,
            backupState: false,
            attestationFormat: $overrides['format'] ?? 'none',
            attestationVerified: false,
            transports: $overrides['transports'] ?? ['usb', 'nfc'],
        );
    }

    protected function setUp(): void {
        parent::setUp();
        $this->user(5);
        $this->user(6, 'bob');
    }

    public function testAddStoresTheKeyAndFindsItByItsRawId(): void {
        $id = "\x00\xff\x80binary\x00id\xfe";

        $rowId = Passkeys::add(5, $this->registration($id), 'Laptop');
        $row = Passkeys::findByCredentialId($id);

        $this->assertSame($rowId, (int) $row['id']);
        $this->assertSame($id, $row['credential_id']);
        $this->assertSame(5, (int) $row['user_id']);
        $this->assertSame('Laptop', $row['name']);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $row['aaguid']);
        $this->assertSame('["usb","nfc"]', $row['transports']);
        $this->assertSame(0, (int) $row['sign_count']);
        $this->assertNull($row['last_used_at']);
        $this->assertNotNull($row['created_at']);
    }

    public function testNoTransportsStoresNull(): void {
        $id = Passkeys::add(5, $this->registration(null, ['transports' => []]), 'Key');

        $this->assertNull(Passkeys::find($id)['transports']);
    }

    public function testAnUnknownIdFindsNothing(): void {
        $this->assertNull(Passkeys::findByCredentialId('nope'));
        $this->assertNull(Passkeys::findByCredentialId(''));
        $this->assertNull(Passkeys::findByCredentialId(str_repeat('x', 2000)));
        $this->assertNull(Passkeys::find(999));
    }

    public function testIdsThatDifferOnlyInCaseAreDifferentKeys(): void {
        Passkeys::add(5, $this->registration('AbC'), 'Upper');
        Passkeys::add(5, $this->registration('abc'), 'Lower');

        $this->assertSame('Upper', Passkeys::findByCredentialId('AbC')['name']);
        $this->assertSame('Lower', Passkeys::findByCredentialId('abc')['name']);
    }

    // -----------------------------------------------------------------
    // one credential id, once
    // -----------------------------------------------------------------

    public function testTheSameCredentialIdIsRefusedForTheSameUser(): void {
        $id = random_bytes(32);
        Passkeys::add(5, $this->registration($id), 'First');

        $this->expectException(PasskeyExists::class);
        Passkeys::add(5, $this->registration($id), 'Again');
    }

    public function testAndForAnotherUser(): void {
        $id = random_bytes(32);
        Passkeys::add(5, $this->registration($id), 'Alice');

        try {
            Passkeys::add(6, $this->registration($id), 'Bob');
            $this->fail('accepted');
        } catch (PasskeyExists) {
            $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
            $this->assertSame(5, (int) Passkeys::findByCredentialId($id)['user_id']);
        }
    }

    public function testTheTableItselfRefusesADuplicate(): void {
        $id = random_bytes(32);
        Passkeys::add(5, $this->registration($id), 'First');

        $this->expectException(\Throwable::class);
        R::exec(
            "INSERT INTO passkeys (user_id, credential_id, source, aaguid, attestation_format, name, created_at)
             VALUES (6, ?, '{}', 'x', 'none', 'Dup', '2026-01-01 00:00:00')",
            [$id]
        );
    }

    // -----------------------------------------------------------------
    // what fits
    // -----------------------------------------------------------------

    public function testACredentialIdOf1023BytesFitsAndOf1024DoesNot(): void {
        Passkeys::add(5, $this->registration(random_bytes(1023)), 'Long');

        $this->expectException(\InvalidArgumentException::class);
        Passkeys::add(5, $this->registration(random_bytes(1024)), 'Too long');
    }

    public function testAnEmptyCredentialIdIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        Passkeys::add(5, $this->registration(''), 'Empty');
    }

    public function testANameOf64CharactersFitsAndOf65DoesNot(): void {
        Passkeys::add(5, $this->registration(), str_repeat('ü', 64));

        $this->expectException(\InvalidArgumentException::class);
        Passkeys::add(5, $this->registration(), str_repeat('ü', 65));
    }

    public function testANameIsRequired(): void {
        $this->expectException(\InvalidArgumentException::class);
        Passkeys::add(5, $this->registration(), '   ');
    }

    public function testAnAttestationFormatThatDoesNotFitIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        Passkeys::add(5, $this->registration(null, ['format' => str_repeat('f', 33)]), 'Key');
    }

    public function testNothingIsStoredWhenARefusalComes(): void {
        try {
            Passkeys::add(5, $this->registration(random_bytes(1024)), 'Too long');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
    }

    // -----------------------------------------------------------------
    // reading, changing, removing
    // -----------------------------------------------------------------

    public function testAUsersKeysAreListedOldestFirstAndCounted(): void {
        $first = Passkeys::add(5, $this->registration(), 'First');
        $second = Passkeys::add(5, $this->registration(), 'Second');
        Passkeys::add(6, $this->registration(), 'Bobs');

        $this->assertSame([$first, $second], array_map(fn(array $r): int => (int) $r['id'], Passkeys::listForUser(5)));
        $this->assertSame(2, Passkeys::countForUser(5));
        $this->assertSame(1, Passkeys::countForUser(6));
        $this->assertSame(0, Passkeys::countForUser(7));
        $this->assertSame([], Passkeys::listForUser(7));
    }

    public function testRenameChangesOnlyTheName(): void {
        $id = Passkeys::add(5, $this->registration(), 'Old');

        $this->assertTrue(Passkeys::rename($id, '  New name  '));
        $this->assertFalse(Passkeys::rename(999, 'Nobody'));

        $this->assertSame('New name', Passkeys::find($id)['name']);
    }

    public function testRenameKeepsTheLimits(): void {
        $id = Passkeys::add(5, $this->registration(), 'Old');

        $this->expectException(\InvalidArgumentException::class);
        Passkeys::rename($id, str_repeat('x', 65));
    }

    public function testRenameRefusesAnEmptyName(): void {
        $id = Passkeys::add(5, $this->registration(), 'Old');

        $this->expectException(\InvalidArgumentException::class);
        Passkeys::rename($id, '');
    }

    public function testUpdateUsageRecordsTheSignInAndTruncatesAnOverlongAddress(): void {
        $id = Passkeys::add(5, $this->registration(), 'Key');

        $this->assertTrue(Passkeys::updateUsage($id, new WebAuthnAssertion(3, true, true, true), str_repeat('9', 60)));

        $row = Passkeys::find($id);
        $this->assertSame(3, (int) $row['sign_count']);
        $this->assertSame(1, (int) $row['backup_state']);
        $this->assertSame(45, strlen($row['last_used_ip']));
        $this->assertNotNull($row['last_used_at']);
    }

    public function testUpdateUsageOnAGoneKeyIsFalse(): void {
        $this->assertFalse(Passkeys::updateUsage(999, new WebAuthnAssertion(1, true, false, false), null));
    }

    public function testDeleteRemovesOneKey(): void {
        $keep = Passkeys::add(5, $this->registration(), 'Keep');
        $drop = Passkeys::add(5, $this->registration(), 'Drop');

        $this->assertTrue(Passkeys::delete($drop));
        $this->assertFalse(Passkeys::delete($drop));
        $this->assertNull(Passkeys::find($drop));
        $this->assertNotNull(Passkeys::find($keep));
    }

    public function testDeleteAllForUserRemovesOnlyThatUsersKeys(): void {
        Passkeys::add(5, $this->registration(), 'One');
        Passkeys::add(5, $this->registration(), 'Two');
        Passkeys::add(6, $this->registration(), 'Bobs');

        $this->assertSame(2, Passkeys::deleteAllForUser(5));
        $this->assertSame(0, Passkeys::countForUser(5));
        $this->assertSame(1, Passkeys::countForUser(6));
        $this->assertSame(0, Passkeys::deleteAllForUser(5));
    }

    // -----------------------------------------------------------------
    // the user handle
    // -----------------------------------------------------------------

    public function testTheHandleIsMadeOnFirstUseAndKept(): void {
        $first = Passkeys::handleFor(5);

        $this->assertSame(32, strlen($first));
        $this->assertSame($first, Passkeys::handleFor(5));
        $this->assertSame($first, R::getCell('SELECT webauthn_handle FROM users WHERE id = 5'));
    }

    public function testHandlesDifferBetweenUsers(): void {
        $this->assertNotSame(Passkeys::handleFor(5), Passkeys::handleFor(6));
    }

    public function testAHandleNeverChangesOnceMade(): void {
        $handle = Passkeys::handleFor(5);
        R::exec('UPDATE users SET webauthn_handle = ? WHERE id = 5 AND webauthn_handle IS NULL', [random_bytes(32)]);

        $this->assertSame($handle, Passkeys::handleFor(5));
    }

    public function testNoHandleForNoUser(): void {
        $this->expectException(\InvalidArgumentException::class);
        Passkeys::handleFor(999);
    }
}
