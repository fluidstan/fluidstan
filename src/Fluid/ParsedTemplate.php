<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use FluidStan\Diagnostics\DiagnosticCollection;
use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

final readonly class ParsedTemplate
{
    public function __construct(
        public string $file,
        public string $source,
        public ?ParsingState $parsingState,
        public NodeLocations $locations,
        public DiagnosticCollection $parseDiagnostics,
        public RenderingContextInterface $renderingContext,
        /** The component this template is rendered as, when it belongs to a configured collection */
        public ?ComponentTemplate $component = null,
    ) {}

    public function isParsed(): bool
    {
        return $this->parsingState instanceof ParsingState;
    }

    /**
     * A component template declares its arguments with f:argument; its scope is closed.
     */
    public function isComponent(): bool
    {
        return $this->parsingState instanceof ParsingState && $this->parsingState->getArgumentDefinitions() !== [];
    }
}
