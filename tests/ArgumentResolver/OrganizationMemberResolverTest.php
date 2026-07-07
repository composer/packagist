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
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationMemberResolverTest extends TestCase
{
    public function testReturnsEmptyForNonUserArgument(): void
    {
        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::never())->method('findOrgMember');
        $resolver = new OrganizationMemberResolver($teamMembers);

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        self::assertSame([], $resolver->resolve($request, $this->argument(type: 'string')));
    }

    public function testReturnsEmptyForUserArgumentWithDifferentName(): void
    {
        // Any other User argument stays with UserResolver; only `organizationMember` belongs here.
        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::never())->method('findOrgMember');
        $resolver = new OrganizationMemberResolver($teamMembers);

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        self::assertSame([], $resolver->resolve($request, $this->argument(name: 'user')));
    }

    public function testResolvesMemberByOrgSlugAndCanonicalUsername(): void
    {
        $member = new User();

        $teamMembers = $this->createMock(OrganizationTeamMemberRepository::class);
        $teamMembers->expects(self::once())
            ->method('findOrgMember')
            // The username from the URL is lowercased to match the canonical column.
            ->with('acme', 'jane')
            ->willReturn($member);
        $resolver = new OrganizationMemberResolver($teamMembers);

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'JANE']);

        self::assertSame([$member], $resolver->resolve($request, $this->argument()));
    }

    public function testThrowsNotFoundWhenUserIsNotAMemberOfTheOrg(): void
    {
        $teamMembers = $this->createStub(OrganizationTeamMemberRepository::class);
        // A non-member (or missing user) has no membership row in the joined query.
        $teamMembers->method('findOrgMember')->willReturn(null);
        $resolver = new OrganizationMemberResolver($teamMembers);

        $request = new Request(attributes: ['organization' => 'acme', 'organizationMember' => 'jane']);

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve($request, $this->argument());
    }

    private function argument(string $name = 'organizationMember', ?string $type = User::class): ArgumentMetadata
    {
        return new ArgumentMetadata($name, $type, false, false, null);
    }
}
