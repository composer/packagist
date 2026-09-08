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

use App\ArgumentResolver\OrganizationMemberResolver;
use App\Entity\Organization;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationStatus;
use App\Entity\User;
use App\Security\Voter\OrganizationActions;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

class OrganizationMemberResolverTest extends TestCase
{
    public function testReturnsEmptyForNonUserArgument(): void
    {
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->expects(self::never())->method('findOrgMember');
        $resolver = new OrganizationMemberResolver($members, $this->createStub(OrganizationRepository::class), $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        self::assertSame([], $resolver->resolve($request, $this->argument(type: 'string')));
    }

    public function testReturnsEmptyForUserArgumentWithDifferentName(): void
    {
        // Any other User argument stays with UserResolver; only `organizationMember` belongs here.
        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->expects(self::never())->method('findOrgMember');
        $resolver = new OrganizationMemberResolver($members, $this->createStub(OrganizationRepository::class), $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        self::assertSame([], $resolver->resolve($request, $this->argument(name: 'user')));
    }

    public function testResolvesMemberByOrganizationAndUsername(): void
    {
        $member = new User();
        $organization = $this->organization();

        $organizations = $this->createMock(OrganizationRepository::class);
        $organizations->expects(self::once())
            ->method('findOneBySlug')
            ->with('acme')
            ->willReturn($organization);

        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->expects(self::once())
            ->method('findOrgMember')
            ->with($organization->id, 'JANE')
            ->willReturn($member);

        // Read access to the organization is required as defense in depth.
        $security = $this->createMock(Security::class);
        $security->expects(self::once())
            ->method('isGranted')
            ->with(OrganizationActions::View->value, $organization)
            ->willReturn(true);
        $resolver = new OrganizationMemberResolver($members, $organizations, $security);

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'JANE']);

        self::assertSame([$member], $resolver->resolve($request, $this->argument()));
    }

    public function testThrowsNotFoundWhenOrganizationDoesNotExist(): void
    {
        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findOneBySlug')->willReturn(null);

        $members = $this->createMock(OrganizationMemberRepository::class);
        $members->expects(self::never())->method('findOrgMember');
        $resolver = new OrganizationMemberResolver($members, $organizations, $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsNotFoundWhenUserCannotViewOrganization(): void
    {
        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findOneBySlug')->willReturn($this->organization());

        $members = $this->createMock(OrganizationMemberRepository::class);
        // A user who cannot read the org must not learn the member exists.
        $members->expects(self::never())->method('findOrgMember');
        $resolver = new OrganizationMemberResolver($members, $organizations, $this->security(false));

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsNotFoundWhenUserIsNotAMemberOfTheOrg(): void
    {
        $organizations = $this->createStub(OrganizationRepository::class);
        $organizations->method('findOneBySlug')->willReturn($this->organization());

        $members = $this->createStub(OrganizationMemberRepository::class);
        // A non-member (or missing user) has no membership row in the joined query.
        $members->method('findOrgMember')->willReturn(null);
        $resolver = new OrganizationMemberResolver($members, $organizations, $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    private function security(bool $granted): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return $security;
    }

    private function organization(): Organization
    {
        return new Organization(
            new Ulid(),
            'acme',
            'ACME Corp',
            OrganizationStatus::Active,
            new \DateTimeImmutable(),
            new Ulid(),
            new Ulid(),
        );
    }

    private function argument(string $name = 'organizationMember', ?string $type = User::class): ArgumentMetadata
    {
        return new ArgumentMetadata($name, $type, false, false, null);
    }
}
