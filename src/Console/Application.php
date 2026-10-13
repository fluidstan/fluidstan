<?php

declare(strict_types=1);

namespace FluidStan\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

final class Application extends BaseApplication
{
    public function __construct(?string $autoloadFile)
    {
        parent::__construct('FluidStan', $this->version());
        $this->setCommandLoader(new FactoryCommandLoader([
            'analyse' => static fn(): AnalyseCommand => new AnalyseCommand($autoloadFile),
        ]));
        $this->setDefaultCommand('analyse', true);
    }

    /**
     * The version Composer installed, like PHPStan and twigstan report it; "unknown" outside Composer.
     */
    private function version(): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('fluidstan/fluidstan')) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion('fluidstan/fluidstan') ?? 'unknown';
    }
}
