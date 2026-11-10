<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\About;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Where `commit` and `built` of `GET /v1/about` come from. */
final class AboutBuildTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';
    private const OTHER = 'fedcba9876543210fedcba9876543210fedcba98';

    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/eppitnic-about-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $path, string $content): void {
        $path = $this->root . '/' . $path;
        if ( ! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    public function testBuildJsonGivesBoth(): void {
        $this->write('BUILD.json', '{"commit": "abc1234", "built": "2026-11-10T08:30:00Z"}');

        $this->assertSame(['commit' => 'abc1234', 'built' => '2026-11-10T08:30:00Z'], About::build($this->root));
    }

    public function testBuildJsonBeatsTheCheckout(): void {
        $this->write('BUILD.json', '{"commit": "abc1234", "built": "2026-11-10T08:30:00Z"}');
        $this->write('.git/HEAD', self::SHA);

        $this->assertSame('abc1234', About::build($this->root)['commit']);
    }

    public function testBuildJsonWithoutAValidTimestampKeepsTheCommit(): void {
        $this->write('BUILD.json', '{"commit": "abc1234", "built": ""}');
        $this->assertSame(['commit' => 'abc1234', 'built' => null], About::build($this->root));

        $this->write('BUILD.json', '{"commit": "abc1234", "built": "yesterday"}');
        $this->assertSame(['commit' => 'abc1234', 'built' => null], About::build($this->root));

        $this->write('BUILD.json', '{"commit": "abc1234"}');
        $this->assertSame(['commit' => 'abc1234', 'built' => null], About::build($this->root));
    }

    #[DataProvider('unusableBuildFiles')]
    public function testAnUnusableBuildJsonFallsBackToTheCheckout(string $content): void {
        $this->write('BUILD.json', $content);
        $this->write('.git/HEAD', self::SHA);

        $this->assertSame(['commit' => '0123456', 'built' => null], About::build($this->root));
    }

    /** @return array<string, array{string}> */
    public static function unusableBuildFiles(): array {
        return [
            'not json'        => ['{"commit": '],
            'not an object'   => ['"abc1234"'],
            'no commit'       => ['{"built": "2026-11-10T08:30:00Z"}'],
            'empty commit'    => ['{"commit": "", "built": "2026-11-10T08:30:00Z"}'],
            'too short'       => ['{"commit": "abc123"}'],
            'not hexadecimal'   => ['{"commit": "main-123"}'],
            'not a string'    => ['{"commit": 1234567}'],
        ];
    }

    public function testNeitherFileNorCheckoutGivesNull(): void {
        $this->assertSame(['commit' => null, 'built' => null], About::build($this->root));
    }

    public function testACheckoutOnABranchGivesItsLooseRef(): void {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/refs/heads/main', self::SHA . "\n");

        $this->assertSame(['commit' => '0123456', 'built' => null], About::build($this->root));
    }

    public function testACheckoutOnABranchFallsBackToPackedRefs(): void {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted \n"
            . self::OTHER . " refs/heads/main-old\n"
            . self::SHA . " refs/heads/main\n"
            . '^' . self::OTHER . "\n");

        $this->assertSame('0123456', About::build($this->root)['commit']);
    }

    public function testALooseRefBeatsPackedRefs(): void {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/refs/heads/main', self::SHA . "\n");
        $this->write('.git/packed-refs', self::OTHER . " refs/heads/main\n");

        $this->assertSame('0123456', About::build($this->root)['commit']);
    }

    public function testADetachedHeadGivesItsHash(): void {
        $this->write('.git/HEAD', self::SHA . "\n");

        $this->assertSame('0123456', About::build($this->root)['commit']);
    }

    public function testABranchWithoutARefGivesNull(): void {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/packed-refs', self::OTHER . " refs/heads/other\n");

        $this->assertSame(['commit' => null, 'built' => null], About::build($this->root));
    }

    public function testAGarbledHeadGivesNull(): void {
        $this->write('.git/HEAD', "not a hash\n");

        $this->assertNull(About::build($this->root)['commit']);
    }

    public function testALinkedWorktreeReadsItsHeadAndTheMainRepositorysRefs(): void {
        $this->write('main/.git/refs/heads/feature', self::SHA . "\n");
        $this->write('main/.git/worktrees/wt/HEAD', "ref: refs/heads/feature\n");
        $this->write('main/.git/worktrees/wt/commondir', "../..\n");
        $this->write('wt/.git', 'gitdir: ' . $this->root . "/main/.git/worktrees/wt\n");

        $this->assertSame('0123456', About::build($this->root . '/wt')['commit']);
    }

    public function testARelativeGitdirIsResolvedAgainstTheRoot(): void {
        $this->write('module/.git', "gitdir: ../gitdirs/module\n");
        $this->write('gitdirs/module/HEAD', self::SHA . "\n");

        $this->assertSame('0123456', About::build($this->root . '/module')['commit']);
    }

    public function testAGitFileWithoutAGitdirGivesNull(): void {
        $this->write('.git', "something else\n");

        $this->assertNull(About::build($this->root)['commit']);
    }

    public function testInfoCarriesBothFields(): void {
        $info = About::info();

        $this->assertArrayHasKey('commit', $info);
        $this->assertArrayHasKey('built', $info);
        $this->assertSame(['name', 'version', 'license', 'copyright', 'commit', 'built', 'dependencies'], array_keys($info));
    }
}
