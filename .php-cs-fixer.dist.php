<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/docs/example/src'])
    ->exclude(['Fixtures/Components', 'Fixtures/Templates', 'Integration/__snapshots__'])
    ->append([__FILE__, __DIR__ . '/rector.php', __DIR__ . '/bin/fluidstan']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.cache/php-cs-fixer/cache.json')
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        '@PHP82Migration' => true,
        '@PHPUnit100Migration:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
        'nullable_type_declaration_for_default_null_value' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => true],
        'phpdoc_trim' => true,
        'phpdoc_order' => true,
        'phpdoc_align' => ['align' => 'left'],
        'single_line_empty_body' => true,
        'yoda_style' => false,
        'modernize_strpos' => true,
        'get_class_to_class_keyword' => true,
    ])
    ->setFinder($finder);
