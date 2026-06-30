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

use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Event\OrganizationNameChanged;
use App\Organization\Domain\Event\OrganizationSlugChanged;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Slug;
use App\Organization\EventStore\OrganizationEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationAggregateTest extends TestCase
{
    public function testCreateRecordsOrganizationCreatedEvent(): void
    {
        $id = new Ulid();
        $organization = Organization::create($id, new Slug('acme'), new DisplayName('ACME Corp'));

        $events = $organization->pullPendingEvents();

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(OrganizationCreated::class, $event);
        self::assertTrue($id->equals($event->organizationId));
        self::assertSame('acme', $event->slug);
        self::assertSame('ACME Corp', $event->displayName);
        self::assertSame(OrganizationEventType::OrganizationCreated, $event->eventType());
    }

    public function testReconstituteRebuildsStateFromHistory(): void
    {
        $id = new Ulid();
        $created = Organization::create($id, new Slug('acme'), new DisplayName('ACME Corp'));
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

    public function testRenameRecordsEventWithPreviousName(): void
    {
        $id = new Ulid();
        $organization = $this->reconstituted($id, 'acme', 'ACME Corp');

        $organization->rename(new DisplayName('ACME Inc'));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrganizationNameChanged::class, $events[0]);
        self::assertSame('ACME Inc', $events[0]->displayName);
        self::assertSame('ACME Corp', $events[0]->previousDisplayName);
        self::assertSame('ACME Inc', $organization->displayName());
    }

    public function testChangeSlugRecordsEventWithPreviousSlug(): void
    {
        $id = new Ulid();
        $organization = $this->reconstituted($id, 'acme', 'ACME Corp');

        $organization->changeSlug(new Slug('acme-inc'));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrganizationSlugChanged::class, $events[0]);
        self::assertSame('acme-inc', $events[0]->slug);
        self::assertSame('acme', $events[0]->previousSlug);
        self::assertSame('acme-inc', $organization->slug());
    }

    public function testRenameToSameNameIsNoop(): void
    {
        $organization = $this->reconstituted(new Ulid(), 'acme', 'ACME Corp');

        $organization->rename(new DisplayName('ACME Corp'));

        self::assertCount(0, $organization->pullPendingEvents());
    }

    public function testChangeSlugToSameSlugIsNoop(): void
    {
        $organization = $this->reconstituted(new Ulid(), 'acme', 'ACME Corp');

        $organization->changeSlug(new Slug('acme'));

        self::assertCount(0, $organization->pullPendingEvents());
    }

    public function testReconstituteReplaysRenameAndSlugChange(): void
    {
        $id = new Ulid();

        $reloaded = Organization::reconstitute($id, [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => ['slug' => 'acme', 'displayName' => 'ACME Corp']],
            ['type' => OrganizationEventType::OrganizationRenamed, 'payload' => ['displayName' => 'ACME Inc', 'previousDisplayName' => 'ACME Corp']],
            ['type' => OrganizationEventType::OrganizationSlugChanged, 'payload' => ['slug' => 'acme-inc', 'previousSlug' => 'acme']],
        ]);

        self::assertSame('acme-inc', $reloaded->slug());
        self::assertSame('ACME Inc', $reloaded->displayName());
        self::assertSame(3, $reloaded->version());
        self::assertCount(0, $reloaded->pullPendingEvents());
    }

    private function reconstituted(Ulid $id, string $slug, string $displayName): Organization
    {
        return Organization::reconstitute($id, [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => ['slug' => $slug, 'displayName' => $displayName]],
        ]);
    }
}
