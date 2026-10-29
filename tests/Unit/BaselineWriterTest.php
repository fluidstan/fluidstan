<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Diagnostics\BaselineWriter;
use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use FluidStan\Tests\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BaselineWriterTest extends TestCase
{
    use TemporaryDirectory;

    public function testWritesPhpStansFormatWithPathsRelativeToTheBaseline(): void
    {
        $diagnostics = new DiagnosticCollection();
        $diagnostics->add(new Diagnostic($this->tmpDir . '/Templates/B.html', 7, 'Cannot access "x" on int.', 'fluidstan.property.unresolvable'));
        $diagnostics->add(new Diagnostic($this->tmpDir . '/Templates/A.html', 3, 'Variable "x" is not defined.', 'variable.undefined'));
        $diagnostics->add(new Diagnostic($this->tmpDir . '/Templates/A.html', 9, 'Variable "x" is not defined.', 'variable.undefined'));
        $diagnostics->add(new Diagnostic($this->tmpDir . '/Templates/A.html', 4, 'No identifier #here'));
        $diagnostics->add(new Diagnostic('', null, 'PHPStan failed', 'phpstan.internal'));
        $diagnostics->add(new Diagnostic('/outside/C.html', 1, 'Elsewhere', 'x'));

        $baseline = $this->tmpDir . '/build/fluidstan-baseline.neon';
        $this->assertSame(5, (new BaselineWriter())->write($diagnostics, $baseline));
        $this->assertStringEqualsFile($baseline, <<<'NEON'
            parameters:
            	ignoreErrors:
            		-
            			message: '#^No identifier \#here$#'
            			count: 1
            			path: ../Templates/A.html

            		-
            			message: '#^Variable "x" is not defined\.$#'
            			identifier: variable.undefined
            			count: 2
            			path: ../Templates/A.html

            		-
            			message: '#^Cannot access "x" on int\.$#'
            			identifier: fluidstan.property.unresolvable
            			count: 1
            			path: ../Templates/B.html

            		-
            			message: '#^Elsewhere$#'
            			identifier: x
            			count: 1
            			path: /outside/C.html

            NEON);
    }

    public function testAFailedWriteIsAnError(): void
    {
        // A directory where the file should be: the write fails
        mkdir($this->tmpDir . '/fluidstan-baseline.neon');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be written');
        (new BaselineWriter())->write(new DiagnosticCollection(), $this->tmpDir . '/fluidstan-baseline.neon');
    }

    public function testAnEmptyBaselineIsStillAFile(): void
    {
        $baseline = $this->tmpDir . '/fluidstan-baseline.neon';
        $this->assertSame(0, (new BaselineWriter())->write(new DiagnosticCollection(), $baseline));
        $this->assertStringEqualsFile($baseline, "parameters:\n\tignoreErrors: []\n");
    }
}
