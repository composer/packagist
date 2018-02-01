<?php declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Monolog\Handler\FingersCrossedHandler;

class LogResetter
{
    private $handlers;

    public function __construct(ContainerInterface $container, array $fingersCrossedHandlerNames)
    {
        $this->handlers = [];

        foreach ($fingersCrossedHandlerNames as $name) {
            $handler = $container->get('monolog.handler.'.$name);
            if (!$handler instanceof FingersCrossedHandler) {
                throw new \RuntimeException('Misconfiguration: '.$name.' given as a fingers_crossed handler type but '.get_class($handler).' was found');
            }

            $this->handlers[] = $handler;
        }
    }

    public function reset()
    {
        foreach ($this->handlers as $handler) {
            $handler->clear();
        }
    }
}
