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

/**
 * Resolves a `User $organizationMember` controller argument from the `organizationMember` route
 * attribute, which carries the member's username rather than their id so no internal user id leaks
 * into URLs. The user is loaded together with the membership check in a single joined query scoped
 * to the `organization` slug from the same route; a username that exists but is not a member of the
 * org resolves to a 404, exactly like a username that does not exist at all.
 *
 * Runs ahead of {@see UserResolver} (which claims every `User` argument) via a higher service
 * priority, so it only applies to the `organizationMember` argument and leaves other users to it.
 */
final readonly class OrganizationMemberResolver implements ValueResolverInterface
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
        if (User::class !== $argument->getType() || 'organizationMember' !== $argument->getName()) {
            return [];
        }

        $member = $this->organizationTeamMemberRepo->findOrgMember($request->attributes->getString('organization'), $request->attributes->getString('organizationMember'));
        if (null === $member) {
            throw new NotFoundHttpException('Member not found.');
        }

        return [$member];
    }
}
