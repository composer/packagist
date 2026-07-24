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

use App\Entity\OrganizationRepository;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use App\Security\Voter\OrganizationActions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a `User $organizationMember` argument from the `organizationMember` route attribute, which
 * carries the username (not an id) so no user id leaks into URLs. A username that is not a member of
 * the org resolves to a 404, exactly like an unknown one.
 *
 * As defense in depth the organization is loaded first and the current user must be able to read it,
 * so a member only resolves for someone who can view the org.
 *
 * Runs ahead of {@see UserResolver} via a higher service priority.
 */
final readonly class OrganizationMemberResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private OrganizationRepository $organizationRepo,
        private Security $security,
    ) {
    }

    /**
     * @return iterable<User>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (User::class !== $argument->getType() || 'organizationMember' !== $argument->getName()) {
            return [];
        }

        $slug = $request->attributes->getString('organization');

        $organization = $this->organizationRepo->findOneBySlug($slug);
        if (null === $organization) {
            throw new NotFoundHttpException('Member not found.');
        }

        if (!$this->security->isGranted(OrganizationActions::View->value, $organization)) {
            throw new NotFoundHttpException('Member not found.');
        }

        $member = $this->organizationTeamMemberRepo->findOrgMember($slug, $request->attributes->getString('organizationMember'));
        if (null === $member) {
            throw new NotFoundHttpException('Member not found.');
        }

        return [$member];
    }
}
