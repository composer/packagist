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

namespace App\Security\Api;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates API requests by username and API token, from a `Bearer username:apiToken` header
 * or from username/apiToken request or query parameters.
 */
class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function supports(Request $request): bool
    {
        return null !== self::getCredentials($request);
    }

    public function authenticate(Request $request): Passport
    {
        [$username, $apiToken] = self::getCredentials($request) ?? throw new BadCredentialsException();

        $passport = new SelfValidatingPassport(new UserBadge($username, function (string $username) use ($apiToken): ?User {
            $user = $this->doctrine->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.usernameCanonical = :username')
                ->andWhere('(u.apiToken = :apiToken OR u.safeApiToken = :apiToken)')
                ->setParameter('username', $username)
                ->setParameter('apiToken', $apiToken)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            // the column collation is case-insensitive, so make sure the token matches exactly
            if (!$user instanceof User || (!hash_equals($user->getApiToken(), $apiToken) && !hash_equals($user->getSafeApiToken(), $apiToken))) {
                return null;
            }

            return $user;
        }));
        $passport->setAttribute('apiToken', $apiToken);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        \assert($user instanceof User);
        $type = hash_equals($user->getApiToken(), $passport->getAttribute('apiToken')) ? ApiType::Unsafe : ApiType::Safe;

        return new ApiToken($user, $type);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // carry on unauthenticated, endpoints that require a user are answered by start()
        return null;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], Response::HTTP_NOT_ACCEPTABLE);
    }

    /**
     * @return array{string, string}|null
     */
    private static function getCredentials(Request $request): ?array
    {
        $authHeader = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ') && str_contains($authHeader, ':')) {
            [$username, $apiToken] = explode(':', substr($authHeader, 7), 2);
        } else {
            $username = $request->request->has('username') ? $request->request->getString('username') : $request->query->getString('username');
            $apiToken = $request->request->has('apiToken') ? $request->request->getString('apiToken') : $request->query->getString('apiToken');
        }

        return '' !== $username && '' !== $apiToken ? [$username, $apiToken] : null;
    }
}
