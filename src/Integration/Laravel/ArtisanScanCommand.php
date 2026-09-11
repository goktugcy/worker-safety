<?php

declare(strict_types=1);

namespace WorkerSafety\Integration\Laravel;

use Symfony\Component\Console\Attribute\AsCommand;
use WorkerSafety\Analyzer\ScanService;
use WorkerSafety\Baseline\BaselineRepository;
use WorkerSafety\Command\ScanCommand;
use WorkerSafety\Support\Paths;

/**
 * `php artisan worker-safety:scan`.
 *
 * Deliberately nothing but a rename and a different default project root: the
 * arguments, options, output formats, baseline handling and exit codes all come
 * from {@see ScanCommand}, so the Artisan command and `vendor/bin/worker-safety`
 * can never drift apart.
 *
 * Artisan may be invoked from any directory, so the application's base path —
 * not the current working directory — is what the scan defaults to. An explicit
 * `--project-dir` still wins, and a relative one is resolved against the
 * application root, which is the directory an Artisan user is thinking in.
 */
#[AsCommand(
    name: 'worker-safety:scan',
    description: 'Analyze this application for cross-request state risks under persistent workers',
)]
final class ArtisanScanCommand extends ScanCommand
{
    public function __construct(
        private readonly string $basePath,
        ScanService $scanService = new ScanService(),
        BaselineRepository $baselines = new BaselineRepository(),
    ) {
        parent::__construct($scanService, $baselines);
    }

    protected function workingDirectory(): string
    {
        return Paths::normalize($this->basePath);
    }
}
