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

namespace App\QueryFilter\User;

use App\Entity\UserFreezeReason;
use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FrozenStatusFilter implements QueryFilterInterface
{
    private function __construct(
        private readonly string $value,
        private readonly ?UserFreezeReason $reason,
    ) {
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag): self
    {
        $value = trim($bag->getString('frozen'));

        if ($value === '' || $value === 'none' || $value === 'any') {
            return new self($value, null);
        }

        $reason = UserFreezeReason::tryFrom($value);
        if ($reason === null) {
            throw new BadRequestHttpException('Unknown freeze filter');
        }

        return new self($value, $reason);
    }

    public static function forReason(UserFreezeReason $reason): self
    {
        return new self($reason->value, $reason);
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        return match ($this->value) {
            'none' => $qb->andWhere('u.frozen IS NULL'),
            'any' => $qb->andWhere('u.frozen IS NOT NULL'),
            '' => $qb,
            default => $qb->andWhere('u.frozen = :frozenReason')->setParameter('frozenReason', $this->reason),
        };
    }

    public function narrowsToFrozen(): bool
    {
        return $this->value !== '' && $this->value !== 'none';
    }

    public function getKey(): string
    {
        return 'frozen';
    }

    public function getSelectedValue(): string
    {
        return $this->value;
    }
}
