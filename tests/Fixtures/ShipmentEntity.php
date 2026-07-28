<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** REFERENCE + DELETE_CHILD — удаляется вместе с родителем, не блокирует. */
#[RelationTo(entity: OrderEntity::class, field: 'orderId', type: RelationType::REFERENCE, cascade: DeletionCascade::DELETE_CHILD)]
class ShipmentEntity
{
    public function __construct(public ?int $id = 20, private ?int $orderId = 1)
    {
    }
}
