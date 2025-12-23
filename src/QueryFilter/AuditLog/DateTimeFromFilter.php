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

namespace App\QueryFilter\AuditLog;

use App\QueryFilter\QueryFilterInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

class DateTimeFromFilter implements QueryFilterInterface
{
    private function __construct(
        private readonly string $value
    ) {}

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        if ($this->value === '') {
            return $qb;
        }

        try {
            $dateTime = new \DateTimeImmutable($this->value);
            $qb->setParameter('datetime_from', $dateTime);
            $qb->andWhere('a.datetime >= :datetime_from');
        } catch (\Exception) {
            // Invalid datetime format, don't use for filtering
        }

        return $qb;
    }

    public function getSelectedValue(): mixed
    {
        return $this->value;
    }

    /**
     * @param InputBag<string> $bag
     */
    public static function fromQuery(InputBag $bag): self
    {
        $value = $bag->get('datetime_from', '');

        if (!is_string($value) || $value === '') {
            return new self('');
        }

        return new self(trim($value));
    }

    public function getKey(): string
    {
        return 'datetime_from';
    }

    public function getDateTime(): ?\DateTimeImmutable
    {
        if ($this->value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($this->value);
        } catch (\Exception) {
            return null;
        }
    }
}
