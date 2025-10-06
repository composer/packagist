<?php

namespace App\QueryFilter;

use Doctrine\ORM\QueryBuilder;

interface QueryFilterInterface
{
    public function filter(QueryBuilder $qb): QueryBuilder;
    public function getKey(): string;
    public function getSelectedValue(): mixed;
}
