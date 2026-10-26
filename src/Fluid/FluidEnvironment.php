<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use FluidStan\Configuration\Configuration;
use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use FluidStan\Environment\EnvironmentInterface;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

/**
 * Creates Fluid rendering contexts configured for analysis and parses templates with them.
 */
final class FluidEnvironment
{
    private bool $bootstrapped = false;

    private ?EnvironmentInterface $environment = null;

    /** @var array<string, list<string>|null> */
    private array $namespaces;

    /** @var array<string, ViewHelperResolverDelegateInterface> */
    private array $resolverDelegates = [];

    private ?ComponentRegistry $components = null;

    public function __construct(private readonly Configuration $configuration)
    {
        $this->namespaces = $configuration->namespaces;
    }

    private function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        foreach ($this->configuration->bootstrapFiles as $file) {
            if (!is_file($file)) {
                throw new \RuntimeException(\sprintf('Bootstrap file "%s" does not exist.', $file));
            }

            (static function (string $file): void {
                require_once $file;
            })($file);
        }

        if ($this->configuration->environment !== null) {
            $class = $this->configuration->environment;
            if (!class_exists($class) || !is_a($class, EnvironmentInterface::class, true)) {
                throw new \RuntimeException(\sprintf('Environment class "%s" does not exist or does not implement %s.', $class, EnvironmentInterface::class));
            }

            $this->environment = new $class();
            $this->environment->boot($this->configuration);
            $this->namespaces = array_merge($this->namespaces, $this->environment->getNamespaces());
            $this->resolverDelegates = $this->environment->getResolverDelegates();
        }

        $this->bootstrapped = true;
    }

    public function getEnvironment(): ?EnvironmentInterface
    {
        $this->bootstrap();
        return $this->environment;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    public function resolvePaths(array $paths): array
    {
        $this->bootstrap();
        return array_map(fn(string $path): string => $this->environment?->resolvePath($path) ?? $path, $paths);
    }

    public function parseFile(string $file): ParsedTemplate
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new \RuntimeException(\sprintf('Cannot read template "%s".', $file));
        }

        return $this->parseSource($source, $file);
    }

    public function parseSource(string $source, string $file): ParsedTemplate
    {
        $this->bootstrap();
        $parser = new LocationTrackingTemplateParser();
        $errorHandler = new CollectingErrorHandler($parser, $file);
        $renderingContext = new RenderingContext();
        $renderingContext->setTemplateParser($parser);
        $renderingContext->setErrorHandler($errorHandler);

        $resolver = new AnalysisViewHelperResolver();
        $resolver->setResolverDelegates($this->resolverDelegates);
        $resolver->setNamespaces($this->namespaces);

        $renderingContext->setViewHelperResolver($resolver);

        $diagnostics = new DiagnosticCollection();
        $parsingState = null;
        // Deprecated ViewHelpers and syntax trigger E_USER_DEPRECATED while parsing; collect them the
        // way Fluid's own TemplateValidator does.
        set_error_handler(static function (int $errno, string $message) use ($diagnostics, $parser, $file): bool {
            $diagnostics->add(new Diagnostic($file, $parser->getCurrentLocation()->line, $message, 'fluid.deprecation'));
            return true;
        }, E_USER_DEPRECATED);
        try {
            $parsingState = $parser->parse($source, md5($file), $file);
        } catch (\Throwable $throwable) {
            // The parser's pointer still refers to the chunk that failed
            $line = $parser->getCurrentLocation()->line;
            $diagnostics->add(new Diagnostic($file, $line, CollectingErrorHandler::cleanMessage($throwable), 'fluid.parser'));
        } finally {
            restore_error_handler();
        }

        $diagnostics->merge($errorHandler->getDiagnostics());
        $this->components ??= ComponentRegistry::fromNamespaces($this->namespaces, $this->resolverDelegates, new AnalysisViewHelperResolver());

        return new ParsedTemplate($file, $source, $parsingState, $parser->getNodeLocations(), $diagnostics, $renderingContext, $this->components->find($file));
    }
}
