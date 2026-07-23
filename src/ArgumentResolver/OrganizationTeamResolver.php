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

use App\Entity\OrganizationTeam;
use App\Entity\OrganizationTeamRepository;
use App\Security\Voter\OrganizationActions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Loads an {@see OrganizationTeam} from the `team` route attribute, scoped to the organization named
 * in the same route. A team from another org resolves to a 404 rather than leaking across boundaries.
 *
 * As defense in depth the current user must also be able to read the team's organization, so a team
 * only surfaces to someone who can view that org.
 */
final readonly class OrganizationTeamResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationTeamRepository $organizationTeamRepo,
        private Security $security,
    ) {
    }

    /**
     * @return iterable<OrganizationTeam>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (OrganizationTeam::class !== $argument->getType()) {
            return [];
        }

        $teamId = $request->attributes->getString($argument->getName());
        if (!Ulid::isValid($teamId)) {
            throw new NotFoundHttpException('Team not found.');
        }

        $team = $this->organizationTeamRepo->findOneByOrgSlugAndTeamId($request->attributes->getString('organization'), Ulid::fromString($teamId));
        if (null === $team) {
            throw new NotFoundHttpException('Team not found.');
        }

        if (!$this->security->isGranted(OrganizationActions::View->value, $team->organization)) {
            throw new NotFoundHttpException('Team not found.');
        }

        return [$team];
    }
}
