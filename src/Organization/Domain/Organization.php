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

namespace App\Organization\Domain;

use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\InvalidAvatar;
use App\Organization\Domain\Exception\InvalidDisplayName;
use App\Organization\EventStore\AbstractAggregate;
use App\Organization\EventStore\DomainEvent;
use Composer\Pcre\Preg;
use Symfony\Component\Uid\Ulid;

/**
 * The Organization aggregate. External facts (slug uniqueness, deny-list, vendor collision,
 * rate limits) are checked by the application service before a command reaches here.
 *
 * This is the write-side model. The projection is {@see \App\Entity\Organization}.
 */
final class Organization extends AbstractAggregate
{
    public const int DISPLAY_NAME_MAX_LENGTH = 60;

    /** Letters, numbers, spaces and hyphens. */
    private const string DISPLAY_NAME_PATTERN = '/^[\p{L}\p{N}\- ]+$/u';

    private string $slug;

    private string $displayName;

    private ?string $avatarUrl = null;

    // Groundwork for org deletion (not yet implemented).
    private bool $deleted = false;

    /**
     * @throws InvalidDisplayName
     * @throws InvalidAvatar
     */
    public static function create(Ulid $id, Slug $slug, string $displayName, ?string $avatarUrl): self
    {
        $displayName = self::normalizeDisplayName($displayName);
        $avatarUrl = self::normalizeAvatar($avatarUrl);

        $organization = new self($id);
        $organization->record(new OrganizationCreated($id, $slug->value, $displayName, $avatarUrl));

        return $organization;
    }

    /**
     * Rebuild from the persisted event history.
     *
     * @param list<array{type: string, payload: array<string, mixed>}> $history
     */
    public static function reconstitute(Ulid $id, array $history): self
    {
        $organization = new self($id);
        $organization->replay(array_map(
            static fn (array $row): DomainEvent => self::denormalize($id, $row['type'], $row['payload']),
            $history,
        ));

        return $organization;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof OrganizationCreated => $this->applyCreated($event),
            default => throw new \LogicException('Unhandled organization event: '.$event->eventType()),
        };
    }

    private function applyCreated(OrganizationCreated $event): void
    {
        $this->slug = $event->slug;
        $this->displayName = $event->displayName;
        $this->avatarUrl = $event->avatarUrl;
        $this->deleted = false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function denormalize(Ulid $id, string $type, array $payload): DomainEvent
    {
        return match ($type) {
            OrganizationCreated::TYPE => OrganizationCreated::fromPayload($id, $payload),
            default => throw new \LogicException('Unknown organization event type: '.$type),
        };
    }

    private static function normalizeDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > self::DISPLAY_NAME_MAX_LENGTH) {
            throw new InvalidDisplayName(sprintf('The display name must be between 1 and %d characters.', self::DISPLAY_NAME_MAX_LENGTH));
        }

        if (!Preg::isMatch(self::DISPLAY_NAME_PATTERN, $displayName)) {
            throw new InvalidDisplayName('The display name may only contain letters, numbers, spaces and hyphens.');
        }

        return $displayName;
    }

    private static function normalizeAvatar(?string $avatarUrl): ?string
    {
        if ($avatarUrl === null || trim($avatarUrl) === '') {
            return null;
        }

        $avatarUrl = trim($avatarUrl);

        if (!Preg::isMatch('{^https://(?:www\.|secure\.)?gravatar\.com/avatar/[a-f0-9]{32,64}(?:\?.*)?$}i', $avatarUrl)) {
            throw new InvalidAvatar('The avatar must be a Gravatar URL (https://gravatar.com/avatar/...).');
        }

        return $avatarUrl;
    }
}
