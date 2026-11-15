<?php

/**
 * FILE: src/EventSubscriber/LockedUserSubscriber.php
 * WHAT: event subscriber that logs out a user whose account was blocked by an admin.
 * ROLE IN THE PROJECT: makes the admin "lock" button effective immediately, even for people already logged in.
 * USED BY: Symfony itself, at the beginning of every request (all logged-in roles).
 */

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
    /**
     * @param TokenStorageInterface $tokenStorage holds the login of the current request
     * @param UrlGeneratorInterface $urls         turns a route name into a URL
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Tells Symfony to call onRequest() at the start of every request. */
    public static function getSubscribedEvents(): array
    {
        // Priority 7: right after the firewall (8), which loads and refreshes the user from the database.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    /**
     * Called at the start of every request, just after Symfony loaded the logged-in user.
     *
     * @param RequestEvent $event the current request
     */
    public function onRequest(RequestEvent $event): void
    {
        // Ignore internal sub-requests (fragments rendered inside a page).
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        // Only an ADMIN block ends live sessions. The automatic brute-force lockout doesn't: otherwise an attacker
        // could kick a victim out of their session just by failing 5 logins with the victim's email.
        if (!$user instanceof User || !$user->isBlocked()) {
            return;
        }

        // Blocked account: remove the login, destroy the session and add a message for the login page.
        $this->tokenStorage->setToken(null);
        $session = $event->getRequest()->getSession();
        $session->invalidate();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'Your account is locked. Please contact support or try again later.');
        }

        // Answer directly with a redirect to the login page (the controller is not executed).
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }
}
