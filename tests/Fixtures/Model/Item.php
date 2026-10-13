<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\Model;

final class Item
{
    public function __construct(
        public string $title,
        private readonly int $price,
        private readonly ?Item $parent = null,
    ) {}

    public function getPrice(): int
    {
        return $this->price;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function hasImage(): bool
    {
        return false;
    }

    public function getParent(): ?Item
    {
        return $this->parent;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return [];
    }

    public function getMoney(): Money
    {
        return new Money();
    }

    /**
     * @return array{street: string, zip: int}
     */
    public function getAddress(): array
    {
        return ['street' => '', 'zip' => 0];
    }
}
