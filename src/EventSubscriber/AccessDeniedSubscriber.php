<?php

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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 2: before the security ExceptionListener (priority 1) converts the exception.
        return [KernelEvents::EXCEPTION => ['onException', 2]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if (!$e instanceof AccessDeniedException && !$e instanceof AccessDeniedHttpException) {
            return;
        }

        $user = $this->security->getUser();
        // Anonymous visitors are simply sent to the login page; that is not an attack signal.
        if (!$user instanceof User) {
            return;
        }

        $this->audit->log(AuditLog::ACCESS_DENIED, [
            'path' => mb_substr($event->getRequest()->getPathInfo(), 0, 255),
            'method' => $event->getRequest()->getMethod(),
        ], $user);
    }
}
