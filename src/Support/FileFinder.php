<?php

declare(strict_types=1);

namespace WorkerSafety\Support;

use WorkerSafety\Exception\AnalysisException;

/**
 * Discovers PHP files to analyze.
 *
 * Excluded directories are pruned during traversal instead of being filtered
 * afterwards, which is what keeps `vendor/` from ever being walked.
 */
final class FileFinder
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ExcludeMatcher $excludes,
        /** @var list<string> */
        private readonly array $extensions = ['php'],
    ) {
    }

    /**
     * @param list<string> $paths absolute file or directory paths
     *
     * @return list<string> sorted absolute file paths
     */
    public function find(array $paths): array
    {
        if ($paths === []) {
            throw AnalysisException::noPaths();
        }

        $files = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                throw AnalysisException::pathNotFound($path);
            }

            if (is_file($path)) {
                // An explicitly named file bypasses the extension filter but not
                // the exclude list, so `scan storage/x.php` still honours config.
                if (!$this->isExcluded($path)) {
                    $files[$path] = true;
                }

                continue;
            }

            foreach ($this->walk($path) as $file) {
                $files[$file] = true;
            }
        }

        $result = array_keys($files);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function walk(string $directory): array
    {
        $directoryIterator = new \RecursiveDirectoryIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::UNIX_PATHS,
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (\SplFileInfo $current): bool {
                // Never traverse into symlinked directories: they invite cycles
                // and usually point back into excluded trees.
                if ($current->isLink()) {
                    return false;
                }

                if ($current->isDir()) {
                    return !$this->isExcluded($current->getPathname());
                }

                return $current->isFile()
                    && $this->hasScannableExtension($current->getFilename())
                    && !$this->isExcluded($current->getPathname());
            },
        );

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if ($file->isFile()) {
                $files[] = Paths::normalize($file->getPathname());
            }
        }

        return $files;
    }

    private function hasScannableExtension(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, $this->extensions, true);
    }

    private function isExcluded(string $absolutePath): bool
    {
        if ($this->excludes->isEmpty()) {
            return false;
        }

        return $this->excludes->matches(Paths::makeRelative(Paths::normalize($absolutePath), $this->projectRoot));
    }
}
