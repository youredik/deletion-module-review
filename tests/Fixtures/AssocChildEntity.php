<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Поле-ассоциация (объект), ровно как в README: private OrderEntity $order. */
#[RelationTo(entity: OrderEntity::class, field: 'order', type: RelationType::BLOCKING, cascade: DeletionCascade::NONE)]
class AssocChildEntity
{
    private OrderEntity $order;

    public function __construct(public ?int $id = 40, ?OrderEntity $order = null)
    {
        if ($order !== null) {
            $this->order = $order;
        }
    }
}
