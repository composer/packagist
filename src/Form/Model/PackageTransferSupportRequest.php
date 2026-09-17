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

namespace App\Form\Model;

use App\Entity\Package;
use Composer\Pcre\Preg;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PackageTransferSupportRequest
{
    public const int MAX_PACKAGES = 50;

    #[Assert\NotBlank(message: 'List at least one package.')]
    public string $packageNames = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    public string $description = '';

    /**
     * Only the shape of each line is checked, not whether the package exists: requesters routinely
     * name packages that were renamed or are not on the site yet, and bouncing those would turn a
     * legitimate request away. The admin queue resolves the names against real packages when it
     * renders the task, which is where knowing the current maintainers actually matters.
     */
    #[Assert\Callback]
    public function validatePackageNames(ExecutionContextInterface $context): void
    {
        $lines = $this->packageNameList();

        if (\count($lines) > self::MAX_PACKAGES) {
            $context->buildViolation('Please list at most '.self::MAX_PACKAGES.' packages, or get in touch by email instead.')
                ->atPath('packageNames')
                ->addViolation();

            return;
        }

        $invalid = array_filter($lines, static fn (string $line): bool => !Preg::isMatch('{^'.Package::PACKAGE_NAME_REGEX.'$}', $line));
        if ($invalid !== []) {
            $context->buildViolation('These do not look like package names (expected "vendor/name"): '.implode(', ', $invalid))
                ->atPath('packageNames')
                ->addViolation();
        }
    }

    /** @return list<string> */
    public function packageNameList(): array
    {
        return array_values(array_filter(
            array_map(trim(...), Preg::split('{\r?\n}', $this->packageNames)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
