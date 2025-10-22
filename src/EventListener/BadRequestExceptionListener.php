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
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Twig\Error\RuntimeError;

/**
 * Converts BadRequestException to BadRequestHttpException
 */
class BadRequestExceptionListener
{
    #[AsEventListener(priority: 1000)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof RuntimeError) {
            $exception = $exception->getPrevious();
        }
        if ($exception instanceof BadRequestException) {
            $event->setThrowable(new BadRequestHttpException($exception->getMessage(), $exception));
        }
    }
}
