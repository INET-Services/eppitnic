<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\DomainService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Glue is judged on the punycode form of both names, whichever form the
 * domain or the nameserver arrives in.
 */
final class DomainServiceGlueTest extends TestCase
{
    #[DataProvider('idnPairs')]
    public function testGlueIsAcceptedUnderAnIdnDomainInEitherForm(string $domain, string $host): void {
        $entries = [['name' => $host, 'ip' => ['192.0.2.1']]];

        $this->assertNull(DomainService::glueError($domain, $entries));
    }

    public static function idnPairs(): array {
        return [
            'punycode host, unicode domain' => ['città.it', 'ns1.xn--citt-3na.it'],
            'unicode host, punycode domain' => ['xn--citt-3na.it', 'ns1.città.it'],
            'trailing dot'                  => ['città.it', 'NS1.Città.it.'],
        ];
    }

    public function testGlueOutsideAnIdnDomainIsStillRefused(): void {
        $error = DomainService::glueError('città.it', [['name' => 'ns1.example.net', 'ip' => ['192.0.2.1']]]);

        $this->assertStringContainsString('only allowed for nameservers under', (string) $error);
    }
}
