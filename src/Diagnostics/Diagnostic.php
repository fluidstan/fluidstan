<?php

declare(strict_types=1);

namespace FluidStan\Diagnostics;

/**
 * One reported problem, located in a template file.
 */
final readonly class Diagnostic
{
    public string $message;

    public function __construct(
        public string $file,
        public ?int $line,
        string $message,
        public ?string $identifier = null,
        public ?string $tip = null,
    ) {
        // Messages may quote template content; keep terminal control sequences out of the output
        $this->message = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $message) ?? $message;
    }

    public function withFile(string $file): self
    {
        return new self($file, $this->line, $this->message, $this->identifier, $this->tip);
    }
}
