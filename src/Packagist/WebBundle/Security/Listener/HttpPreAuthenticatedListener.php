<?php
/**
 * @author strati <strati@strati.hu>
 */

namespace Packagist\WebBundle\Security\Listener;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Firewall\AbstractPreAuthenticatedListener;

/**
 * Listener for basic http authentication method
 */
class HttpPreAuthenticatedListener extends AbstractPreAuthenticatedListener
{
    /**
     * Gets the user and credentials from the Request.
     *
     * @param Request $request
     *
     * @return array
     * @throws BadCredentialsException
     */
    protected function getPreAuthenticatedData(Request $request)
    {
        if (false === $request->server->has('PHP_AUTH_USER')) {
            throw new BadCredentialsException('No authenticated user was not found');
        }

        return array($request->server->get('PHP_AUTH_USER'), $request->server->get('PHP_AUTH_PW', ''));
    }
}
