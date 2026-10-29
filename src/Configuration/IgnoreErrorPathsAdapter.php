<?php

declare(strict_types=1);

namespace FluidStan\Configuration;

use Nette\DI\Config\Adapter;
use Nette\DI\Config\Adapters\NeonAdapter;

/**
 * Nette's NEON adapter, with the path and paths of each ignoreErrors entry made absolute against the
 * file that defines the entry, as PHPStan does: a baseline elsewhere keeps working when included.
 */
final class IgnoreErrorPathsAdapter implements Adapter
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $file): array
    {
        $data = (new NeonAdapter())->load($file);
        $entries = \is_array($data['parameters'] ?? null) ? ($data['parameters']['ignoreErrors'] ?? null) : null;
        if (!\is_array($entries)) {
            return $data;
        }

        $directory = \dirname($file);
        foreach ($entries as $index => $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            if (\is_string($entry['path'] ?? null)) {
                $entry['path'] = self::absolute($entry['path'], $directory);
            }

            if (\is_array($entry['paths'] ?? null)) {
                $entry['paths'] = array_map(static fn(mixed $path): mixed => \is_string($path) ? self::absolute($path, $directory) : $path, $entry['paths']);
            }

            $entries[$index] = $entry;
        }

        $data['parameters']['ignoreErrors'] = $entries;
        return $data;
    }

    private static function absolute(string $path, string $directory): string
    {
        return ConfigFileLoader::normalize((new ConfigurationLoader())->resolvePath($path, $directory));
    }
}
