<?php

declare(strict_types=1);

namespace Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\TestCase;
use Shared\Deletion\DeletionService;
use Shared\Deletion\Dto\DependentGroupDto;
use Tests\Fixtures\{AssocChildEntity, InvoiceEntity, NoteEntity, OrderEntity, OrderItemEntity, ShipmentEntity, TagEntity};

require_once __DIR__ . '/Fixtures/proxy.php';

/**
 * Тесты для DeletionService.
 *
 * Разделены на две группы:
 *   1) «Зафиксированное поведение» — то, что работает верно, и это надо защитить от регрессий.
 *   2) «Найденные дефекты» — тесты, которые доказывают проблемы из ревью. Они зелёные,
 *      потому что проверяют фактическое поведение, но каждый снабжён пометкой,
 *      каким поведение должно быть. Это исполняемая часть code review.
 */
final class DeletionServiceTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // 1. Зафиксированное поведение
    // ────────────────────────────────────────────────────────────────

    public function testEntityWithoutAnyRelationsCanBeDeleted(): void
    {
        $service = $this->makeService([OrderEntity::class], new FakeReadRepository());

        $result = $service->canDelete(new OrderEntity());

        self::assertTrue($result->canDelete);
        self::assertSame([], $result->dependents);
    }

    public function testBlockingChildPreventsParentDeletion(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, OrderItemEntity::class],
            new FakeReadRepository(byAssociation: [OrderItemEntity::class => [new OrderItemEntity(10)]])
        );

        $result = $service->canDelete(new OrderEntity());

        self::assertFalse($result->canDelete);
        self::assertCount(1, $result->dependents);
        self::assertSame(OrderItemEntity::class, $result->dependents[0]->childClass);
        self::assertTrue($result->dependents[0]->hard);
        self::assertSame([10], $result->dependents[0]->ids);
    }

    public function testDeleteChildCascadeDoesNotBlockAndLandsInDeleteList(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, ShipmentEntity::class],
            new FakeReadRepository(byAssociation: [ShipmentEntity::class => [new ShipmentEntity(20)]])
        );

        $relations = $service->analyze(new OrderEntity());

        self::assertTrue($relations->canDelete);
        self::assertCount(1, $relations->childrenDelete);
        self::assertSame([], $relations->childrenDetach);
    }

    public function testDetachRelationsChildDoesNotBlockParentEvenWhenBlocking(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, TagEntity::class],
            new FakeReadRepository(byJoinTable: [TagEntity::class => [new TagEntity(50)]])
        );

        $relations = $service->analyze(new OrderEntity());

        self::assertTrue($relations->canDelete, 'DETACH_RELATIONS не должен блокировать родителя');
        self::assertCount(1, $relations->childrenDetach);
        self::assertTrue($relations->childrenDetach[0]->hard, 'флаг hard=true, но удаление при этом разрешено');
    }

    public function testCountMatchesNumberOfCollectedIds(): void
    {
        $children = [new OrderItemEntity(10), new OrderItemEntity(11), new OrderItemEntity(12)];
        $service = $this->makeService(
            [OrderEntity::class, OrderItemEntity::class],
            new FakeReadRepository(byAssociation: [OrderItemEntity::class => $children])
        );

        $group = $service->analyze(new OrderEntity())->childrenDelete[0];

        self::assertSame(3, $group->count);
        self::assertSame([10, 11, 12], $group->ids);
    }

    // ────────────────────────────────────────────────────────────────
    // 2. Найденные дефекты
    // ────────────────────────────────────────────────────────────────

    /**
     * ДЕФЕКТ 1 (критичный). Doctrine отдаёт lazy-loaded сущность как прокси,
     * у которого $object::class равен Proxies\__CG__\<FQCN>. Карта связей построена
     * по настоящим именам классов, поэтому поиск детей не находит ничего
     * и canDelete возвращает true при живых блокирующих детях.
     *
     * Ожидаемое поведение: результат должен совпадать с testBlockingChildPreventsParentDeletion.
     */
    public function testProxyEntitySilentlyLosesAllChildren(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, OrderItemEntity::class],
            new FakeReadRepository(byAssociation: [OrderItemEntity::class => [new OrderItemEntity(10)]])
        );

        $proxy = new \Proxies\__CG__\Tests\Fixtures\OrderEntity();

        $result = $service->canDelete($proxy);

        self::assertTrue($result->canDelete, 'ФАКТ: удаление разрешено, хотя блокирующий ребёнок существует');
        self::assertSame([], $result->dependents, 'ФАКТ: зависимости не найдены вовсе');
    }

    /**
     * ДЕФЕКТ 1б (та же причина). Атрибуты в PHP не наследуются: если RelationTo
     * объявлен на абстрактной базовой сущности, наследники его не видят.
     * При наследовании таблиц связь просто перестаёт существовать для модуля.
     */
    public function testRelationDeclaredOnParentClassIsInvisibleToSubclass(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, InvoiceEntity::class],
            new FakeReadRepository(byAssociation: [InvoiceEntity::class => [new InvoiceEntity(60)]])
        );

        $result = $service->canDelete(new OrderEntity());

        self::assertTrue($result->canDelete, 'ФАКТ: связь с базового класса потеряна');
        self::assertSame([], $result->dependents);
    }

    /**
     * ДЕФЕКТ 2. Связь REFERENCE + cascade NONE полностью выпадает из ответа:
     * записи есть, но ни в childrenDelete, ни в childrenDetach они не попадают.
     * Потребитель API не узнает, что на сущность кто-то ссылается.
     */
    public function testReferenceWithCascadeNoneDisappearsFromResult(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, NoteEntity::class],
            new FakeReadRepository(byAssociation: [NoteEntity::class => [new NoteEntity(30)]])
        );

        $relations = $service->analyze(new OrderEntity());

        self::assertSame([], $relations->childrenDelete);
        self::assertSame([], $relations->childrenDetach);
        self::assertTrue($relations->canDelete);
    }

    /**
     * ДЕФЕКТ 3. Ровно тот пример, что описан в README (`private OrderEntity $order`),
     * приводит к TypeError: getScalarFkValue() объявлен как int|string|null,
     * но читает поле-ассоциацию и получает объект.
     */
    public function testAssociationFieldCrashesWithTypeError(): void
    {
        $service = $this->makeService(
            [AssocChildEntity::class, OrderEntity::class],
            new FakeReadRepository()
        );

        $this->expectException(\TypeError::class);

        $service->canDelete(new AssocChildEntity(40, new OrderEntity()));
    }

    /**
     * ДЕФЕКТ 4. Неинициализированное типизированное свойство роняет проверку:
     * Reflection читает значение без isInitialized().
     */
    public function testUninitializedTypedPropertyCrashes(): void
    {
        $service = $this->makeService(
            [AssocChildEntity::class, OrderEntity::class],
            new FakeReadRepository()
        );

        $this->expectException(\Error::class);

        $service->canDelete(new AssocChildEntity(40, null));
    }

    /**
     * ДЕФЕКТ 5. Ветка «родителей» жёстко проставляет hard = false,
     * поэтому проверка hasHardParent в analyze() не может сработать никогда —
     * это мёртвый код, а запросы ради него всё равно выполняются.
     */
    public function testParentGroupsAreAlwaysSoftSoTheHardParentCheckIsDead(): void
    {
        $service = $this->makeService(
            [OrderItemEntity::class, OrderEntity::class],
            new FakeReadRepository()
        );

        $relations = $service->analyze(new OrderItemEntity(10, 1));

        self::assertCount(1, $relations->parents);
        self::assertFalse(
            $relations->parents[0]->hard,
            'связь объявлена как BLOCKING, но в группе родителей hard всегда false'
        );
        self::assertTrue($relations->canDelete);
    }

    /**
     * ДЕФЕКТ 6. canDelete() склеивает в dependents три разных списка,
     * и флаг hard перестаёт означать «блокирует удаление»:
     * у detach-группы hard = true, но удаление при этом разрешено.
     */
    public function testHardFlagInDependentsDoesNotMeanBlocking(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, TagEntity::class],
            new FakeReadRepository(byJoinTable: [TagEntity::class => [new TagEntity(50)]])
        );

        $result = $service->canDelete(new OrderEntity());

        self::assertTrue($result->canDelete);
        self::assertTrue(
            $this->hasHardGroup($result->dependents),
            'в списке есть группа с hard=true, хотя удаление разрешено — потребитель API будет введён в заблуждение'
        );
    }

    // ────────────────────────────────────────────────────────────────

    /**
     * @param list<class-string> $entityClasses
     */
    private function makeService(array $entityClasses, FakeReadRepository $finder): DeletionService
    {
        $metadata = [];
        foreach ($entityClasses as $class) {
            $metadata[$class] = new ClassMetadata($class);
        }

        // createStub, а не createMock: проверяем состояние результата, а не факты вызовов.
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn(array_values($metadata));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($factory);
        $em->method('getClassMetadata')->willReturnCallback(
            static fn (string $class): ClassMetadata => $metadata[$class] ?? new ClassMetadata($class)
        );

        return new DeletionService($em, $finder);
    }

    /**
     * @param list<DependentGroupDto> $groups
     */
    private function hasHardGroup(array $groups): bool
    {
        foreach ($groups as $group) {
            if ($group->hard) {
                return true;
            }
        }

        return false;
    }
}
