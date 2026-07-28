<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Родитель. Своих атрибутов не имеет. */
class OrderEntity
{
    public function __construct(public ?int $id = 1)
    {
    }
}
