<?php

declare(strict_types=1);

namespace Tests;

use Shared\Persistence\GenericReadRepository;

/**
 * Подменить GenericReadRepository через createMock() нельзя — DeletionService
 * типизирует конкретный класс, а не интерфейс. Поэтому наследуемся.
 */
final class FakeReadRepository extends GenericReadRepository
{
    /**
     * @param array<string, array<int, object>> $byAssociation
     * @param array<string, array<int, object>> $byJoinTable
     * @param array<string, array<int, object>> $byJson
     */
    public function __construct(
        private readonly array $byAssociation = [],
        private readonly array $byJoinTable = [],
        private readonly array $byJson = [],
    ) {
    }

    public function getId(object $object): int|string|null
    {
        return $object->id ?? null;
    }

    public function findByAssociation(string $entityClass, string $field, object $parent): array
    {
        return $this->byAssociation[$entityClass] ?? [];
    }

    public function findByJoinTable(
        string $entityClass,
        string $joinTable,
        string $joinColumn,
        string $inverseJoinColumn,
        object $parent
    ): array {
        return $this->byJoinTable[$entityClass] ?? [];
    }

    public function findByJsonContains(string $entityClass, string $field, mixed $value): array
    {
        return $this->byJson[$entityClass] ?? [];
    }
}
