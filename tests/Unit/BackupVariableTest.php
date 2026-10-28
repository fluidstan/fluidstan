<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Fluid\FluidEnvironment;
use FluidStan\Transpiler\TemplateTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * The variables that back up a scoped variable around f:alias and the like must not collide with the
 * template's own variables. Fluid 5 rejects identifiers starting with "_" while parsing, so the case
 * exists on Fluid 4 only.
 */
final class BackupVariableTest extends TestCase
{
    public function testBackupVariablesAvoidTheTemplatesOwnNames(): void
    {
        $environment = new FluidEnvironment((new ConfigurationLoader())->fromParameters([], __DIR__));
        $parsed = $environment->parseSource(
            '{__scope1_aliased}<f:alias map="{aliased: 1}">{aliased}</f:alias>{aliased}',
            '/t.html',
        );
        foreach ($parsed->parseDiagnostics as $diagnostic) {
            if (str_contains($diagnostic->message, 'cannot start with a "_"')) {
                self::markTestSkipped('This Fluid version rejects the identifier, so it cannot collide.');
            }
        }

        $code = (new TemplateTranspiler())->transpile($parsed)->code;
        $this->assertStringContainsString('$__scope2_aliased = $aliased;', $code);
        $this->assertStringNotContainsString('$__scope1_aliased = $aliased;', $code);
    }
}
