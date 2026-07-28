<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** BLOCKING + cascade NONE — по коду блокирует удаление OrderEntity. */
#[RelationTo(entity: OrderEntity::class, field: 'orderId', type: RelationType::BLOCKING, cascade: DeletionCascade::NONE)]
class OrderItemEntity
{
    public function __construct(public ?int $id = 10, private ?int $orderId = 1)
    {
    }
}
