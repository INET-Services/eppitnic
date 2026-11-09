<?php

namespace Eppitnic\Support;

/**
 * Domain and host names in the Unicode and punycode forms, by ext-intl:
 * UTS #46, non-transitional (IDNA2008-like: "ß" stays "ß").
 *
 * @category    Net
 * @package     Eppitnic\Support\Idn
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Idn
{
    private const TO_ASCII = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;
    private const TO_UNICODE = IDNA_NONTRANSITIONAL_TO_UNICODE | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    /** What validate() reports; the others are left to the registry. */
    private const OWN_ERRORS = IDNA_ERROR_EMPTY_LABEL
        | IDNA_ERROR_LABEL_TOO_LONG
        | IDNA_ERROR_DOMAIN_NAME_TOO_LONG
        | IDNA_ERROR_LEADING_HYPHEN
        | IDNA_ERROR_TRAILING_HYPHEN
        | IDNA_ERROR_HYPHEN_3_4;

    /** In the order validate() reports them. */
    private const MESSAGES = [
        IDNA_ERROR_EMPTY_LABEL => 'name must not have an empty label',
        IDNA_ERROR_LABEL_TOO_LONG => 'a label must not be longer than 63 octets in punycode',
        IDNA_ERROR_DOMAIN_NAME_TOO_LONG => 'name must not be longer than 253 octets in punycode',
        IDNA_ERROR_LEADING_HYPHEN => 'a label must not start with a hyphen',
        IDNA_ERROR_TRAILING_HYPHEN => 'a label must not end with a hyphen',
        IDNA_ERROR_HYPHEN_3_4 => 'a label must not have hyphens in its third and fourth position unless it is a valid xn-- label',
        IDNA_ERROR_PUNYCODE => 'an xn-- label is not valid punycode',
        IDNA_ERROR_INVALID_ACE_LABEL => 'an xn-- label does not decode to a valid label',
        IDNA_ERROR_LEADING_COMBINING_MARK => 'a label must not start with a combining mark',
        IDNA_ERROR_DISALLOWED => 'name contains a character that domain names do not allow',
        IDNA_ERROR_LABEL_HAS_DOT => 'a label must not contain a dot',
        IDNA_ERROR_BIDI => 'name mixes left-to-right and right-to-left text in a way domain names do not allow',
        IDNA_ERROR_CONTEXTJ => 'a label has a zero-width joiner or non-joiner where it is not allowed',
    ];

    /**
     * Lower case, NFC, and every xn-- label decoded to Unicode. A label is
     * decoded only if encoding the result gives the same label back.
     */
    public static function normalize(string $name): string {
        if ( ! mb_check_encoding($name, 'UTF-8')) {
            return $name;
        }
        $folded = \Normalizer::normalize(mb_strtolower($name), \Normalizer::FORM_C);
        if ($folded === false) {
            return $name;
        }

        $labels = explode('.', $folded);
        foreach ($labels as $i => $label) {
            if (str_starts_with($label, 'xn--')) {
                $labels[$i] = self::decode($label);
            }
        }
        return implode('.', $labels);
    }

    /**
     * The punycode form the registry compares by. A name with an IDNA error
     * validate() does not report itself comes back normalised but unencoded.
     */
    public static function ace(string $name): string {
        $name = self::normalize($name);
        [$ace, $errors] = self::convert($name, true);
        return ($errors & ~self::OWN_ERRORS) === 0 ? $ace : $name;
    }

    /**
     * @return string|null what is wrong with the name, or null if nothing is
     */
    public static function validate(string $name): ?string {
        if ($name === '') {
            return 'name must not be empty';
        }
        if ( ! mb_check_encoding($name, 'UTF-8')) {
            return 'name must be valid UTF-8';
        }

        [, $errors] = self::convert(self::normalize($name), true);
        if ($errors === 0) {
            return null;
        }
        foreach (self::MESSAGES as $bit => $message) {
            if (($errors & $bit) !== 0) {
                return $message;
            }
        }
        return "name is not a valid internationalized domain name (IDNA error {$errors})";
    }

    /**
     * The label in Unicode, or as given unless encoding the result gives the
     * same label back.
     */
    private static function decode(string $label): string {
        [$unicode, $errors] = self::convert($label, false);
        if (($errors & ~self::OWN_ERRORS) !== 0) {
            return $label;
        }
        [$again, $errors] = self::convert($unicode, true);
        return $again === $label && ($errors & ~self::OWN_ERRORS) === 0 ? $unicode : $label;
    }

    /**
     * @return array{string, int} the converted name and the IDNA error bits
     */
    private static function convert(string $name, bool $toAscii): array {
        if ($name === '') {
            return ['', IDNA_ERROR_EMPTY_LABEL];
        }
        if ($toAscii) {
            idn_to_ascii($name, self::TO_ASCII, INTL_IDNA_VARIANT_UTS46, $info);
        } else {
            idn_to_utf8($name, self::TO_UNICODE, INTL_IDNA_VARIANT_UTS46, $info);
        }
        // ICU gives no result past 254 octets
        return [$info['result'] ?? $name, $info['errors'] ?? IDNA_ERROR_DOMAIN_NAME_TOO_LONG];
    }
}
