<?php

namespace Eppitnic\Support;

/**
 * What this installation is made of: the application's identity and the
 * runtime packages Composer installed, for the "About" screen.
 *
 * @category    Net
 * @package     Eppitnic\Support\About
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class About
{
    private const COMMIT = '/^[0-9a-f]{7,40}$/';
    private const TIMESTAMP = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:\d\d)$/';

    /**
     * The version is APP_VERSION; the dependency licenses are joined with
     * " OR " ('' when a package names none).
     *
     * @return array{name: string, version: string, license: string, copyright: string,
     *               commit: ?string, built: ?string,
     *               dependencies: list<array{name: string, version: string, license: string}>}
     */
    public static function info(): array {
        $build = self::build();
        return [
            'name'         => 'eppitnic',
            'version'      => self::version(),
            'license'      => 'BSD-3-Clause',
            'copyright'    => self::copyright(),
            'commit'       => $build['commit'],
            'built'        => $build['built'],
            'dependencies' => self::dependencies(),
        ];
    }

    /**
     * Which commit this is and when it was packaged. BUILD.json, written at
     * packaging time, wins; a git checkout gives its HEAD (read from .git,
     * never by running git) and no time; anything else gives neither.
     *
     * @return array{commit: ?string, built: ?string}
     */
    public static function build(string $root = EPPITNIC_ROOT): array {
        $file = json_decode((string) @file_get_contents($root . '/BUILD.json'), true);
        if (is_array($file) && is_string($file['commit'] ?? null) && preg_match(self::COMMIT, $file['commit'])) {
            $built = $file['built'] ?? null;
            return [
                'commit' => $file['commit'],
                'built'  => is_string($built) && preg_match(self::TIMESTAMP, $built) ? $built : null,
            ];
        }
        return ['commit' => self::gitCommit($root), 'built' => null];
    }

    /** HEAD of the checkout at $root, abbreviated to 7 characters. */
    private static function gitCommit(string $root): ?string {
        $dir = $root . '/.git';
        if (is_file($dir)) {
            // a linked worktree or submodule: ".git" is a file, "gitdir: <path>"
            if ( ! preg_match('/^gitdir: (.+)$/m', (string) @file_get_contents($dir), $m)) {
                return null;
            }
            $dir = str_starts_with($m[1], '/') ? $m[1] : $root . '/' . $m[1];
        }

        $head = trim((string) @file_get_contents($dir . '/HEAD'));
        $hash = str_starts_with($head, 'ref: ') ? self::gitRef($dir, substr($head, 5)) : $head;
        return preg_match('/^[0-9a-f]{40}$/', $hash) ? substr($hash, 0, 7) : null;
    }

    /** The commit a ref names: its loose file, else packed-refs; '' if neither. */
    private static function gitRef(string $dir, string $ref): string {
        // a linked worktree keeps its refs in the main repository
        $dirs = [$dir];
        $common = trim((string) @file_get_contents($dir . '/commondir'));
        if ($common !== '') {
            $dirs[] = str_starts_with($common, '/') ? $common : $dir . '/' . $common;
        }

        foreach ($dirs as $d) {
            $hash = trim((string) @file_get_contents($d . '/' . $ref));
            if ($hash !== '') {
                return $hash;
            }
        }
        foreach ($dirs as $d) {
            $packed = (string) @file_get_contents($d . '/packed-refs');
            if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    private static function version(): string {
        return APP_VERSION;
    }

    private static function copyright(): string {
        $license = (string) @file_get_contents(EPPITNIC_ROOT . '/LICENSE');
        return preg_match('/^Copyright .+$/m', $license, $m) ? $m[0] : '';
    }

    /** @return list<array{name: string, version: string, license: string}> */
    private static function dependencies(): array {
        $installed = json_decode((string) @file_get_contents(EPPITNIC_ROOT . '/vendor/composer/installed.json'), true);
        $dev = array_flip($installed['dev-package-names'] ?? []);

        $dependencies = [];
        foreach ($installed['packages'] ?? [] as $package) {
            if (isset($dev[$package['name']])) {
                continue;
            }
            $dependencies[] = [
                'name'    => $package['name'],
                'version' => $package['pretty_version'] ?? $package['version'] ?? '',
                'license' => implode(' OR ', (array) ($package['license'] ?? [])),
            ];
        }
        usort($dependencies, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        return $dependencies;
    }
}
