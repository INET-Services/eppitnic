<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DomainCreateCommand;
use Eppitnic\Cli\Command\DomainImportCommand;
use Eppitnic\Cli\Command\DomainTransferCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Support\PasswordGenerator;
use Eppitnic\Tests\Support\EppTestCase;

/**
 * The verbs that share DomainService with the REST API. Everything runs under
 * --dry-run, which is what makes it possible: create and transfer change
 * registry state, so the honest test is to look at what they would send.
 */
final class CreateTransferCommandTest extends EppTestCase
{
    private function preview(\Eppitnic\Cli\Command $command): string {
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $command->run();
        return (string) ob_get_clean();
    }

    /**
     * An available name takes the create branch, carrying every field the
     * options supplied except the admin and tech contacts, which are ignored.
     */
    public function testCreateBuildsADomainCreate(): void {
        $output = $this->preview(new DomainCreateCommand([
            '--dry-run', '--registrant=REGI1234REGI5678', '--admin=ADMIN123ADMIN456',
            '--tech=TECH1234TECH5678:TECH9012TECH3456',
            '--ns=ns1.example.it:ns2.example.it',
            'example-one.it',
        ]));

        $this->assertStringContainsString('<domain:create', $output);
        $this->assertStringContainsString('<domain:registrant>REGI1234REGI5678</domain:registrant>', $output);
        $this->assertStringNotContainsString('<domain:contact', $output);
        $this->assertStringNotContainsString('ADMIN123ADMIN456', $output);
        $this->assertStringNotContainsString('TECH1234TECH5678', $output);
        $this->assertStringContainsString('<domain:hostName>ns2.example.it</domain:hostName>', $output);
    }

    /**
     * The registry caps the nameservers at six; anything beyond is dropped
     * rather than sent and refused. --tech is accepted and sends nothing.
     */
    public function testCreateCapsNameserversAtSixAndIgnoresTech(): void {
        $tech = implode(':', array_map(fn($i) => "TECH{$i}", range(1, 8)));
        $ns = implode(':', array_map(fn($i) => "ns{$i}.example.it", range(1, 8)));

        $output = $this->preview(new DomainCreateCommand([
            '--dry-run', '--registrant=REGI1234REGI5678', "--tech={$tech}", "--ns={$ns}", 'example-one.it',
        ]));

        $this->assertSame(0, substr_count($output, '<domain:contact'));
        $this->assertSame(6, substr_count($output, '<domain:hostName>'));
        $this->assertStringNotContainsString('ns7.example.it', $output);
    }

    public function testCreateGeneratesAnAuthinfoWhenNoneGiven(): void {
        $output = $this->preview(new DomainCreateCommand([
            '--dry-run', '--registrant=REGI1234REGI5678', 'example-one.it',
        ]));

        $this->assertSame(1, preg_match('#<domain:pw>(.*)</domain:pw>#', $output, $m), 'no authinfo was generated');

        // whatever PasswordGenerator draws from, not a shape frozen here: the
        // assertion is that this is the 16-character credential the codebase
        // generates. An authinfo is unrestricted, so this pins our choice
        $this->assertSame(16, strlen($m[1]));
        $this->assertSame('', preg_replace(
            '/[' . preg_quote(PasswordGenerator::SAFE_CHARSET, '/') . ']/', '', $m[1]
        ), 'the authinfo used characters outside the safe set');
    }

    public function testCreateRequiresARegistrant(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('no registrant');
        (new DomainCreateCommand(['--dry-run', 'example-one.it']))->run();
    }

    /**
     * The ';'-separated file format CLI/domain-DoCreate.php accepted, where a
     * bulk registration gives each domain its own contacts and nameservers.
     */
    public function testCreateReadsPerDomainRowsFromAFile(): void {
        $file = tempnam(sys_get_temp_dir(), 'eppitnic-rows-');
        file_put_contents($file, "example-one.it;REGI1234REGI5678;TECH1234TECH5678;ns1.example.it:ns2.example.it\n");

        $output = $this->preview(new DomainCreateCommand(['--dry-run', "--file={$file}"]));
        unlink($file);

        $this->assertStringContainsString('<domain:registrant>REGI1234REGI5678</domain:registrant>', $output);
        $this->assertStringContainsString('<domain:hostName>ns1.example.it</domain:hostName>', $output);
    }

    /**
     * @return array<string, array{0: string}> operation, also the op attribute
     */
    public static function transferOperations(): array {
        return [
            'request' => ['request'],
            'approve' => ['approve'],
            'reject'  => ['reject'],
            'cancel'  => ['cancel'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transferOperations')]
    public function testTransferSendsTheRightOperation(string $operation): void {
        $output = $this->preview(new DomainTransferCommand([
            '--dry-run', '--authinfo=SECRET1234567890', '--registrant=REGI1234REGI5678', $operation, 'example-one.it',
        ]));

        $this->assertStringContainsString("<transfer op=\"{$operation}\">", $output);
        $this->assertStringContainsString('<domain:name>example-one.it</domain:name>', $output);
    }

    public function testTransferRequestRequiresAnAuthinfo(): void {
        $this->expectException(UsageError::class);
        (new DomainTransferCommand(['--dry-run', '--registrant=REGI1234REGI5678', 'request', 'example-one.it']))->run();
    }

    public function testTransferRequestRequiresARegistrant(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('no registrant');
        (new DomainTransferCommand(['--dry-run', '--authinfo=SECRET1234567890', 'request', 'example-one.it']))->run();
    }

    public function testTransferOtherOperationsNeedNoRegistrant(): void {
        $output = $this->preview(new DomainTransferCommand([
            '--dry-run', '--authinfo=SECRET1234567890', 'approve', 'example-one.it',
        ]));

        $this->assertStringContainsString('<transfer op="approve">', $output);
    }

    public function testTransferRejectsAnUnknownOperation(): void {
        $this->expectException(UsageError::class);
        (new DomainTransferCommand(['--dry-run', 'sideways', 'example-one.it']))->run();
    }

    /**
     * Per-domain authinfo, for the common case of transferring in a batch of
     * domains that each have their own code.
     */
    public function testTransferTakesAuthinfoPerRow(): void {
        $output = $this->preview(new DomainTransferCommand([
            '--dry-run', '--registrant=REGI1234REGI5678', 'request', 'example-one.it;CODEONE1234567890',
        ]));

        $this->assertStringContainsString('<domain:pw>CODEONE1234567890</domain:pw>', $output);
    }

    /**
     * set-owner builds its requests from registry data a dry run cannot read,
     * so a preview would show invented contents.
     */
    public function testSetOwnerRejectsDryRun(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('does not apply to set-owner');
        (new \Eppitnic\Cli\Command\DomainSetOwnerCommand(['--dry-run', '--new-reseller=2', 'example-one.it']))->run();
    }

    public function testSetOwnerRequiresANewOwner(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('--new-reseller');
        (new \Eppitnic\Cli\Command\DomainSetOwnerCommand(['example-one.it']))->run();
    }

    /**
     * Import only writes locally, so there is no request a preview could show
     * -- saying so is better than printing nothing and looking broken.
     */
    public function testImportRejectsDryRun(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('does not apply to import');
        (new DomainImportCommand(['--dry-run', 'example-one.it']))->run();
    }
}
