<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Shared\Deletion\Attribute\RelationTo;
use Shared\Deletion\Enum\{DeletionCascade, RelationType};

/** Базовая сущность с объявленной связью (наследование таблиц — обычное дело). */
#[RelationTo(entity: OrderEntity::class, field: 'orderId', type: RelationType::BLOCKING, cascade: DeletionCascade::NONE)]
abstract class AbstractDocumentEntity
{
    public function __construct(public ?int $id = 60, protected ?int $orderId = 1)
    {
    }
}
