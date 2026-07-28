<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** REFERENCE + NONE — связь есть, но в результат не попадает вообще. */
#[RelationTo(entity: OrderEntity::class, field: 'orderId', type: RelationType::REFERENCE, cascade: DeletionCascade::NONE)]
class NoteEntity
{
    public function __construct(public ?int $id = 30, private ?int $orderId = 1)
    {
    }
}
