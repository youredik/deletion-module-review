<?php

declare(strict_types=1);

namespace Shared\Persistence;

/**
 * ЗАГЛУШКА, написанная для запуска тестов.
 *
 * Класса нет в присланном архиве, но DeletionService типизирует его в конструкторе,
 * поэтому без него код не загрузить. Сигнатуры восстановлены по вызовам
 * в DeletionService: getId(), findByAssociation(), findByJoinTable(), findByJsonContains().
 *
 * Важно: это и есть одна из находок ревью — DeletionService зависит от конкретного
 * класса, а не от интерфейса, поэтому подменить его в тестах штатно нельзя.
 */
class GenericReadRepository
{
    public function getId(object $object): int|string|null
    {
        throw new \LogicException('stub');
    }

    /**
     * @return array<int, object>
     */
    public function findByAssociation(string $entityClass, string $field, object $parent): array
    {
        throw new \LogicException('stub');
    }

    /**
     * @return array<int, object>
     */
    public function findByJoinTable(
        string $entityClass,
        string $joinTable,
        string $joinColumn,
        string $inverseJoinColumn,
        object $parent
    ): array {
        throw new \LogicException('stub');
    }

    /**
     * @return array<int, object>
     */
    public function findByJsonContains(string $entityClass, string $field, mixed $value): array
    {
        throw new \LogicException('stub');
    }
}
