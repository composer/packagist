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

use App\ArgumentResolver\TeamMemberResolver;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

class TeamMemberResolverTest extends TestCase
{
    public function testReturnsEmptyForNonUserArgument(): void
    {
        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::never())->method('findTeamMember');
        $resolver = new TeamMemberResolver($teamMembers);

        $request = new Request(attributes: ['team' => (string) new Ulid(), 'teamMember' => 'jane']);

        self::assertSame([], $resolver->resolve($request, $this->argument(type: 'string')));
    }

    public function testReturnsEmptyForUserArgumentWithDifferentName(): void
    {
        // Any other User argument stays with UserResolver; only `teamMember` belongs to this resolver.
        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::never())->method('findTeamMember');
        $resolver = new TeamMemberResolver($teamMembers);

        $request = new Request(attributes: ['team' => (string) new Ulid(), 'teamMember' => 'jane']);

        self::assertSame([], $resolver->resolve($request, $this->argument(name: 'user')));
    }

    public function testResolvesMemberByTeamIdAndUsername(): void
    {
        $member = new User();
        $teamId = new Ulid();

        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::once())
            ->method('findTeamMember')
            // The raw username from the URL is passed through; the repository canonicalises it.
            ->with(self::callback(static fn (Ulid $id): bool => $id->equals($teamId)), 'JANE')
            ->willReturn($member);
        $resolver = new TeamMemberResolver($teamMembers);

        $request = new Request(attributes: ['team' => (string) $teamId, 'teamMember' => 'JANE']);

        self::assertSame([$member], $resolver->resolve($request, $this->argument()));
    }

    public function testThrowsNotFoundWhenTeamIdIsNotAUlid(): void
    {
        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::never())->method('findTeamMember');
        $resolver = new TeamMemberResolver($teamMembers);

        $request = new Request(attributes: ['team' => 'not-a-ulid', 'teamMember' => 'jane']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    public function testThrowsNotFoundWhenUserIsNotAMemberOfTheTeam(): void
    {
        $teamMembers = $this->createStub(OrganizationTeamMemberRepository::class);
        // A non-member (or missing user) has no membership row in the joined query.
        $teamMembers->method('findTeamMember')->willReturn(null);
        $resolver = new TeamMemberResolver($teamMembers);

        $request = new Request(attributes: ['team' => (string) new Ulid(), 'teamMember' => 'jane']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    private function argument(string $name = 'teamMember', ?string $type = User::class): ArgumentMetadata
    {
        return new ArgumentMetadata($name, $type, false, false, null);
    }
}
