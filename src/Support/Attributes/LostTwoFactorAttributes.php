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

namespace App\Support\Attributes;

final readonly class LostTwoFactorAttributes implements SupportRequestAttributes
{
    public function __construct(
        /**
         * SHA-256 of the single-use token in the "this wasn't me" link of the alert mail. Only the
         * hash is stored; the raw token exists solely in that emailed link.
         */
        public string $cancelTokenHash,

        /**
         * Earliest time the request may be granted. Set for high-value accounts so the owner alert
         * has time to land and be acted on; null means immediately actionable. Stored rather than
         * recomputed so a later download spike cannot restart the clock.
         */
        public ?\DateTimeImmutable $approvableAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $approvableAt = $data['approvableAt'] ?? null;

        return new self(
            (string) ($data['cancelTokenHash'] ?? ''),
            is_string($approvableAt) ? new \DateTimeImmutable($approvableAt) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'cancelTokenHash' => $this->cancelTokenHash,
            // Offset carried in the string, so the value round-trips whatever the PHP timezone is.
            'approvableAt' => $this->approvableAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    public function summary(): ?string
    {
        return null;
    }
}
