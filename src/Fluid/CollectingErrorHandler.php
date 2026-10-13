<?php

declare(strict_types=1);

namespace FluidStan\Fluid;

use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use TYPO3Fluid\Fluid\Core\Compiler\StopCompilingException;
use TYPO3Fluid\Fluid\Core\ErrorHandler\ErrorHandlerInterface;
use TYPO3Fluid\Fluid\Core\Parser\Exception as ParserException;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\ExpressionException;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception as ViewHelperException;
use TYPO3Fluid\Fluid\View\Exception as ViewException;

/**
 * Collects parser errors as diagnostics and lets parsing continue, so that all problems of a
 * template are reported in one run.
 */
final readonly class CollectingErrorHandler implements ErrorHandlerInterface
{
    private DiagnosticCollection $diagnostics;

    public function __construct(
        private LocationTrackingTemplateParser $parser,
        private string $file,
    ) {
        $this->diagnostics = new DiagnosticCollection();
    }

    public function getDiagnostics(): DiagnosticCollection
    {
        return $this->diagnostics;
    }

    public function handleParserError(ParserException $error): string
    {
        $this->collect($error, 'fluid.parser');
        return '';
    }

    public function handleExpressionError(ExpressionException $error): string
    {
        $this->collect($error, 'fluid.expression');
        return '';
    }

    public function handleViewHelperError(ViewHelperException $error): string
    {
        $this->collect($error, 'fluid.viewHelper');
        return '';
    }

    public function handleCompilerError(StopCompilingException $error): string
    {
        return '';
    }

    public function handleViewError(ViewException $error): string
    {
        $this->collect($error, 'fluid.view');
        return '';
    }

    public function collect(\Throwable $error, string $identifier): void
    {
        // Exceptions routed through the error handler may carry a placeholder location (line 1);
        // the parser's current chunk is reliable at this point.
        $line = $this->parser->getCurrentLocation()->line;
        $this->diagnostics->add(new Diagnostic($this->file, $line, self::cleanMessage($error), $identifier));
    }

    /**
     * TemplateParser::createParsingRelatedExceptionWithContext() wraps parse errors into a message with
     * location and source chunk, keeping the original exception as previous; the location is already known here.
     */
    public static function cleanMessage(\Throwable $error): string
    {
        $previous = $error->getPrevious();
        if ($previous instanceof \Throwable && str_starts_with($error->getMessage(), 'Fluid parse error in template')) {
            return $previous->getMessage();
        }

        return $error->getMessage();
    }
}
