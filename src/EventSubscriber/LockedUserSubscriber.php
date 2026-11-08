<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * SECURITY (rules 4, 6): when an admin blocks an account, the user's EXISTING sessions end on their next request.
 * UserChecker only runs at login, so without this a locked user would stay logged in until the session expires.
 */
final class LockedUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 7: right after the firewall (8), which loads and refreshes the user from the database.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        // Only an ADMIN block ends live sessions. The automatic brute-force lockout doesn't: otherwise an attacker
        // could kick a victim out of their session just by failing 5 logins with the victim's email.
        if (!$user instanceof User || !$user->isBlocked()) {
            return;
        }

        $this->tokenStorage->setToken(null);
        $session = $event->getRequest()->getSession();
        $session->invalidate();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'Your account is locked. Please contact support or try again later.');
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }
}
