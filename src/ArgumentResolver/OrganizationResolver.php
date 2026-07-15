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

use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\SlugReservationRepository;
use App\Organization\Http\OrganizationRenamedException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads an {@see Organization} from its slug route attribute.
 */
final readonly class OrganizationResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationRepository $organizationRepo,
        private SlugReservationRepository $slugReservationRepo,
        private Security $security,
    ) {
    }

    /**
     * @return iterable<Organization>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Organization::class !== $argument->getType()) {
            return [];
        }

        $slug = $request->attributes->getString($argument->getName());

        $organization = $this->organizationRepo->findOneBySlug($slug);
        if (null === $organization) {
            $rename = $this->slugReservationRepo->findActiveRename($slug);
            if (null !== $rename) {
                $target = $this->organizationRepo->find($rename->orgId);
                if (null !== $target && (!$target->isDeleted() || $this->security->isGranted('ROLE_ADMIN_ORGS'))) {
                    throw new OrganizationRenamedException($target->slug);
                }
            }

            throw new NotFoundHttpException('Organization not found.');
        }

        if ($organization->isDeleted() && !$this->security->isGranted('ROLE_ADMIN_ORGS')) {
            throw new GoneHttpException('This organization was deleted.');
        }

        return [$organization];
    }
}
