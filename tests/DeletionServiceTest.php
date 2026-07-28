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
 * Тесты для DeletionService. Две группы.
 *
 * Первая — то, что работает верно и должно таким остаться.
 * Вторая повторяет проблемы из ревью. Эти тесты зелёные, потому что проверяют,
 * как модуль ведёт себя сейчас; в комментарии к каждому написано, как должно быть.
 */
final class DeletionServiceTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // Как должно работать
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
    // Найденные проблемы
    // ────────────────────────────────────────────────────────────────

    /**
     * Проблема 1, самая опасная. Лениво загруженная сущность приходит как прокси,
     * у которого $object::class равен Proxies\__CG__\<FQCN>. Карта построена по настоящим
     * именам классов, поэтому потомки не находятся и удаление разрешается.
     *
     * Как должно быть: тот же результат, что в testBlockingChildPreventsParentDeletion.
     */
    public function testProxyEntitySilentlyLosesAllChildren(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, OrderItemEntity::class],
            new FakeReadRepository(byAssociation: [OrderItemEntity::class => [new OrderItemEntity(10)]])
        );

        $proxy = new \Proxies\__CG__\Tests\Fixtures\OrderEntity();

        $result = $service->canDelete($proxy);

        self::assertTrue($result->canDelete, 'удаление разрешено, хотя блокирующий потомок на месте');
        self::assertSame([], $result->dependents, 'зависимости не найдены вовсе');
    }

    /**
     * Проблема 1б, причина та же. Атрибуты в PHP не наследуются: если RelationTo
     * написан на абстрактном предке, наследники его не увидят. При наследовании
     * таблиц связь для модуля просто исчезает.
     */
    public function testRelationDeclaredOnParentClassIsInvisibleToSubclass(): void
    {
        $service = $this->makeService(
            [OrderEntity::class, InvoiceEntity::class],
            new FakeReadRepository(byAssociation: [InvoiceEntity::class => [new InvoiceEntity(60)]])
        );

        $result = $service->canDelete(new OrderEntity());

        self::assertTrue($result->canDelete, 'связь, объявленная у предка, потеряна');
        self::assertSame([], $result->dependents);
    }

    /**
     * Проблема 2. Связь REFERENCE вместе с cascade NONE выпадает из ответа целиком:
     * записи есть, но не попадают ни в childrenDelete, ни в childrenDetach.
     * Тот, кто вызвал метод, не узнает, что на сущность кто-то ссылается.
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
     * Проблема 3. Ровно тот пример, что описан в README (`private OrderEntity $order`),
     * даёт TypeError: getScalarFkValue() обещает int|string|null, а читает обычную
     * связь и получает объект.
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
     * Проблема 4. Незаполненное типизированное свойство роняет проверку:
     * значение читается без isInitialized().
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
     * Проблема 5. Ветка «родителей» жёстко ставит hard = false,
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
     * Проблема 6. canDelete() склеивает в dependents три разных списка,
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
