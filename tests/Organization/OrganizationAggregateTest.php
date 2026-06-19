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

namespace App\Tests\Organization;

use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\InvalidDisplayName;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Slug;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationAggregateTest extends TestCase
{
    public function testCreateRecordsOrganizationCreatedEvent(): void
    {
        $id = new Ulid();
        $organization = Organization::create($id, new Slug('acme'), 'ACME Corp');

        $events = $organization->pullPendingEvents();

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(OrganizationCreated::class, $event);
        self::assertTrue($id->equals($event->organizationId));
        self::assertSame('acme', $event->slug);
        self::assertSame('ACME Corp', $event->displayName);
        self::assertSame('organization-created', $event->eventType());
    }

    public function testCreateTrimsDisplayName(): void
    {
        $organization = Organization::create(new Ulid(), new Slug('acme'), '  ACME Corp  ');

        self::assertSame('ACME Corp', $organization->displayName());
        self::assertSame('acme', $organization->slug());
        self::assertFalse($organization->isDeleted());
    }

    public function testCreateRejectsInvalidDisplayName(): void
    {
        $this->expectException(InvalidDisplayName::class);

        Organization::create(new Ulid(), new Slug('acme'), 'ACME, Inc.');
    }

    public function testCreateRejectsTooLongDisplayName(): void
    {
        $this->expectException(InvalidDisplayName::class);

        Organization::create(new Ulid(), new Slug('acme'), str_repeat('a', 61));
    }

    public function testReconstituteRebuildsStateFromHistory(): void
    {
        $id = new Ulid();
        $created = Organization::create($id, new Slug('acme'), 'ACME Corp');
        $event = $created->pullPendingEvents()[0];
        self::assertInstanceOf(OrganizationCreated::class, $event);

        $reloaded = Organization::reconstitute($id, [
            ['type' => $event->eventType(), 'payload' => $event->toPayload()],
        ]);

        self::assertSame('acme', $reloaded->slug());
        self::assertSame('ACME Corp', $reloaded->displayName());
        self::assertSame(1, $reloaded->version());
        // History replay must not leave events pending to be appended again.
        self::assertCount(0, $reloaded->pullPendingEvents());
    }
}
