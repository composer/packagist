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

namespace App\Support;

use App\Entity\Package;
use App\Entity\SupportRequestMessage;
use App\Entity\SupportRequestRepository;
use App\Entity\User;
use App\Support\Attributes\PackageUrlChangeAttributes;
use Doctrine\Persistence\ManagerRegistry;

class UrlChangeRequestTracker
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SupportRequestRepository $supportRequests,
        private readonly SupportNotifier $notifier,
    ) {
    }

    /**
     * Notes an applied URL change on its support request, then resolves it and emails the requester
     * once every package has moved. Not in PackageListener, which also fires for webhook rewrites.
     */
    public function noteAppliedChange(Package $package, string $previousUrl, string $publicId, ?User $actor): void
    {
        if ($publicId === '' || $previousUrl === $package->getRepository()) {
            return;
        }

        $request = $this->supportRequests->findOneByPublicId($publicId);
        if ($request === null || !$request->isOpen() || $request->type !== SupportRequestType::PackageUrlChange) {
            return;
        }

        $attributes = $request->attributesOf(PackageUrlChangeAttributes::class);
        if (!\in_array($package->getName(), $attributes->names(), true)) {
            return;
        }

        $em = $this->doctrine->getManager();
        $em->persist(SupportRequestMessage::internalNote(
            $request,
            'Repository for '.$package->getName().' changed from '.$previousUrl.' to '.$package->getRepository().'.',
            $actor,
        ));

        $now = new \DateTimeImmutable();
        $request->touch($now);

        $reply = null;
        $repo = $this->doctrine->getRepository(Package::class);
        if ($attributes->isFullyApplied(static fn (string $name): ?Package => $repo->findOneBy(['name' => $name]))) {
            $reply = SupportRequestMessage::reply($request, SupportRequestType::urlChangeAppliedReply($attributes), $actor);
            $em->persist($reply);
            $request->resolve($now);
        }

        $em->flush();

        if ($reply !== null) {
            $this->notifier->notifyUserOfReply($request, $reply);
        }
    }
}
