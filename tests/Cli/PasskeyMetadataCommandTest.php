<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\PasskeyMetadataCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Tests\Support\PasskeyCliTestCase;
use Eppitnic\Tests\Support\StaticHttpClient;
use Eppitnic\Tests\Support\TestPki;

/**
 * `passkey metadata`: fetch the FIDO BLOB, or import one downloaded elsewhere
 * (--file), under the same checks.
 */
final class PasskeyMetadataCommandTest extends PasskeyCliTestCase
{
    private const ONE = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';

    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void {
        array_map('unlink', $this->files);
        parent::tearDown();
    }

    private function file(string $jwt): string {
        $path = tempnam(sys_get_temp_dir(), 'mds');
        file_put_contents($path, $jwt);
        return $this->files[] = $path;
    }

    private function authenticators(): array {
        return [self::ONE => ['description' => 'Key One', 'roots' => [$this->fidoRoot()['pem']]]];
    }

    public function testItIsRegisteredAsPasskeyMetadata(): void {
        $this->assertSame(PasskeyMetadataCommand::class, Application::commands()['passkey metadata']);
        $this->assertStringContainsString('passkey metadata', (new Application())->overview());
        $this->assertArrayHasKey('file=', (new PasskeyMetadataCommand([]))->options());
    }

    public function testAFileIsImportedAndTheEntriesStored(): void {
        $path = $this->file($this->blob($this->authenticators(), 4, '2099-01-01'));

        [$code, $output] = $this->exec(PasskeyMetadataCommand::class, ["--file={$path}"]);

        $this->assertSame(0, $code, $this->errorText());
        $this->assertStringContainsString('metadata BLOB 4: 1 authenticator(s), next update 2099-01-01', $output);
        $this->assertSame('Key One', AuthenticatorMetadata::find(self::ONE)['description']);
        $this->assertSame(4, AuthenticatorMetadata::blob()['serial']);
    }

    public function testByHandItRunsWhilePasskeysAreOff(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));
        $path = $this->file($this->blob($this->authenticators(), 4));

        [$code] = $this->exec(PasskeyMetadataCommand::class, ["--file={$path}"]);

        $this->assertSame(0, $code, $this->errorText());
        $this->assertSame(4, AuthenticatorMetadata::blob()['serial']);
    }

    public function testJsonCarriesTheResult(): void {
        $path = $this->file($this->blob($this->authenticators(), 4));

        [, $output] = $this->exec(PasskeyMetadataCommand::class, ["--file={$path}", '--json']);

        $result = json_decode($output, true)[0];
        $this->assertSame([4, 1], [$result['serial'], $result['entries']]);
    }

    public function testAnUnreadableFileIsAUsageError(): void {
        foreach (['--file=/no/such/file', '--file='] as $argument) {
            try {
                $this->exec(PasskeyMetadataCommand::class, [$argument]);
                $this->fail("accepted {$argument}");
            } catch (UsageError $e) {
                $this->assertStringContainsString('cannot read', $e->getMessage());
            }
        }
    }

    public function testABadSignatureIsRefusedWithItsReasonAndStoresNothing(): void {
        [$header, $payload, $signature] = explode('.', $this->blob($this->authenticators(), 4));
        $path = $this->file("{$header}.{$payload}x.{$signature}");

        [$code, $output] = $this->exec(PasskeyMetadataCommand::class, ["--file={$path}"]);

        $this->assertSame(METADATA_REFUSED, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('metadata not updated (signature)', $this->errorText());
        $this->assertSame(0, AuthenticatorMetadata::count());
    }

    public function testABlobOfAnotherRootIsRefused(): void {
        $path = $this->file($this->blob($this->authenticators()));
        PasskeyMetadata::useRoot(TestPki::root('Not FIDO')['pem']);

        [$code] = $this->exec(PasskeyMetadataCommand::class, ["--file={$path}"]);

        $this->assertSame(METADATA_REFUSED, $code);
        $this->assertStringContainsString('(chain)', $this->errorText());
        $this->assertSame(0, AuthenticatorMetadata::count());
    }

    public function testAStaleBlobIsRefused(): void {
        $path = $this->file($this->blob($this->authenticators(), 4, date('Y-m-d', time() - 40 * 86400)));

        [$code] = $this->exec(PasskeyMetadataCommand::class, ["--file={$path}"]);

        $this->assertSame(METADATA_REFUSED, $code);
        $this->assertStringContainsString('(stale)', $this->errorText());
    }

    public function testAnOlderSerialIsARollbackAndRefused(): void {
        $this->exec(PasskeyMetadataCommand::class, ['--file=' . $this->file($this->blob($this->authenticators(), 9))]);

        [$code] = $this->exec(PasskeyMetadataCommand::class, ['--file=' . $this->file($this->blob($this->authenticators(), 8))]);

        $this->assertSame(METADATA_REFUSED, $code);
        $this->assertStringContainsString('(rollback)', $this->errorText());
        $this->assertSame(9, AuthenticatorMetadata::blob()['serial']);
    }

    public function testWithoutAFileItFetchesFromFido(): void {
        $http = new StaticHttpClient(200, $this->blob($this->authenticators(), 6));
        PasskeyMetadata::useHttpClient($http);

        [$code, $output] = $this->exec(PasskeyMetadataCommand::class, []);

        $this->assertSame(0, $code, $this->errorText());
        $this->assertStringContainsString('metadata BLOB 6', $output);
        $this->assertSame('https://mds3.fidoalliance.org/', $http->calls[0]['url']);
    }

    public function testAFetchThatFailsHasItsOwnExitCodeAndKeepsWhatWasStored(): void {
        $this->exec(PasskeyMetadataCommand::class, ['--file=' . $this->file($this->blob($this->authenticators(), 5))]);
        PasskeyMetadata::useHttpClient(new StaticHttpClient(0, '', 'Could not resolve host'));

        [$code, $output] = $this->exec(PasskeyMetadataCommand::class, []);

        $this->assertSame(METADATA_FETCH_FAILED, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('metadata not updated (fetch)', $this->errorText());
        $this->assertStringContainsString('Could not resolve host', $this->errorText());
        $this->assertSame(5, AuthenticatorMetadata::blob()['serial']);
    }
}
