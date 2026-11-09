<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\Idn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Names in Unicode and punycode form (UTS #46, non-transitional), and what
 * Idn::validate() reports about them.
 */
final class IdnTest extends TestCase
{
    // -----------------------------------------------------------------
    // normalize()
    // -----------------------------------------------------------------

    #[DataProvider('normalized')]
    public function testNormalize(string $name, string $expected): void {
        $this->assertSame($expected, Idn::normalize($name));
    }

    public static function normalized(): array {
        return [
            'ASCII is lower-cased'                  => ['Example.COM', 'example.com'],
            'Unicode is lower-cased'                => ['CITTÀ.IT', 'città.it'],
            'decomposed becomes composed (NFC)'     => ["cafe\u{0301}.it", 'café.it'],
            'punycode is decoded'                   => ['xn--citt-3na.it', 'città.it'],
            'upper-case punycode is decoded'        => ['XN--CITT-3NA.IT', 'città.it'],
            'each label on its own'                 => ['ns1.xn--citt-3na.it', 'ns1.città.it'],
            'a name mixing both forms'              => ['città.xn--citt-3na.it', 'città.città.it'],
            'trailing dot stays'                    => ['xn--citt-3na.it.', 'città.it.'],
            'sharp s stays'                         => ['xn--strae-oqa.it', 'straße.it'],
            'capital sharp s becomes sharp s'       => ['STRAẞE.it', 'straße.it'],
            'ss is not sharp s'                     => ['STRASSE.it', 'strasse.it'],
            'empty'                                 => ['', ''],
            'invalid UTF-8 comes back unchanged'    => ["a\xc3.it", "a\xc3.it"],
        ];
    }

    /**
     * A label is decoded only if encoding the result gives the label back.
     */
    #[DataProvider('labelsThatStayAsTyped')]
    public function testNormalizeLeavesALabelThatDoesNotRoundTrip(string $name): void {
        $this->assertSame($name, Idn::normalize($name));
    }

    public static function labelsThatStayAsTyped(): array {
        return [
            'invalid punycode'                  => ['xn--zz.it'],
            'punycode that decodes to nothing'  => ['xn--a.it'],
            'ASCII in disguise'                 => ['xn--abc-.it'],
            'not in NFC once decoded'           => ['xn--e-xbb.it'],
            'bidi rule broken once decoded'     => ['xn--ab-vld.it'],
        ];
    }

    public function testNormalizeDecodesOnlyTheLabelsThatRoundTrip(): void {
        $this->assertSame('ns1.xn--zz.città.it', Idn::normalize('NS1.XN--ZZ.xn--citt-3na.IT'));
    }

    public function testNormalizeDecodesALabelWhoseOnlyFaultsValidateReports(): void {
        $this->assertSame('-à.it', Idn::normalize('xn----sfa.it'));
        $this->assertSame('àb--cd.it', Idn::normalize('xn--b--cd-qqa.it'));
    }

    // -----------------------------------------------------------------
    // ace()
    // -----------------------------------------------------------------

    #[DataProvider('encoded')]
    public function testAce(string $name, string $expected): void {
        $this->assertSame($expected, Idn::ace($name));
    }

    public static function encoded(): array {
        return [
            'ASCII is lower-cased'              => ['NS1.Example.NET', 'ns1.example.net'],
            'Unicode'                           => ['città.it', 'xn--citt-3na.it'],
            'upper case'                        => ['CITTÀ.IT', 'xn--citt-3na.it'],
            'decomposed'                        => ["citta\u{0300}.it", 'xn--citt-3na.it'],
            'punycode stays'                    => ['xn--citt-3na.it', 'xn--citt-3na.it'],
            'upper-case punycode'               => ['XN--CITT-3NA.IT', 'xn--citt-3na.it'],
            'mixed name'                        => ['ns1.città.it', 'ns1.xn--citt-3na.it'],
            'trailing dot stays'                => ['città.it.', 'xn--citt-3na.it.'],
            'sharp s is kept, not ss'           => ['straße.it', 'xn--strae-oqa.it'],
            'capital sharp s'                   => ['STRAẞE.it', 'xn--strae-oqa.it'],
            'ss stays ss'                       => ['strasse.it', 'strasse.it'],
            'ideographic full stop is a dot'    => ['città。it', 'xn--citt-3na.it'],
            'empty'                             => ['', ''],
        ];
    }

    #[DataProvider('faultsValidateReports')]
    public function testAceStillEncodesWhatValidateRefuses(string $name, string $expected): void {
        $this->assertSame($expected, Idn::ace($name));
    }

    public static function faultsValidateReports(): array {
        return [
            'empty label'               => ['à..it', 'xn--0ca..it'],
            'leading hyphen'            => ['-à.it', 'xn----sfa.it'],
            'trailing hyphen'           => ['à-.it', 'xn----rfa.it'],
            'hyphens in positions 3-4'  => ['àb--cd.it', 'xn--b--cd-qqa.it'],
        ];
    }

    public function testAceStillEncodesALabelOrNameThatIsTooLong(): void {
        $label = str_repeat('è', 64);
        $this->assertMatchesRegularExpression('/^xn--[a-z0-9-]{60,}\.it$/', Idn::ace("{$label}.it"));

        // 254 octets in punycode: ICU still hands over the result
        $this->assertSame('xn--8ca.' . str_repeat('a.', 122) . 'bb', Idn::ace('è.' . str_repeat('a.', 122) . 'bb'));
    }

    public function testAceReturnsANameBeyondWhatICUEncodesNormalised(): void {
        $name = 'È.' . str_repeat('a.', 125) . 'it';

        $this->assertSame(mb_strtolower($name), Idn::ace($name));
        $this->assertSame('name must not be longer than 253 octets in punycode', Idn::validate($name));
    }

    /**
     * Any other IDNA error leaves the name normalised but not encoded, for
     * validate() or the registry to refuse.
     */
    #[DataProvider('unencodable')]
    public function testAceLeavesAnUnencodableNameUnencoded(string $name, string $expected): void {
        $this->assertSame($expected, Idn::ace($name));
    }

    public static function unencodable(): array {
        return [
            'bidi rule'                 => ['Aאb.it', 'aאb.it'],
            'digit first in a bidi name' => ['1שלום.it', '1שלום.it'],
            'context J'                 => ["a\u{200d}b.it", "a\u{200d}b.it"],
            'invalid punycode'          => ['XN--ZZ.it', 'xn--zz.it'],
            'combining mark first'      => ["\u{0301}a.it", "\u{0301}a.it"],
            'replacement character'     => ["a\u{FFFD}b.it", "a\u{FFFD}b.it"],
            'invalid UTF-8'             => ["a\xc3.it", "a\xc3.it"],
        ];
    }

    public function testAceKeepsGermanSharpSAsTheRegistryExpectsIt(): void {
        $this->assertSame('xn--strae-oqa.it', Idn::ace('straße.it'));
        $this->assertNotSame(Idn::ace('strasse.it'), Idn::ace('straße.it'));
    }

    public function testAceAndNormalizeUndoEachOther(): void {
        foreach (self::itLetters() as $letter) {
            $name = "a{$letter}b.it";
            $ace = Idn::ace($name);

            $this->assertMatchesRegularExpression('/^[a-z0-9.-]+$/', $ace, $name);
            $this->assertSame($name, Idn::normalize($ace), $name);
            $this->assertSame($ace, Idn::ace(Idn::normalize($name)), $name);
        }
    }

    public function testAceKeepsASCIINamesAsTheyAre(): void {
        foreach (['example.it', 'ns1.example.net', 'a-b.it', 'a1.it', 'xn--citt-3na.it', 'a.b.c.d.it'] as $name) {
            $this->assertSame($name, Idn::ace($name));
        }
    }

    /**
     * Lower-case letters of Latin-1 Supplement and Latin Extended-A, except
     * the four UTS #46 folds into other letters (ĳ, ŀ, ŉ, ſ).
     *
     * @return string[]
     */
    private static function itLetters(): array {
        $letters = [];
        for ($code = 0xDF; $code <= 0x17F; $code++) {
            $letter = mb_chr($code);
            if ($code !== 0xF7 && mb_strtolower($letter) === $letter && ! in_array($letter, ['ĳ', 'ŀ', 'ŉ', 'ſ'], true)) {
                $letters[] = $letter;
            }
        }
        return $letters;
    }

    public function testTheLetterSetCoversTheRegistrysAccents(): void {
        $letters = self::itLetters();

        foreach (['à', 'á', 'â', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ð', 'ñ', 'ò', 'ó', 'ô', 'ö', 'ø', 'œ', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'ß', 'þ'] as $letter) {
            $this->assertContains($letter, $letters);
        }
    }

    // -----------------------------------------------------------------
    // validate()
    // -----------------------------------------------------------------

    #[DataProvider('validNames')]
    public function testValidateAcceptsAName(string $name): void {
        $this->assertNull(Idn::validate($name));
    }

    public static function validNames(): array {
        return [
            'ASCII'                         => ['example.it'],
            'upper case'                    => ['EXAMPLE.IT'],
            'Unicode'                       => ['città.it'],
            'punycode'                      => ['xn--citt-3na.it'],
            'sharp s'                       => ['straße.it'],
            'trailing dot'                  => ['città.it.'],
            'hyphen inside a label'         => ['a-b.it'],
            'label of 63 octets'            => [str_repeat('a', 63) . '.it'],
            'Unicode label of 63 octets'    => [str_repeat('è', 30) . '.it'],
            'name of 253 octets'            => [str_repeat('a.', 125) . 'abc'],
            'right-to-left'                 => ['שלום.it'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testValidateReportsTheProblem(string $name, string $expected): void {
        $this->assertSame($expected, Idn::validate($name));
    }

    public static function invalidNames(): array {
        return [
            'empty'                         => ['', 'name must not be empty'],
            'invalid UTF-8'                 => ["a\xc3.it", 'name must be valid UTF-8'],
            'empty label'                   => ['a..it', 'name must not have an empty label'],
            'leading dot'                   => ['.it', 'name must not have an empty label'],
            'only a dot'                    => ['.', 'name must not have an empty label'],
            'empty label, Unicode'          => ['à..it', 'name must not have an empty label'],
            'label of 64 octets'            => [str_repeat('a', 64) . '.it', 'a label must not be longer than 63 octets in punycode'],
            'label over 63 octets in punycode' => [str_repeat('è', 64) . '.it', 'a label must not be longer than 63 octets in punycode'],
            'name of 254 octets'            => [str_repeat('a.', 125) . 'abcd', 'name must not be longer than 253 octets in punycode'],
            'name over 254 octets'          => [str_repeat('a.', 200) . 'it', 'name must not be longer than 253 octets in punycode'],
            'leading hyphen'                => ['-a.it', 'a label must not start with a hyphen'],
            'trailing hyphen'               => ['a-.it', 'a label must not end with a hyphen'],
            'leading hyphen, Unicode'       => ['-à.it', 'a label must not start with a hyphen'],
            'hyphens in positions 3-4'      => ['ab--cd.it', 'a label must not have hyphens in its third and fourth position unless it is a valid xn-- label'],
            'hyphens in positions 3-4, Unicode' => ['àb--cd.it', 'a label must not have hyphens in its third and fourth position unless it is a valid xn-- label'],
            'invalid punycode'              => ['xn--zz.it', 'an xn-- label is not valid punycode'],
            'xn-- label that is not a label' => ['xn--abc-.it', 'an xn-- label does not decode to a valid label'],
            'combining mark first'          => ["\u{0301}a.it", 'a label must not start with a combining mark'],
            'disallowed character'          => ["a\u{FFFD}b.it", 'name contains a character that domain names do not allow'],
            'bidi: Latin inside Hebrew'     => ['אa.it', 'name mixes left-to-right and right-to-left text in a way domain names do not allow'],
            'bidi: digit first'             => ['1שלום.it', 'name mixes left-to-right and right-to-left text in a way domain names do not allow'],
            'context J'                     => ["a\u{200d}b.it", 'a label has a zero-width joiner or non-joiner where it is not allowed'],
        ];
    }

    public function testValidateAllowsAValidXnLabelWithHyphensInPositionsThreeAndFour(): void {
        $this->assertNull(Idn::validate('xn--citt-3na.it'));
        $this->assertNotNull(Idn::validate('ab--citt-3na.it'));
    }

    public function testValidateJudgesTheNameAfterNormalising(): void {
        $this->assertNull(Idn::validate("CAFE\u{0301}.IT"));
        $this->assertSame('a label must not start with a hyphen', Idn::validate('-CITTÀ.IT'));
    }
}
