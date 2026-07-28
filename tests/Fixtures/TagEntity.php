<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Связь через промежуточную таблицу, cascade = DETACH_RELATIONS. */
#[RelationTo(
    entity: OrderEntity::class,
    field: 'id',
    type: RelationType::BLOCKING,
    cascade: DeletionCascade::DETACH_RELATIONS,
    joinTable: 'order_tag',
    joinColumn: 'order_id',
    inverseJoinColumn: 'tag_id'
)]
class TagEntity
{
    public function __construct(public ?int $id = 50)
    {
    }
}
