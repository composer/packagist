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

use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Ulid;

/**
 * Shows every package of one account event. The filter is only set by the "and N more packages" link.
 */
class EventFilter implements QueryFilterInterface
{
    private const KEY = 'event';

    private function __construct(
        private readonly ?Ulid $value,
    ) {
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === null) {
            return $qb;
        }

        return $qb->andWhere('t.sourceAuditLogId = :event')
            ->setParameter('event', $this->value, 'ulid');
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getSelectedValue(): ?string
    {
        return $this->value !== null ? (string) $this->value : null;
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag): self
    {
        $value = $bag->get(self::KEY);

        // An invalid id is ignored, so the page shows all entries instead of an error.
        if (!\is_string($value) || !Ulid::isValid($value)) {
            return new self(null);
        }

        return new self(Ulid::fromString($value));
    }
}
