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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Loads an {@see OrganizationTeam} from the `team` route attribute, ensuring it belongs to the
 * organization named in the same route. A team from another organization resolves to a 404 rather
 * than leaking across org boundaries. The team is fetched by org slug + team id in a single query,
 * so the organization is not loaded twice.
 */
final readonly class OrganizationTeamResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationTeamRepository $teams,
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

        $team = $this->teams->findOneByOrgSlugAndTeamId($request->attributes->getString('organization'), Ulid::fromString($teamId));
        if (null === $team) {
            throw new NotFoundHttpException('Team not found.');
        }

        return [$team];
    }
}
