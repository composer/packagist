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

namespace App\ArgumentResolver;

use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Resolves a `User $teamMember` controller argument from the `teamMember` route attribute, which
 * carries the member's username rather than their id so no internal user id leaks into URLs. The
 * user is loaded together with the membership check in a single joined query scoped to the `team`
 * from the same route; a username that exists but is not in the team resolves to a 404, exactly
 * like a username that does not exist at all.
 *
 * Runs ahead of {@see UserResolver} (which claims every `User` argument) via a higher service
 * priority, so it only applies to the `teamMember` argument and leaves other users to that resolver.
 */
final readonly class TeamMemberResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationTeamMemberRepository $organizationTeamMemberRepo,
    ) {
    }

    /**
     * @return iterable<User>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (User::class !== $argument->getType() || 'teamMember' !== $argument->getName()) {
            return [];
        }

        $teamId = $request->attributes->getString('team');
        if (!Ulid::isValid($teamId)) {
            throw new NotFoundHttpException('Team member not found.');
        }

        $member = $this->organizationTeamMemberRepo->findTeamMember(Ulid::fromString($teamId), $request->attributes->getString('teamMember'));
        if (null === $member) {
            throw new NotFoundHttpException('Team member not found.');
        }

        return [$member];
    }
}
