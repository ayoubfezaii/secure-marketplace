<?php

/**
 * FILE: src/EventSubscriber/ResponseHardeningSubscriber.php
 * WHAT: event subscriber that removes the "X-Powered-By" header from every response.
 * ROLE IN THE PROJECT: small hardening: the site does not tell attackers which PHP version it runs.
 * USED BY: Symfony itself, just before every response is sent (everyone).
 */

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
    /** Tells Symfony to call onResponse() for every response. Priority -1024 = run among the very last listeners. */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    /**
     * Called just before the response is sent to the browser.
     */
    public function onResponse(ResponseEvent $event): void
    {
        // Remove the header from the Symfony response and from PHP's own header list.
        $event->getResponse()->headers->remove('X-Powered-By');
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
