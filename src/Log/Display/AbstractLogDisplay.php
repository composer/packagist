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

namespace App\Log\Display;

/**
 * Every log row carries a timestamp and an actor; the rest is per-event detail added by the leaf
 * classes in {@see Event}.
 */
abstract readonly class AbstractLogDisplay implements LogDisplayInterface
{
    public function __construct(
        public \DateTimeImmutable $datetime,
        public ActorDisplay $actor,
        /**
         * Only the audit log records an IP, and only auditors see it. Rows projected into the
         * transparency log have none (`package_transparency_log` has no such column).
         */
        public ?string $ip = null,
    ) {
    }

    public function getDateTime(): \DateTimeImmutable
    {
        return $this->datetime;
    }

    public function getActor(): ActorDisplay
    {
        return $this->actor;
    }

    public function getTypeTranslationKey(): string
    {
        return 'log.type.'.$this->getType()->value;
    }

    /**
     * Translation key for a reason label, or null if we have no label for the stored value, so the
     * row shows nothing instead of a raw enum value.
     *
     * @param class-string<\BackedEnum> $reasonEnum
     */
    protected function reasonTranslationKey(?string $reason, string $reasonEnum, string $group): ?string
    {
        if ($reason === null || $reasonEnum::tryFrom($reason) === null) {
            return null;
        }

        return 'log.'.$group.'.'.$reason;
    }
}
