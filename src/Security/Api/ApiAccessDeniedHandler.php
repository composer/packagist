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

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Answers authenticated API requests that a voter denied.
 */
class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $token = $this->security->getToken();
        $message = $token instanceof ApiToken && !$token->allows(ApiType::Unsafe)
            ? 'The safe API token can not be used for this action, use the main API token'
            : 'You are not allowed to edit this package';

        return new JsonResponse(['status' => 'error', 'message' => $message], Response::HTTP_FORBIDDEN);
    }
}
