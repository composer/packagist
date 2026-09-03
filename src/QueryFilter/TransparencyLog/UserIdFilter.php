<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\QueryFilter\TransparencyLog;

use App\Entity\PackageTransparencyLogSearch;
use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Filters by account id, in all roles. The link on a user's profile uses it. Unlike a search by
 * username, it also finds entries from before a rename.
 *
 * The page has no input for it. The form keeps it in a hidden field.
 */
class UserIdFilter implements QueryFilterInterface
{
    private const KEY = 'user_id';

    private function __construct(
        private readonly ?int $value,
    ) {
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === null) {
            return $qb;
        }

        return $qb
            ->join(PackageTransparencyLogSearch::class, 'userIdSearch', Join::WITH, 'userIdSearch.leafIndex = t.leafIndex')
            ->andWhere('userIdSearch.userId = :userId')
            // one row per entry, also when the account is both user and actor
            ->andWhere('userIdSearch.primaryRow = true')
            ->setParameter('userId', $this->value)
            // order by the index columns, see AbstractSearchIndexFilter
            ->orderBy('userIdSearch.datetime', 'DESC')
            ->addOrderBy('userIdSearch.leafIndex', 'DESC');
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getSelectedValue(): ?int
    {
        return $this->value;
    }

    /**
     * An invalid id is ignored, so the page shows all entries instead of an error.
     *
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag): self
    {
        $value = $bag->get(self::KEY);

        if (!\is_numeric($value) || (int) $value <= 0) {
            return new self(null);
        }

        return new self((int) $value);
    }
}
