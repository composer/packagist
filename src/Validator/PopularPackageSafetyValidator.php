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

namespace App\Validator;

use App\Entity\Package;
use App\Entity\PackageRepository;
use App\Entity\User;
use App\Model\DownloadManager;
use Predis\Connection\ConnectionException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class PopularPackageSafetyValidator extends ConstraintValidator
{
    public function __construct(
        private DownloadManager $downloadManager,
        private Security $security,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PopularPackageSafety) {
            throw new UnexpectedTypeException($constraint, PopularPackageSafety::class);
        }

        // custom constraints should ignore null and empty values to allow
        // other constraints (NotBlank, NotNull, etc.) to take care of that
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedValueException($value, Package::class);
        }

        if ($this->security->isGranted('ROLE_EDIT_PACKAGES')) {
            return;
        }

        // bypass download check for some accounts which requested it
        $user = $this->security->getUser();
        if ($user instanceof User && in_array($user->getUsernameCanonical(), [], true)) {
            return;
        }

        try {
            $downloads = $this->downloadManager->getTotalDownloads($value);
        } catch (ConnectionException $e) {
            $downloads = PHP_INT_MAX;
        }

        // more than 50000 downloads = established package, do not allow editing URL anymore
        if ($downloads > 50_000) {
            $this->context->buildViolation($constraint->message)
                ->atPath('repository')
                ->addViolation()
            ;
        }
    }
}
