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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class VendorWritableValidator extends ConstraintValidator
{
    public function __construct(
        private PackageRepository $packageRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof VendorWritable) {
            throw new UnexpectedTypeException($constraint, VendorWritable::class);
        }

        // custom constraints should ignore null and empty values to allow
        // other constraints (NotBlank, NotNull, etc.) to take care of that
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedValueException($value, Package::class);
        }

        // a moderator assigning the package vouches for the namespace; the PackageCreated audit
        // record names them. Their own submissions stay subject to the check.
        if ($value->getSubmittedOnBehalfOf() !== null) {
            return;
        }

        try {
            $vendor = $value->getVendor();
            if ($vendor && $this->packageRepository->isVendorTaken($vendor, $value->getMaintainers()->first() ?: null)) {
                $this->context->buildViolation('The vendor name "'.$vendor.'" was already claimed by someone else on Packagist.org. '
                        .'You may ask them to add your package and give you maintainership access. '
                        .'If they add you as a maintainer on any package in that vendor namespace, '
                        .'you will then be able to add new packages in that namespace. '
                        .'The packages already in that vendor namespace can be found at '
                        .'<a href="'.$this->urlGenerator->generate('view_vendor', ['vendor' => $vendor]).'">'.$vendor.'</a>.'
                        .'If those packages belong to you but were submitted by someone else, you can '
                        .'<a href="'.$this->urlGenerator->generate('support_vendor_claim', ['vendor' => $vendor]).'">request the "'.$vendor.'" namespace</a>.')
                    ->atPath('repository')
                    ->addViolation()
                ;
            }
        } catch (\Doctrine\ORM\NoResultException $e) {
        }
    }
}
