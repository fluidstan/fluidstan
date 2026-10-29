<?php

declare(strict_types=1);

namespace FluidStan\Diagnostics;

/**
 * The baseline was not written because some diagnostics belong to no template, as PHPStan refuses
 * to generate a baseline when there are internal errors.
 */
final class BaselineException extends \RuntimeException
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct('The baseline was not written: the analysis reported errors that belong to no template and cannot be ignored.');
    }
}
