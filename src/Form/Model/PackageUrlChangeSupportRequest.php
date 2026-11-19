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
use App\Support\Attributes\PackageUrlChange;
use Composer\Pcre\Preg;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Checked as strings only: probing the URL here would let any user aim outbound requests at a host
 * of their choosing. The admin's edit form does the real VCS probe on save.
 */
class PackageUrlChangeSupportRequest
{
    #[Assert\NotBlank]
    public string $packageName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $repository = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    public string $description = '';

    #[Assert\Callback]
    public function validateRepository(ExecutionContextInterface $context): void
    {
        // Checked in the form the admin's save will store, so nothing passes here that it would reject.
        $url = Package::normalizeRepositoryUrl(trim($this->repository));
        if ($url === '') {
            return;
        }

        if (!str_starts_with($url, 'https://')) {
            $context->buildViolation('Only https:// repository URLs are supported. git@ URLs for GitHub, GitLab and Bitbucket are converted automatically.')
                ->atPath('repository')
                ->addViolation();

            return;
        }

        $host = PackageUrlChange::hostOf($url);

        if ($host === null) {
            $context->buildViolation('That does not look like a repository URL.')->atPath('repository')->addViolation();

            return;
        }

        // Not a security boundary on its own -- the admin's save is -- but it keeps URLs we could
        // never act on out of the queue, and keeps an admin from being handed an internal address.
        // Requiring a named TLD also rules out IP literals in every notation (IPv6, decimal, hex).
        if (
            !Preg::isMatch('{\.\p{L}[\p{L}\p{N}-]*$}u', $host)
            || Preg::isMatch('{\.(local|internal|localdomain)$}i', $host)
        ) {
            $context->buildViolation('The repository must be on a public host we can reach.')->atPath('repository')->addViolation();

            return;
        }

        if (parse_url($url, \PHP_URL_PORT) !== null) {
            $context->buildViolation('Repository URLs with an explicit port cannot be requested here.')->atPath('repository')->addViolation();

            return;
        }

        if (parse_url($url, \PHP_URL_USER) !== null || parse_url($url, \PHP_URL_PASS) !== null) {
            $context->buildViolation('Remove the credentials from the URL before requesting the change.')->atPath('repository')->addViolation();
        }
    }
}
