<?php

declare(strict_types=1);

namespace FluidStan\Console;

use FluidStan\InstalledVersion;
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

    private function version(): string
    {
        return InstalledVersion::of('fluidstan/fluidstan');
    }
}
