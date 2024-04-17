<?php

namespace App\Security\Voter;

use App\Entity\Package;
use App\Entity\User;
use App\Model\DownloadManager;
use Predis\Connection\ConnectionException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class PackageVoter extends \Symfony\Component\Security\Core\Authorization\Voter\Voter
{
    public function __construct(
        private Security $security,
        private DownloadManager $downloadManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Package && PackageActions::tryFrom($attribute) instanceof PackageActions;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // the user must be logged in
        if (!$user instanceof User) {
            return false;
        }

        /** @var Package $package */
        $package = $subject;

        return match(PackageActions::from($attribute)) {
            PackageActions::Abandon => $this->canEdit($package, $user),
            PackageActions::Delete => $this->canDelete($package, $user),
            PackageActions::DeleteVersion => $this->canDeleteVersion($package, $user),
            PackageActions::Edit => $this->canEdit($package, $user),
            PackageActions::AddMaintainer => $this->canAddMaintainers($package, $user),
            PackageActions::RemoveMaintainer => $this->canRemoveMaintainers($package, $user),
            PackageActions::Update => $package->isMaintainer($user) || $this->security->isGranted('ROLE_UPDATE_PACKAGES'),
        };
    }

    private function canDelete(Package $package, User $user): bool
    {
        if ($this->security->isGranted('ROLE_DELETE_PACKAGES')) {
            return true;
        }

        // non maintainers can not delete
        if (!$package->isMaintainer($user)) {
            return false;
        }

        try {
            $downloads = $this->downloadManager->getTotalDownloads($package);
        } catch (ConnectionException $e) {
            return false;
        }

        // more than 1000 downloads = established package, do not allow deletion by maintainers
        if ($downloads > 1_000) {
            return false;
        }

        return true;
    }

    private function canDeleteVersion(Package $package, User $user): bool
    {
        if ($this->security->isGranted('ROLE_DELETE_PACKAGES')) {
            return true;
        }

        // only maintainers can delete
        return $package->isMaintainer($user);
    }

    private function canEdit(Package $package, User $user): bool
    {
        if ($this->security->isGranted('ROLE_EDIT_PACKAGES')) {
            return true;
        }

        // non maintainers can not edit
        if (!$package->isMaintainer($user)) {
            return false;
        }

        return true;
    }

    private function canAddMaintainers(Package $package, User $user): bool
    {
        if ($this->security->isGranted('ROLE_EDIT_PACKAGES')) {
            return true;
        }

        if (!$package->isMaintainer($user)) {
            return false;
        }

        return true;
    }

    private function canRemoveMaintainers(Package $package, User $user): bool
    {
        // at least one has to remain
        if (1 === $package->getMaintainers()->count()) {
            return false;
        }

        return $this->canAddMaintainers($package, $user);
    }

    public function supportsAttribute(string $attribute): bool
    {
        return PackageActions::tryFrom($attribute) instanceof PackageActions;
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Package::class, true);
    }
}
