<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\View\TemplateFinder as FluidTemplateFinder;

final class TemplateFinder
{
    /**
     * @param list<string> $paths Files or directories
     * @param list<string> $suffixes File name suffixes such as ".html"
     * @return list<string> Absolute file paths, sorted
     */
    public function find(array $paths, array $suffixes): array
    {
        $files = [];
        $directories = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = (string) realpath($path);
            } elseif (is_dir($path)) {
                $directories[] = $path;
            } else {
                throw new \RuntimeException(\sprintf('Path "%s" does not exist.', $path));
            }
        }

        foreach ($suffixes as $suffix) {
            foreach ($this->scan($directories, ltrim($suffix, '.')) as $file) {
                $files[] = (string) realpath($file);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private function scan(array $directories, string $extension): array
    {
        if (class_exists(FluidTemplateFinder::class)) {
            return array_values((new FluidTemplateFinder())->findTemplatesByFileExtension($directories, $extension));
        }

        // Fluid 4 has no TemplateFinder
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getBasename(), '.' . $extension)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
