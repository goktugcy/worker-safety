<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\AnalysisException;
use WorkerSafety\Support\ExcludeMatcher;
use WorkerSafety\Support\FileFinder;
use WorkerSafety\Support\Paths;

#[CoversClass(FileFinder::class)]
final class FileFinderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Paths::normalize(sys_get_temp_dir() . '/ws-finder-' . bin2hex(random_bytes(6)));

        $this->write('src/A.php', '<?php');
        $this->write('src/Nested/B.php', '<?php');
        $this->write('src/notes.txt', 'text');
        $this->write('vendor/acme/C.php', '<?php');
        $this->write('storage/framework/D.php', '<?php');
        $this->write('resources/views/home.blade.php', 'blade');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    private function find(array $paths, array $excludes = [], array $extensions = ['php']): array
    {
        $absolute = array_values(array_map(
            fn (string $path): string => $this->root . '/' . $path,
            $paths,
        ));

        $files = (new FileFinder($this->root, new ExcludeMatcher($excludes), $extensions))->find($absolute);

        return array_values(array_map(
            fn (string $file): string => Paths::makeRelative($file, $this->root),
            $files,
        ));
    }

    public function test_it_finds_php_files_recursively_and_sorts_them(): void
    {
        self::assertSame(['src/A.php', 'src/Nested/B.php'], $this->find(['src']));
    }

    public function test_it_ignores_other_extensions(): void
    {
        self::assertNotContains('src/notes.txt', $this->find(['src']));
    }

    public function test_excluded_directories_are_never_walked(): void
    {
        $files = $this->find(['.'], ['vendor', 'storage']);

        self::assertNotContains('vendor/acme/C.php', $files);
        self::assertNotContains('storage/framework/D.php', $files);
        self::assertContains('src/A.php', $files);
    }

    public function test_glob_excludes_apply_to_files(): void
    {
        $files = $this->find(['.'], ['*.blade.php']);

        self::assertNotContains('resources/views/home.blade.php', $files);
    }

    public function test_an_explicitly_named_file_is_returned(): void
    {
        self::assertSame(['src/A.php'], $this->find(['src/A.php']));
    }

    public function test_an_explicitly_named_file_still_honours_excludes(): void
    {
        self::assertSame([], $this->find(['vendor/acme/C.php'], ['vendor']));
    }

    public function test_extra_extensions_can_be_configured(): void
    {
        self::assertContains('src/notes.txt', $this->find(['src'], [], ['php', 'txt']));
    }

    public function test_a_missing_path_is_an_error(): void
    {
        $this->expectException(AnalysisException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->find(['nope']);
    }

    public function test_no_paths_is_an_error(): void
    {
        $this->expectException(AnalysisException::class);

        (new FileFinder($this->root, new ExcludeMatcher([])))->find([]);
    }
}
