<?php

declare(strict_types=1);

namespace FluidStan\Tests;

/**
 * A temporary directory per test process, removed again afterwards: it keeps generated code and
 * PHPStan's caches out of the source tree without piling up under mutation testing.
 */
trait TemporaryDirectory
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fluidstan-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->tmpDir);
    }
}
