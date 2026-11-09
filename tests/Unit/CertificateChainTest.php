<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\CertificateChain;
use Eppitnic\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Whether a certificate chain leads to a trust anchor, at a given time.
 */
final class CertificateChainTest extends TestCase
{
    private array $root;
    private array $intermediate;
    private array $leaf;
    private \DateTimeImmutable $now;

    protected function setUp(): void {
        $this->root = TestPki::root();
        $this->intermediate = TestPki::intermediate($this->root);
        $this->leaf = TestPki::attestation($this->intermediate);
        $this->now = new \DateTimeImmutable();
    }

    public function testAChainLeadsToItsRoot(): void {
        $this->assertTrue(CertificateChain::anchoredAt([$this->leaf['pem'], $this->intermediate['pem']], $this->root['pem'], $this->now));
    }

    public function testAnIntermediateCanBeTheAnchor(): void {
        $this->assertTrue(CertificateChain::anchoredAt([$this->leaf['pem']], $this->intermediate['pem'], $this->now));
    }

    public function testAChainThatCarriesTheAnchorStopsThere(): void {
        $this->assertTrue(CertificateChain::anchoredAt(
            [$this->leaf['pem'], $this->intermediate['pem'], $this->root['pem']],
            $this->intermediate['pem'],
            $this->now
        ));
    }

    public function testTheEndEntityItselfCanBeTheAnchor(): void {
        $this->assertTrue(CertificateChain::anchoredAt([$this->leaf['pem']], $this->leaf['pem'], $this->now));
    }

    public function testAnotherRootDoesNotAnchor(): void {
        $other = TestPki::root('Other Root');

        $this->assertFalse(CertificateChain::anchoredAt([$this->leaf['pem'], $this->intermediate['pem']], $other['pem'], $this->now));
        $this->assertFalse(CertificateChain::anchoredAt([$this->leaf['pem']], $other['pem'], $this->now));
    }

    public function testAnotherCaWithTheSameNameDoesNotAnchor(): void {
        $lookalike = TestPki::root('Test Root');

        $this->assertFalse(CertificateChain::anchoredAt([$this->leaf['pem'], $this->intermediate['pem']], $lookalike['pem'], $this->now));
    }

    public function testACertificateIsOnlyGoodWithinItsValidity(): void {
        $chain = [$this->leaf['pem'], $this->intermediate['pem']];

        $this->assertFalse(CertificateChain::anchoredAt($chain, $this->root['pem'], $this->now->modify('-2 days')), 'not yet valid');
        $this->assertFalse(CertificateChain::anchoredAt($chain, $this->root['pem'], $this->now->modify('+60 days')), 'expired');
        $this->assertFalse(CertificateChain::anchoredAt([$this->leaf['pem']], $this->leaf['pem'], $this->now->modify('+60 days')), 'an anchor too');
    }

    public function testAnEndEntityDoesNotVouchForAnotherCertificate(): void {
        $below = TestPki::attestation($this->leaf);

        $this->assertFalse(CertificateChain::anchoredAt([$below['pem'], $this->leaf['pem'], $this->intermediate['pem']], $this->root['pem'], $this->now));
    }

    public function testAMissingLinkDoesNotAnchor(): void {
        $this->assertFalse(CertificateChain::anchoredAt([$this->leaf['pem']], $this->root['pem'], $this->now));
    }

    public function testNothingOrGarbageDoesNotAnchor(): void {
        $this->assertFalse(CertificateChain::anchoredAt([], $this->root['pem'], $this->now));
        $this->assertFalse(CertificateChain::anchoredAt(['not a certificate'], $this->root['pem'], $this->now));
        $this->assertFalse(CertificateChain::anchoredAt([$this->leaf['pem']], 'not a certificate', $this->now));
    }

    public function testPemAndBase64DerAreBothTaken(): void {
        $this->assertTrue(CertificateChain::anchoredAt(
            [TestPki::der($this->leaf), TestPki::der($this->intermediate)],
            TestPki::der($this->root),
            $this->now
        ));
    }

    public function testTextAroundThePemIsIgnored(): void {
        $this->assertTrue(CertificateChain::anchoredAt([$this->leaf['pem']], "# a note\n" . $this->intermediate['pem'], $this->now));
    }
}
