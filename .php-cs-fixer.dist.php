<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    // Fixtures are analysis input rather than project code: one of them is
    // deliberately unparseable and the rest deliberately break good practice.
    ->exclude('Fixtures')
    ->append([__DIR__ . '/bin/worker-safety', __FILE__]);

return (new Config())
    ->setFinder($finder)
    // The parallel runner needs to bind a local TCP socket, which some CI
    // sandboxes refuse. This codebase lints in under two seconds sequentially,
    // so determinism is worth more than the parallelism.
    ->setParallelConfig(ParallelConfigFactory::sequential())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_empty_phpdoc' => true,
        'no_extra_blank_lines' => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => true,
        ],
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_indent' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ]);
