<?php

/**
 * FILE: src/EventSubscriber/AccessDeniedSubscriber.php
 * WHAT: event subscriber that writes an audit log line each time a logged-in user gets "403 access denied".
 * ROLE IN THE PROJECT: an event subscriber is code that Symfony calls automatically when something happens
 *   (here: an exception). It makes IDOR attempts visible to the admin.
 * USED BY: Symfony itself, on every request that ends with an access-denied error (buyer, seller, admin).
 */

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Logs 403s hit by LOGGED-IN users (rule 14): a buyer trying /admin, or someone changing a UUID in a URL
 * to open another user's order (IDOR probing). Only logs; the response is still built by the firewall.
 */
final class AccessDeniedSubscriber implements EventSubscriberInterface
{
    /**
     * @param AuditLogger $audit    writes into the audit log
     * @param Security    $security gives the logged-in user
     */
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Security $security,
    ) {
    }

    /** Tells Symfony which event this class listens to, which method to call, and with which priority. */
    public static function getSubscribedEvents(): array
    {
        // Priority 2: before the security ExceptionListener (priority 1) converts the exception.
        return [KernelEvents::EXCEPTION => ['onException', 2]];
    }

    /**
     * Called by Symfony when any exception is thrown during a request.
     *
     * @param ExceptionEvent $event contains the exception and the request
     */
    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        // Only "access denied" exceptions are interesting here.
        if (!$e instanceof AccessDeniedException && !$e instanceof AccessDeniedHttpException) {
            return;
        }

        $user = $this->security->getUser();
        // Anonymous visitors are simply sent to the login page; that is not an attack signal.
        if (!$user instanceof User) {
            return;
        }

        // SECURITY: logging - who was refused, on which URL and with which HTTP method.
        $this->audit->log(AuditLog::ACCESS_DENIED, [
            'path' => mb_substr($event->getRequest()->getPathInfo(), 0, 255),
            'method' => $event->getRequest()->getMethod(),
        ], $user);
    }
}
