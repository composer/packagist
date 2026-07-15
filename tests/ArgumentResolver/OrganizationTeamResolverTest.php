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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

class OrganizationTeamResolverTest extends TestCase
{
    public function testReturnsEmptyForNonTeamArgument(): void
    {
        $resolver = new OrganizationTeamResolver($this->createStub(OrganizationTeamRepository::class));

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
        $resolver = new OrganizationTeamResolver($teams);

        $request = new Request(attributes: ['organization' => 'acme', 'team' => $teamId]);

        self::assertSame([$team], $resolver->resolve($request, $this->argument()));
    }

    public function testThrowsNotFoundWhenTeamIdIsNotAUlid(): void
    {
        $teams = $this->createMock(OrganizationTeamRepository::class);
        $teams->expects(self::never())->method('findOneByOrgSlugAndTeamId');
        $resolver = new OrganizationTeamResolver($teams);

        $request = new Request(attributes: ['organization' => 'acme', 'team' => 'not-a-ulid']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsNotFoundWhenTeamDoesNotBelongToOrganization(): void
    {
        $teams = $this->createStub(OrganizationTeamRepository::class);
        // A team from another org (or a missing team) has no row for this org's slug.
        $teams->method('findOneByOrgSlugAndTeamId')->willReturn(null);
        $resolver = new OrganizationTeamResolver($teams);

        $request = new Request(attributes: ['organization' => 'acme', 'team' => (string) new Ulid()]);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
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
