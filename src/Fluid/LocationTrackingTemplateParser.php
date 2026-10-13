<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use TYPO3Fluid\Fluid\Core\Parser\InterceptorInterface;
use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\ExpressionNodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ObjectAccessorNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\TextNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3Fluid\Fluid\Core\Parser\TemplateParser;

/**
 * Fluid's syntax tree nodes carry no source positions. This parser records one per node
 * while parsing, by locating the parser's current template chunk in the original source
 * and the node's own source text inside that chunk.
 *
 * Positions are exact for ViewHelper tags and for inline syntax; a node whose text cannot
 * be found falls back to the first line of its chunk.
 */
final class LocationTrackingTemplateParser extends TemplateParser
{
    private NodeLocations $locations;

    private string $originalSource = '';

    private string $processedSource = '';

    private int $originalCursor = 0;

    private int $processedCursor = 0;

    private string $templateIdentifier = '';

    private ?string $currentChunk = null;

    private int $currentChunkPointerLine = 0;

    private int $currentChunkOffset = 0;

    private int $currentChunkLine = 1;

    private int $chunkCursor = 0;

    public function __construct()
    {
        $this->locations = new NodeLocations();
    }

    public function getNodeLocations(): NodeLocations
    {
        return $this->locations;
    }

    /**
     * Signature compatible with Fluid 4 (two parameters, nullable identifier) and Fluid 5 (three parameters).
     */
    public function parse(string $templateString, ?string $templateIdentifier = null, ?string $originalTemplatePath = null): ParsingState
    {
        $this->locations = new NodeLocations();
        $this->originalSource = $templateString;
        $this->processedSource = '';
        $this->originalCursor = 0;
        $this->processedCursor = 0;
        $this->currentChunk = null;
        $this->templateIdentifier = $originalTemplatePath ?? $templateIdentifier ?? '';
        // Fluid 5 takes the original template path as third argument, Fluid 4 has only two parameters
        $arguments = [$templateString, $templateIdentifier ?? ''];
        if ((new \ReflectionMethod(TemplateParser::class, 'parse'))->getNumberOfParameters() > 2) {
            $arguments[] = $originalTemplatePath;
        }

        return parent::parse(...$arguments);
    }

    protected function preProcessTemplateSource(string $templateSource): string
    {
        $this->processedSource = parent::preProcessTemplateSource($templateSource);
        return $this->processedSource;
    }

    /**
     * Location of the chunk the parser is currently working on; used for parse errors.
     */
    public function getCurrentLocation(): SourceLocation
    {
        $this->synchronizeChunk();
        return new SourceLocation($this->templateIdentifier, $this->currentChunkLine, 1);
    }

    protected function callInterceptor(NodeInterface &$node, int $interceptionPoint, ParsingState $state): void
    {
        $this->record($node, $interceptionPoint);
        parent::callInterceptor($node, $interceptionPoint, $state);
    }

    private function record(NodeInterface $node, int $interceptionPoint): void
    {
        if ($this->locations->has($node)) {
            return;
        }

        $this->synchronizeChunk();
        $chunk = $this->currentChunk ?? '';
        $needle = null;
        $advance = false;

        if ($node instanceof ViewHelperNode) {
            // Only inline ViewHelpers reach the closing interception without an opening one; those are
            // located via their accessor, so a chunk-level position (no needle) is acceptable.
            if ($interceptionPoint !== InterceptorInterface::INTERCEPT_CLOSING_VIEWHELPER && str_starts_with($chunk, '<' . $node->getNamespace() . ':' . $node->getName())) {
                $needle = '';
            } elseif ($interceptionPoint !== InterceptorInterface::INTERCEPT_CLOSING_VIEWHELPER) {
                $needle = $node->getNamespace() . ':' . $node->getName();
            }
        } elseif ($node instanceof ObjectAccessorNode) {
            $needle = '{' . $node->getObjectPath();
            $advance = true;
        } elseif ($node instanceof TextNode) {
            $needle = $node->getText();
            $advance = true;
        } elseif ($node instanceof ExpressionNodeInterface) {
            $needle = trim($node->getExpression());
            $advance = true;
        }

        $offset = 0;
        if ($needle !== null && $needle !== '') {
            $position = strpos($chunk, $needle, min($this->chunkCursor, \strlen($chunk)));
            if ($position === false) {
                $position = strpos($chunk, $needle);
            }

            if ($position !== false) {
                $offset = $position;
                if ($advance) {
                    $this->chunkCursor = $position + 1;
                }
            }
        }

        $prefix = substr($chunk, 0, $offset);
        $line = $this->currentChunkLine + substr_count($prefix, "\n");
        $lastNewline = strrpos($prefix, "\n");
        $column = $lastNewline === false ? $this->currentChunkOffset + $offset + 1 : $offset - $lastNewline;
        $this->locations->set($node, new SourceLocation($this->templateIdentifier, $line, $column));
    }

    /**
     * Detects whether the parser moved on to a new chunk and, if so, finds that chunk in the source.
     */
    private function synchronizeChunk(): void
    {
        $pointers = $this->getCurrentParsingPointers();
        $pointerLine = \is_int($pointers[0] ?? null) ? $pointers[0] : 1;
        $chunk = \is_string($pointers[2] ?? null) ? $pointers[2] : '';
        if ($chunk === $this->currentChunk && $pointerLine === $this->currentChunkPointerLine) {
            return;
        }

        $this->currentChunk = $chunk;
        $this->currentChunkPointerLine = $pointerLine;
        $this->chunkCursor = 0;

        $position = $chunk === '' ? false : strpos($this->originalSource, $chunk, $this->originalCursor);
        if ($position !== false) {
            $this->originalCursor = $position + \strlen($chunk);
            $this->currentChunkLine = 1 + substr_count($this->originalSource, "\n", 0, $position);
            $this->currentChunkOffset = $this->columnOffset($this->originalSource, $position);
            return;
        }

        $position = $chunk === '' ? false : strpos($this->processedSource, $chunk, $this->processedCursor);
        if ($position !== false) {
            $this->processedCursor = $position + \strlen($chunk);
            $this->currentChunkLine = 1 + substr_count($this->processedSource, "\n", 0, $position);
            $this->currentChunkOffset = $this->columnOffset($this->processedSource, $position);
            return;
        }

        // Fallback: Fluid's pointer holds the line at the end of the chunk.
        $this->currentChunkLine = max(1, $pointerLine - substr_count($chunk, "\n"));
        $this->currentChunkOffset = 0;
    }

    private function columnOffset(string $source, int $position): int
    {
        $lineStart = strrpos(substr($source, 0, $position), "\n");
        return $lineStart === false ? $position : $position - $lineStart - 1;
    }
}
