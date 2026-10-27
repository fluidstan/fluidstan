<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Fluid\FluidEnvironment;
use FluidStan\Tests\Fixtures\DelegatingEnvironment;
use FluidStan\Tests\Fixtures\IgnoringEnvironment;
use PHPUnit\Framework\TestCase;

final class FluidEnvironmentTest extends TestCase
{
    public function testIgnoredNamespacesOfTheEnvironmentAreLeftAlone(): void
    {
        $source = '<x:custom>{title}</x:custom>';
        $loader = new ConfigurationLoader();
        $parameters = ['namespaces' => ['x' => 'Nowhere\\ViewHelpers']];

        $parsed = (new FluidEnvironment($loader->fromParameters($parameters, __DIR__)))->parseSource($source, '/t.html');
        $this->assertGreaterThan(0, \count($parsed->parseDiagnostics), 'A ViewHelper that cannot be resolved is a parser error');

        // The environment declares "x" as ignored, which overrides the configured namespace
        $parameters['environment'] = IgnoringEnvironment::class;
        $parsed = (new FluidEnvironment($loader->fromParameters($parameters, __DIR__)))->parseSource($source, '/t.html');
        $this->assertCount(0, $parsed->parseDiagnostics);
        $this->assertTrue($parsed->isParsed());
    }

    public function testDelegatesOfTheEnvironmentResolveNamespacesThatAreNotClasses(): void
    {
        $loader = new ConfigurationLoader();
        $source = '<x:atom.typed text="a" count="1" ratio="1.5" flag="{true}" anything="" when="{now}" />';
        $file = __DIR__ . '/../Fixtures/Components/Atom/Typed/Typed.html';

        $parsed = (new FluidEnvironment($loader->fromParameters(['namespaces' => ['x' => DelegatingEnvironment::NAMESPACE]], __DIR__)))->parseSource($source, $file);
        $this->assertGreaterThan(0, \count($parsed->parseDiagnostics), 'Without the delegate the namespace string is taken for a PHP namespace');

        $environment = new FluidEnvironment($loader->fromParameters(['environment' => DelegatingEnvironment::class], __DIR__));
        $parsed = $environment->parseSource($source, $file);
        $this->assertCount(0, $parsed->parseDiagnostics);
        // The registry knows the delegate's templates as well
        $this->assertSame('atom.typed', $parsed->component?->name);
    }
}
