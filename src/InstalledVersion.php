<?php

declare(strict_types=1);

namespace FluidStan;

use Composer\InstalledVersions;

/**
 * The version Composer installed of a package, as PHPStan and twigstan report theirs.
 */
final class InstalledVersion
{
    public static function of(string $package): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
    }
}
