<?php

declare(strict_types=1);

namespace App\Shop;

final readonly class Money implements \Stringable
{
    public function __construct(
        public int $cents,
        public string $currency,
    ) {}

    public function __toString(): string
    {
        return \sprintf('%.2f %s', $this->cents / 100, $this->currency);
    }
}
