<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\Validate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The length maps in Validate are a copy of the schema's column widths; this
 * is what keeps the two from drifting apart.
 */
final class SchemaColumnWidthsTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../config/mariadb-schema.sql';

    /** @return array<string, int> column => varchar width of one table */
    private static function widths(string $table): array {
        $sql = (string) file_get_contents(self::SCHEMA);
        preg_match('/CREATE TABLE `' . $table . '` \((.*?)\n\) ENGINE/s', $sql, $body);
        preg_match_all('/^\s*`(\w+)`\s+varchar\((\d+)\)/m', $body[1] ?? '', $columns, PREG_SET_ORDER);

        $widths = [];
        foreach ($columns as [, $name, $width]) {
            $widths[$name] = (int) $width;
        }
        return $widths;
    }

    /** @return array<string, array{string, array<string, int>}> */
    public static function maps(): array {
        return [
            'contacts' => ['contacts', Validate::CONTACT_FIELD_MAX_LENGTHS],
            'domains'  => ['domains', Validate::DOMAIN_FIELD_MAX_LENGTHS],
            'users'    => ['users', Validate::USER_FIELD_MAX_LENGTHS],
        ];
    }

    #[DataProvider('maps')]
    public function testEveryLimitEqualsItsColumnWidth(string $table, array $map): void {
        $widths = self::widths($table);

        foreach ($map as $field => $max) {
            $this->assertArrayHasKey($field, $widths, "{$table}.{$field} is not a varchar column");
            $this->assertSame($widths[$field], $max, "{$table}.{$field}");
        }
    }

    public function testLimitsCountCharactersNotBytes(): void {
        $map = ['name' => 256];

        $this->assertNull(Validate::maxLength(['name' => str_repeat('ü', 256)], $map));
        $this->assertNotNull(Validate::maxLength(['name' => str_repeat('ü', 257)], $map));
    }
}
