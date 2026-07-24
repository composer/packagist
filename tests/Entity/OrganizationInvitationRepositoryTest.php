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

namespace App\Tests\Entity;

use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Organization\Domain\InvitationStatus;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationInvitationRepositoryTest extends IntegrationTestCase
{
    public function testFindVisibleByOrgKeepsPendingAndRecentlyResolvedOnly(): void
    {
        $orgId = new Ulid();
        $now = new \DateTimeImmutable('2026-01-15 12:00:00');
        $cutoff = $now->sub(new \DateInterval('P7D'));

        $pending = $this->invitation($orgId, 'pending@example.org', InvitationStatus::Pending, null);
        $recentlyResolved = $this->invitation($orgId, 'recent@example.org', InvitationStatus::Accepted, $now->sub(new \DateInterval('P2D')));
        $longResolved = $this->invitation($orgId, 'old@example.org', InvitationStatus::Declined, $now->sub(new \DateInterval('P30D')));
        // A different org's pending invitation must never leak into this org's list.
        $otherOrg = $this->invitation(new Ulid(), 'other@example.org', InvitationStatus::Pending, null);
        $this->store($pending, $recentlyResolved, $longResolved, $otherOrg);

        $visible = static::getService(OrganizationInvitationRepository::class)->findVisibleByOrg($orgId, $cutoff);

        $emails = array_map(static fn (OrganizationInvitation $invitation): string => $invitation->email, $visible);
        self::assertEqualsCanonicalizing(['pending@example.org', 'recent@example.org'], $emails);
    }

    private function invitation(Ulid $orgId, string $email, InvitationStatus $status, ?\DateTimeImmutable $resolvedAt): OrganizationInvitation
    {
        $createdAt = new \DateTimeImmutable('2026-01-01 00:00:00');

        return new OrganizationInvitation(
            new Ulid(),
            $orgId,
            $email,
            mb_strtolower($email),
            $status,
            str_repeat('a', 64),
            $createdAt,
            $createdAt->add(new \DateInterval('P7D')),
            $createdAt,
            null,
            $resolvedAt,
        );
    }
}
