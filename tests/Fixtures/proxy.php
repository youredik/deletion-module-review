<?php

declare(strict_types=1);

// Имитация Doctrine-прокси: реальное имя класса у lazy-loaded сущности
// выглядит как Proxies\__CG__\<FQCN>, а не как сама сущность.
namespace Proxies\__CG__\Tests\Fixtures;

class OrderEntity extends \Tests\Fixtures\OrderEntity
{
}
