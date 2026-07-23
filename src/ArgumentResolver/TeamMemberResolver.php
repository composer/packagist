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
 * Resolves a `User $teamMember` argument from the `teamMember` route attribute, which carries the
 * username (not an id) so no user id leaks into URLs. A username that is not in the team resolves to
 * a 404, exactly like an unknown one.
 *
 * Runs ahead of {@see UserResolver} via a higher service priority.
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
