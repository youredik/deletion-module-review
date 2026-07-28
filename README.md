# Тестовое задание: модуль `Deletion`

Эдуард Сарваров · для 3snet

- **Часть 1 — code review:** [REVIEW.md](REVIEW.md)
- **Часть 2 — тесты:** [tests/DeletionServiceTest.php](tests/DeletionServiceTest.php)

## Про содержимое репозитория

Исходники вашего модуля сюда намеренно не выложены — это фрагмент внутренней
библиотеки, и публиковать его в открытом репозитории неправильно. В ревью
цитируются только отдельные строки по месту разбора.

Чтобы запустить тесты, положите модуль в `src/Shared/Deletion/`:

```bash
composer install
cp -r /путь/к/Deletion src/Shared/Deletion
./vendor/bin/phpunit
```

Класса `Shared\Persistence\GenericReadRepository` в архиве не было, а
`DeletionService` типизирует его в конструкторе — поэтому в `src/Shared/Persistence/`
лежит заглушка, восстановленная по вызовам. Тестовый двойник (`tests/FakeReadRepository.php`)
наследуется от неё: подменить зависимость штатно нельзя, потому что в конструкторе
указан конкретный класс, а не интерфейс. Это разобрано в ревью, п. 5.2.

## Как проверялось

PHP 8.5.6, Doctrine ORM 2.19, PHPUnit 11.5.

```
Tests: 11, Assertions: 27
```

Одно предупреждение об устаревшем `ReflectionProperty::setAccessible()` приходит из
самого модуля (`DeletionService.php:235`) — разобрано в ревью, п. 3.8.

Отдельно проверялась ветка связей через промежуточную таблицу
(`DeletionService::getJoinTableParentIds()`): запрос строится как DQL, но в `FROM`
подставляется имя таблицы. На живом ORM это даёт

```
[Semantical Error] line 0, col 24 near 'order_tag jt': Error: Class 'order_tag' is not defined.
```

Подробности — в ревью, п. 1.5.
