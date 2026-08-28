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

use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationInvitationRepository;
use App\Organization\Domain\InvitationStatus;
use App\Organization\Http\InvitationExpiredException;
use App\Organization\InvitationTokenGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Loads an {@see OrganizationInvitation} from the `invitation` route attribute, ensuring it belongs to
 * the organization named in the same route and is still actionable, i.e. pending and not past its
 * expiry. Anything else (unknown id, another organization's invitation, or one that is resolved or
 * expired) resolves to a 404, so an owner can only act on an active invitation and nothing leaks across
 * org boundaries.
 *
 * The invitee-facing routes also carry the emailed `token`; where present it is verified here, so the
 * controller can assume both a valid token and an actionable invitation. A token that matches proves the
 * visitor holds the emailed link, so rather than hiding an expired invitation behind a 404 it raises an
 * {@see InvitationExpiredException} for {@see \App\EventListener\InvitationExpiredListener} to explain.
 */
final readonly class OrganizationInvitationResolver implements ValueResolverInterface
{
    public function __construct(
        private OrganizationInvitationRepository $organizationInvitationRepo,
        private InvitationTokenGenerator $tokens,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return iterable<OrganizationInvitation>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (OrganizationInvitation::class !== $argument->getType()) {
            return [];
        }

        $invitationId = $request->attributes->getString($argument->getName());
        if (!Ulid::isValid($invitationId)) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        $invitation = $this->organizationInvitationRepo->findOneByOrgSlugAndId($request->attributes->getString('organization'), Ulid::fromString($invitationId));
        if (null === $invitation) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        $token = $request->attributes->getString('token');
        if ('' !== $token && !$this->tokens->matches($token, $invitation->tokenHash)) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        $now = $this->clock->now();
        if (!$invitation->isActive($now)) {
            // Effective status, so the answer does not change once the lazy sweep flips the row to expired.
            if ('' !== $token && $invitation->effectiveStatus($now) === InvitationStatus::Expired) {
                throw new InvitationExpiredException($invitation->expiresAt);
            }

            throw new NotFoundHttpException('Invitation not found.');
        }

        return [$invitation];
    }
}
