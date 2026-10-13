<?php

declare(strict_types=1);

namespace App\Shop;

final readonly class Product
{
    public function __construct(
        public string $name,
        private Money $price,
        private ?Category $category = null,
        private ?Image $image = null,
    ) {}

    public function getPrice(): Money
    {
        return $this->price;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getImage(): ?Image
    {
        return $this->image;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
