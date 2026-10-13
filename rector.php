<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/docs/example/src'])
    ->withSkip([
        __DIR__ . '/tests/Fixtures/Components',
        __DIR__ . '/tests/Fixtures/Templates',
        // Generated code under test, not source
        __DIR__ . '/tests/Integration/__snapshots__',
        // The runtime facade consists of stubs whose signatures are the contract with the generated code
        __DIR__ . '/src/Runtime/F.php',
    ])
    ->withCache(__DIR__ . '/.cache/rector')
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withAttributesSets(phpunit: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
