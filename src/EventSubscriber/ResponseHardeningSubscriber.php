<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SECURITY (rule 13): removes the "X-Powered-By: PHP/x.y.z" header that PHP adds by itself. Advertising the exact
 * PHP version helps an attacker pick known exploits. (In production also set expose_php = Off in php.ini.)
 */
final class ResponseHardeningSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->remove('X-Powered-By');
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
