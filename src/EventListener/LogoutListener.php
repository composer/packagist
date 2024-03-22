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

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Exception\LogoutException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LogoutListener
{
    #[AsEventListener]
    public function handleExpiredCsrfError(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        do {
            if ($e instanceof LogoutException) {
                if ($e->getMessage() !== 'Invalid CSRF token.') {
                    return;
                }

                try {
                    $session = $event->getRequest()->getSession();
                    if ($session instanceof FlashBagAwareSessionInterface) {
                        $session->getFlashBag()->add('warning', 'Invalid CSRF token, try logging out again.');
                    }

                    $event->setResponse(new RedirectResponse('/'));
                    $event->allowCustomResponseCode();
                } catch (\Exception $e) {
                    $event->setThrowable($e);
                }

                return;
            }
        } while (null !== $e = $e->getPrevious());
    }
}
