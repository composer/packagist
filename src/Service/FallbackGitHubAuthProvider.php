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

namespace App\Service;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

class FallbackGitHubAuthProvider
{
    public function __construct(
        /** @var list<string> */
        private array $fallbackGhTokens,
        private ManagerRegistry $doctrine,
    ) {
    }

    public function getAuthToken(): ?string
    {
        if ($this->fallbackGhTokens) {
            $fallbackUser = $this->doctrine->getRepository(User::class)->findOneBy(['usernameCanonical' => $this->fallbackGhTokens[random_int(0, count($this->fallbackGhTokens) - 1)]]);
            if (null === $fallbackUser) {
                throw new \LogicException('Invalid fallback user was not found');
            }
            $fallbackToken = $fallbackUser->getGithubToken();
            if (null === $fallbackToken) {
                throw new \LogicException('Invalid fallback user '.$fallbackUser->getUsername().' has no token');
            }

            return $fallbackToken;
        }

        return null;
    }
}
