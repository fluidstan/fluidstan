<?php

declare(strict_types=1);

namespace FluidStan\Configuration;

use Nette\DI\Config\Loader;

/**
 * Reads a configuration file with its includes: Nette's configuration loader, extended the way
 * PHPStan extends it for its own NeonLoader. Includes are merged with lists added up and the including
 * file winning otherwise; the baseline being generated is left out, even before it exists; and the
 * adapter makes the paths of ignoreErrors entries absolute against the file that defines them.
 *
 * Not PHPStan's loader itself: loading it from the phar registers PHPStan's own Composer data, and
 * a framework booted afterwards, such as TYPO3, then sees PHPStan's packages as installed.
 */
final class ConfigFileLoader extends Loader
{
    public function __construct(private readonly ?string $skippedFile = null)
    {
        $this->addAdapter('neon', new IgnoreErrorPathsAdapter());
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $file, ?bool $merge = true): array
    {
        if ($this->skippedFile !== null && self::normalize($file) === self::normalize($this->skippedFile)) {
            return [];
        }

        return parent::load($file, $merge);
    }

    /**
     * An absolute path without "." and ".." segments, also for a file that does not exist yet.
     */
    public static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $index => $segment) {
            if ($segment === '..' && $segments !== [] && end($segments) !== '' && end($segments) !== '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && ($segment !== '' || $index === 0)) {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
