<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\AttestationTrust;
use Eppitnic\Tests\Support\PasskeyTestCase;
use Eppitnic\Tests\Support\TestPki;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\TrustPath\CertificateTrustPath;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * When an attestation statement counts as proof of the model it claims: only
 * with a chain to a root FIDO lists for that AAGUID, of a type FIDO lists.
 */
final class AttestationTrustTest extends PasskeyTestCase
{
    private const AAGUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private array $root;
    private array $intermediate;
    private array $leaf;
    private \DateTimeImmutable $now;

    protected function setUp(): void {
        parent::setUp();
        $this->root = TestPki::root('Vendor Root');
        $this->intermediate = TestPki::intermediate($this->root);
        $this->leaf = TestPki::attestation($this->intermediate, self::AAGUID);
        $this->now = new \DateTimeImmutable();
    }

    private function statement(string $type = AttestationStatement::TYPE_BASIC, string $format = 'packed', ?array $chain = null): AttestationStatement {
        return AttestationStatement::create(
            $format,
            [],
            $type,
            CertificateTrustPath::create(array_column($chain ?? [$this->leaf, $this->intermediate], 'pem'))
        );
    }

    private function listed(array $roots, array $entry = []): void {
        $this->metadata([self::AAGUID => $entry + ['attestationRootCertificates' => array_map(TestPki::der(...), $roots), 'attestationTypes' => ['basic_full']]]);
    }

    private function anchored(?AttestationStatement $statement = null, string $aaguid = self::AAGUID, ?\DateTimeImmutable $at = null): bool {
        return AttestationTrust::anchored($statement ?? $this->statement(), $aaguid, $at ?? $this->now);
    }

    public function testAChainToARootListedForTheAaguidIsAnchored(): void {
        $this->listed([$this->root]);

        $this->assertTrue($this->anchored());
    }

    public function testAnIntermediateListedAsTheRootAnchorsToo(): void {
        $this->listed([$this->intermediate]);

        $this->assertTrue($this->anchored());
        $this->assertTrue($this->anchored($this->statement(chain: [$this->leaf])));
    }

    public function testAnyOfSeveralRootsWill(): void {
        $this->listed([TestPki::root('Another'), $this->root]);

        $this->assertTrue($this->anchored());
    }

    public function testTheAaguidIsMatchedWhateverItsCase(): void {
        $this->listed([$this->root]);

        $this->assertTrue($this->anchored(aaguid: strtoupper(self::AAGUID)));
    }

    public function testAChainToAnotherRootIsNot(): void {
        $this->listed([TestPki::root('Another')]);

        $this->assertFalse($this->anchored());
    }

    public function testARootListedForAnotherAaguidDoesNotHelp(): void {
        $this->listed([$this->root]);

        $this->assertFalse($this->anchored(aaguid: '11111111-2222-4333-8444-555555555555'));
    }

    public function testAnAaguidFidoDoesNotListIsNotAnchored(): void {
        $this->metadata();

        $this->assertFalse($this->anchored());
    }

    public function testAnEntryWithoutRootsAnchorsNothing(): void {
        $this->listed([]);

        $this->assertFalse($this->anchored());
    }

    public function testAnAttestationTypeFidoDoesNotListIsNotAnchored(): void {
        $this->listed([$this->root], ['attestationTypes' => ['basic_surrogate']]);

        $this->assertFalse($this->anchored());
    }

    public function testNoListedTypeMeansAnyType(): void {
        $this->listed([$this->root], ['attestationTypes' => []]);

        $this->assertTrue($this->anchored());
    }

    public function testEachLibraryTypeHasItsMdsName(): void {
        $this->listed([$this->root], ['attestationTypes' => ['attca']]);
        $this->assertTrue($this->anchored($this->statement(AttestationStatement::TYPE_ATTCA)));
        $this->assertFalse($this->anchored($this->statement(AttestationStatement::TYPE_BASIC)));

        $this->listed([$this->root], ['attestationTypes' => ['anonca']]);
        $this->assertTrue($this->anchored($this->statement(AttestationStatement::TYPE_ANONCA)));
    }

    public function testNoneAndSelfAttestationProveNothing(): void {
        $this->listed([$this->root], ['attestationTypes' => []]);

        foreach ([AttestationStatement::TYPE_NONE, AttestationStatement::TYPE_SELF] as $type) {
            $this->assertFalse($this->anchored(AttestationStatement::create('packed', [], $type, EmptyTrustPath::create())), $type);
            $this->assertFalse($this->anchored($this->statement($type)), "{$type} with a chain");
        }
    }

    public function testFidoU2fHasNoAaguidToAnchorTo(): void {
        $this->listed([$this->root]);

        $this->assertTrue($this->anchored($this->statement(format: 'packed')));
        $this->assertFalse($this->anchored($this->statement(format: 'fido-u2f')));
    }

    public function testTheAllZeroAaguidIsNeverAnchored(): void {
        $nil = '00000000-0000-0000-0000-000000000000';
        $this->metadata([$nil => ['attestationRootCertificates' => [TestPki::der($this->root)]]]);

        $this->assertFalse($this->anchored(aaguid: $nil));
    }

    public function testACertificateOutsideItsValidityIsNotAnchored(): void {
        $this->listed([$this->root]);

        $this->assertFalse($this->anchored(at: $this->now->modify('+60 days')));
        $this->assertFalse($this->anchored(at: $this->now->modify('-3 days')));
    }

    public function testACertificateOfAnotherCaWithTheSameNamesIsNot(): void {
        $lookalike = TestPki::attestation(TestPki::intermediate(TestPki::root('Vendor Root')), self::AAGUID);
        $this->listed([$this->root]);

        $this->assertFalse($this->anchored($this->statement(chain: [$lookalike])));
    }
}
