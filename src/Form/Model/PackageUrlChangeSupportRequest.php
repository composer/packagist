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

use Composer\Pcre\Preg;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Everything here is checked as a string. This form never probes the URL, and must not start doing
 * so: it is reachable by anyone logged in, and the value ends up in front of an admin, so a probe
 * here would turn a support form into an outbound request generator aimed at a host the requester
 * chose. The admin's own edit form runs ValidPackageRepository and the real VCS probe on save, which
 * is where a dead or bogus URL is meant to fail.
 *
 * packageName is not validated here either: the form offers it as a choice list built from the
 * requester's own packages, so the shape and the ownership both come from that.
 */
class PackageUrlChangeSupportRequest
{
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
        $url = trim($this->repository);
        if ($url === '') {
            return;
        }

        $isScpLike = Preg::isMatch('{^[a-z0-9._-]+@[a-z0-9.-]+:[^/].*$}i', $url);
        if (!$isScpLike && !Preg::isMatch('{^(https|ssh|git\+ssh)://}i', $url)) {
            $context->buildViolation('Only https:// and ssh:// repository URLs can be requested here, or the git@host:path form.')
                ->atPath('repository')
                ->addViolation();

            return;
        }

        $host = $isScpLike
            ? (string) Preg::replace('{^[^@]*@([^:]+):.*$}', '$1', $url)
            : (string) parse_url($url, \PHP_URL_HOST);

        if ($host === '') {
            $context->buildViolation('That does not look like a repository URL.')->atPath('repository')->addViolation();

            return;
        }

        // Not a security boundary on its own -- the admin's save is -- but it keeps URLs we could
        // never act on out of the queue, and keeps an admin from being handed an internal address.
        if (
            filter_var($host, \FILTER_VALIDATE_IP) !== false
            || in_array(strtolower($host), ['localhost'], true)
            || Preg::isMatch('{\.(local|internal|localdomain)$}i', $host)
        ) {
            $context->buildViolation('The repository must be on a public host we can reach.')->atPath('repository')->addViolation();

            return;
        }

        if (!$isScpLike && parse_url($url, \PHP_URL_PORT) !== null) {
            $context->buildViolation('Repository URLs with an explicit port cannot be requested here.')->atPath('repository')->addViolation();

            return;
        }

        if (!$isScpLike && (parse_url($url, \PHP_URL_USER) !== null || parse_url($url, \PHP_URL_PASS) !== null)) {
            $context->buildViolation('Remove the credentials from the URL before requesting the change.')->atPath('repository')->addViolation();
        }
    }
}
