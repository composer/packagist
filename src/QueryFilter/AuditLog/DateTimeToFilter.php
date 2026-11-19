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

class DateTimeToFilter implements QueryFilterInterface
{
    private function __construct(
        private readonly string $value,
    ) {
    }

    public function filter(QueryBuilder $qb): QueryBuilder
    {
        $dateTime = $this->getDateTime();
        if ($dateTime === null) {
            return $qb;
        }

        return $qb->andWhere('a.datetime <= :datetime_to')
            ->setParameter('datetime_to', $dateTime);
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
        $value = $bag->get('datetime_to', '');

        if (!\is_string($value) || $value === '') {
            return new self('');
        }

        return new self(trim($value));
    }

    public function getKey(): string
    {
        return 'datetime_to';
    }

    public function getDateTime(): ?\DateTimeImmutable
    {
        if ($this->value === '') {
            return null;
        }

        try {
            $dateTime = new \DateTimeImmutable($this->value);
        } catch (\Exception) {
            return null;
        }

        // a date without a time includes that whole day
        return preg_match('{^\d{4}-\d{2}-\d{2}$}', $this->value) ? $dateTime->setTime(23, 59, 59) : $dateTime;
    }
}
