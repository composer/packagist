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

namespace App\Tests\ArgumentResolver;

use App\ArgumentResolver\OrganizationResolver;
use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationStatus;
use App\Entity\SlugReservation;
use App\Entity\SlugReservationKind;
use App\Entity\SlugReservationRepository;
use App\Organization\Http\OrganizationRenamedException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

class OrganizationResolverTest extends TestCase
{
    public function testReturnsEmptyForNonOrganizationArgument(): void
    {
        $organizationRepo = $this->createStub(OrganizationRepository::class);
        $resolver = new OrganizationResolver($organizationRepo, $this->reservations(), $this->createStub(Security::class));

        $request = new Request(attributes: ['slug' => 'acme']);

        self::assertSame([], $resolver->resolve($request, $this->argument(type: 'string')));
    }

    public function testResolvesActiveOrganizationBySlug(): void
    {
        $organization = $this->organization('acme');
        $organizationRepo = $this->createStub(OrganizationRepository::class);
        $organizationRepo->method('findOneBySlug')->willReturn($organization);
        $resolver = new OrganizationResolver($organizationRepo, $this->reservations(), $this->createStub(Security::class));

        $request = new Request(attributes: ['organization' => 'acme']);

        self::assertSame([$organization], $resolver->resolve($request, $this->argument()));
    }

    public function testThrowsNotFoundWhenSlugDoesNotMatch(): void
    {
        $organizationRepo = $this->createStub(OrganizationRepository::class);
        $organizationRepo->method('findOneBySlug')->willReturn(null);
        $resolver = new OrganizationResolver($organizationRepo, $this->reservations(), $this->createStub(Security::class));

        $request = new Request(attributes: ['organization' => 'missing']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testRedirectsToCurrentSlugWhenActiveRenameReservationExists(): void
    {
        $current = $this->organization('acme-inc');
        $reservation = new SlugReservation(new Ulid(), 'acme', $current->id, SlugReservationKind::RenamedFrom, new \DateTimeImmutable());

        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findOneBySlug')->willReturn(null);
        $organizations->method('find')->willReturn($current);
        $reservations = $this->createStub(SlugReservationRepository::class);
        $reservations->method('findActiveRename')->willReturn($reservation);
        $resolver = new OrganizationResolver($organizations, $reservations, $this->createStub(Security::class));

        $request = new Request(attributes: ['organization' => 'acme']);

        try {
            $resolver->resolve($request, $this->argument());
            self::fail('Expected OrganizationRenamedException.');
        } catch (OrganizationRenamedException $e) {
            self::assertSame('acme-inc', $e->currentSlug);
        }
    }

    public function testThrowsNotFoundWhenRenameTargetIsDeleted(): void
    {
        $reservation = new SlugReservation(new Ulid(), 'acme', new Ulid(), SlugReservationKind::RenamedFrom, new \DateTimeImmutable());

        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findOneBySlug')->willReturn(null);
        $organizations->method('find')->willReturn($this->organization('acme-inc', deleted: true));
        $reservations = $this->createStub(SlugReservationRepository::class);
        $reservations->method('findActiveRename')->willReturn($reservation);
        $resolver = new OrganizationResolver($organizations, $reservations, $this->createStub(Security::class));

        $request = new Request(attributes: ['organization' => 'acme']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsGoneForDeletedOrganizationWhenNotAdmin(): void
    {
        $organizationRepo = $this->createStub(OrganizationRepository::class);
        $organizationRepo->method('findOneBySlug')->willReturn($this->organization('acme', deleted: true));
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);
        $resolver = new OrganizationResolver($organizationRepo, $this->reservations(), $security);

        $request = new Request(attributes: ['organization' => 'acme']);

        $this->expectException(GoneHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testReturnsDeletedOrganizationForAdmin(): void
    {
        $organization = $this->organization('acme', deleted: true);
        $organizationRepo = $this->createStub(OrganizationRepository::class);
        $organizationRepo->method('findOneBySlug')->willReturn($organization);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);
        $resolver = new OrganizationResolver($organizationRepo, $this->reservations(), $security);

        $request = new Request(attributes: ['organization' => 'acme']);

        self::assertSame([$organization], $resolver->resolve($request, $this->argument()));
    }

    private function argument(string $name = 'organization', ?string $type = Organization::class): ArgumentMetadata
    {
        return new ArgumentMetadata($name, $type, false, false, null);
    }

    private function reservations(): SlugReservationRepository
    {
        return $this->createStub(SlugReservationRepository::class);
    }

    private function organization(string $slug, bool $deleted = false): Organization
    {
        return new Organization(
            new Ulid(),
            $slug,
            'ACME Corp',
            $deleted ? OrganizationStatus::Deleted : OrganizationStatus::Active,
            new \DateTimeImmutable(),
            new Ulid(),
            new Ulid(),
            $deleted ? new \DateTimeImmutable() : null,
            $deleted ? 'owner' : null,
        );
    }
}
