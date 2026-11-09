<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\Validate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validate::isDomain(): a .it name of one label, in Unicode or punycode, in
 * any case. The registry stays the judge of its exact character set.
 */
final class ValidateDomainTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testAccepts(string $name): void {
        $this->assertTrue(Validate::isDomain($name), $name);
    }

    public static function accepted(): array {
        return [
            'plain'                       => ['example.it'],
            'digits and hyphens'          => ['my-site-2.it'],
            'hyphens in positions 3-4'    => ['ab--cd.it'],
            'hyphens in positions 3-4, IDN' => ['àb--cd.it'],
            'one character'               => ['a.it'],
            'upper case'                  => ['EXAMPLE.IT'],
            'longest label'               => [str_repeat('a', 63) . '.it'],
            'sharp s'                     => ['sesamstraße.it'],
            'sharp s, upper case'         => ['SESAMSTRAßE.IT'],
            'accented'                    => ['città.it'],
            'accented, upper case'        => ['CITTÀ.IT'],
            'decomposed accent'           => ["citta\u{0300}.it"],
            'umlaut'                      => ['bäckerei-gasser.it'],
            'punycode'                    => ['xn--sesamstrae-e4a.it'],
            'upper-case punycode'         => ['XN--CITT-3NA.IT'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefuses(string $name): void {
        $this->assertFalse(Validate::isDomain($name), $name);
    }

    public static function refused(): array {
        return [
            'empty'                       => [''],
            'no label'                    => ['.it'],
            'another TLD'                 => ['example.com'],
            'no TLD'                      => ['example'],
            'three labels'                => ['www.example.it'],
            'three labels, IDN'           => ['www.città.it'],
            'empty label'                 => ['ex..it'],
            'leading hyphen'              => ['-example.it'],
            'trailing hyphen'             => ['example-.it'],
            'label of 64'                 => [str_repeat('a', 64) . '.it'],
            'underscore'                  => ['ex_ample.it'],
            'space'                       => ['ex ample.it'],
            'Cyrillic'                    => ['пример.it'],
            'Cyrillic look-alike of a'    => ["\u{0430}pple.it"],
            'Greek'                       => ['σασ.it'],
            'Han'                         => ['日本.it'],
            'emoji'                       => ['😀.it'],
            'full-width Latin'            => ['ｓｅｓａｍ.it'],
            'ligature'                    => ['ﬁsh.it'],
            'invalid punycode'            => ['xn--zz.it'],
            'punycode of a Cyrillic name' => ['xn--e1afmkfd.it'],
            'invalid UTF-8'               => ["a\xc3.it"],
        ];
    }
}
