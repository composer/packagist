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

use App\ArgumentResolver\OrganizationTeamResolver;
use App\Entity\Organization;
use App\Entity\OrganizationStatus;
use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamRepository;
use App\Organization\Domain\OrganizationTeamKind;
use App\Security\Voter\OrganizationActions;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

class OrganizationTeamResolverTest extends TestCase
{
    public function testReturnsEmptyForNonTeamArgument(): void
    {
        $resolver = new OrganizationTeamResolver($this->createStub(OrganizationTeamRepository::class), $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'team' => (string) new Ulid()]);

        self::assertSame([], $resolver->resolve($request, $this->argument(type: 'string')));
    }

    public function testResolvesTeamByOrgSlugAndTeamId(): void
    {
        $team = $this->team();
        $teamId = (string) $team->teamId;

        $teams = $this->createMock(OrganizationTeamRepository::class);
        $teams->expects(self::once())
            ->method('findOneByOrgSlugAndTeamId')
            ->with('acme', self::callback(static fn (Ulid $id): bool => (string) $id === $teamId))
            ->willReturn($team);

        // Read access to the team's own organization is required as defense in depth.
        $security = $this->createMock(Security::class);
        $security->expects(self::once())
            ->method('isGranted')
            ->with(OrganizationActions::View->value, $team->organization)
            ->willReturn(true);
        $resolver = new OrganizationTeamResolver($teams, $security);

        $request = new Request(attributes: ['organization' => 'acme', 'team' => $teamId]);

        self::assertSame([$team], $resolver->resolve($request, $this->argument()));
    }

    public function testThrowsNotFoundWhenTeamIdIsNotAUlid(): void
    {
        $teams = $this->createMock(OrganizationTeamRepository::class);
        $teams->expects(self::never())->method('findOneByOrgSlugAndTeamId');
        $resolver = new OrganizationTeamResolver($teams, $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'team' => 'not-a-ulid']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsNotFoundWhenTeamDoesNotBelongToOrganization(): void
    {
        $teams = $this->createStub(OrganizationTeamRepository::class);
        // A team from another org (or a missing team) has no row for this org's slug.
        $teams->method('findOneByOrgSlugAndTeamId')->willReturn(null);
        $resolver = new OrganizationTeamResolver($teams, $this->security(true));

        $request = new Request(attributes: ['organization' => 'acme', 'team' => (string) new Ulid()]);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsNotFoundWhenUserCannotViewOrganization(): void
    {
        $team = $this->team();

        $teams = $this->createStub(OrganizationTeamRepository::class);
        $teams->method('findOneByOrgSlugAndTeamId')->willReturn($team);
        // A user who cannot read the team's org must not learn the team exists.
        $resolver = new OrganizationTeamResolver($teams, $this->security(false));

        $request = new Request(attributes: ['organization' => 'acme', 'team' => (string) $team->teamId]);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    private function security(bool $granted): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return $security;
    }

    private function argument(string $name = 'team', ?string $type = OrganizationTeam::class): ArgumentMetadata
    {
        return new ArgumentMetadata($name, $type, false, false, null);
    }

    private function team(): OrganizationTeam
    {
        $organization = new Organization(
            new Ulid(),
            'acme',
            'ACME Corp',
            OrganizationStatus::Active,
            new \DateTimeImmutable(),
            new Ulid(),
            new Ulid(),
        );

        return new OrganizationTeam(
            new Ulid(),
            $organization,
            OrganizationTeamKind::Custom,
            'backend',
            null,
            new \DateTimeImmutable(),
        );
    }
}
