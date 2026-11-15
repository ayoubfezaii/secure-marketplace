<?php

/**
 * FILE: src/EventSubscriber/LoginLockoutSubscriber.php
 * WHAT: event subscriber that counts wrong passwords, locks the account after 5 failures, and logs every
 *   login success, failure and logout.
 * ROLE IN THE PROJECT: the BRUTE FORCE defence stored on the account (works even if the attacker changes IP).
 * USED BY: Symfony Security, which sends an event after each login attempt and each logout (all roles).
 */

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Account lockout (rule 6) and login/logout audit logging (rule 14).
 *
 * The rate limiter (security.yaml, login_throttling) limits attempts per IP + email.
 * This lockout is stored on the account, so it still applies when the attacker
 * changes IP address.
 */
final class LoginLockoutSubscriber implements EventSubscriberInterface
{
    /**
     * @param UserRepository         $users to find the account behind the typed email
     * @param EntityManagerInterface $em    Doctrine, to save the failure counter
     * @param AuditLogger            $audit writes into the audit log
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Tells Symfony which security events this class listens to and which method handles each one. */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
        ];
    }

    /**
     * Called by Symfony after a FAILED login attempt.
     *
     * @param LoginFailureEvent $event contains the typed email and the reason of the failure
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // The 2FA step is logged by TwoFactorSubscriber; a wrong 2FA code must not count as a wrong password.
        if (self::isTwoFactorStep($event->getAuthenticator())) {
            return;
        }

        // The email typed in the form (cut to 180 characters), the reason, and the matching account if it exists.
        $identifier = mb_substr($event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier() ?? '', 0, 180);
        $exception = $event->getException();
        $user = '' !== $identifier ? $this->users->findOneByEmail($identifier) : null;

        // SECURITY: only a wrong password counts towards the lockout. Attempts rejected by the
        // rate limiter or by an existing lock don't extend the lock.
        if (!$exception instanceof BadCredentialsException) {
            $this->audit->log(AuditLog::LOGIN_BLOCKED, ['email' => $identifier, 'reason' => (new \ReflectionClass($exception))->getShortName()], $user);

            return;
        }

        // SECURITY: failures for unknown emails are logged too, without revealing anything to the client.
        $this->audit->log(AuditLog::LOGIN_FAILURE, ['email' => $identifier], $user);

        // Unknown email: nothing to count.
        if (!$user instanceof User) {
            return;
        }

        // SECURITY: brute force - count this failure on the account; at 5 the account is locked for 15 minutes.
        $user->registerFailedLogin();
        $this->em->flush();

        // The account just became locked: log it.
        if ($user->isLocked()) {
            $this->audit->log(AuditLog::ACCOUNT_LOCKED, [
                'email' => $user->getEmail(),
                'locked_until' => $user->getLockedUntil()?->format(\DATE_ATOM),
            ], $user);
        }
    }

    /**
     * Called by Symfony after a SUCCESSFUL password check.
     *
     * @param LoginSuccessEvent $event contains the user who logged in
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        // Skip other user classes, and skip the 2FA step (it has its own subscriber).
        if (!$user instanceof User || self::isTwoFactorStep($event->getAuthenticator())) {
            return;
        }

        // SECURITY: a successful password check resets the failure counter, so lockout only follows consecutive failures.
        if (0 !== $user->getFailedAttempts() || null !== $user->getLockedUntil()) {
            $user->resetFailedLogins();
        }
        // "Last login" column of the admin user list (the full history stays in the audit log).
        $user->recordLogin();
        $this->em->flush();

        // SECURITY: logging - successful login (with a note when a 2FA code is still required).
        $this->audit->log(AuditLog::LOGIN_SUCCESS, ['email' => $user->getEmail(), '2fa_pending' => $user->isTotpAuthenticationEnabled()], $user);
    }

    /**
     * Called by Symfony when a user logs out.
     */
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->audit->log(AuditLog::LOGOUT, [], $user);
        }
    }

    /** True when the event comes from the 2FA code step and not from the password form. */
    private static function isTwoFactorStep(object $authenticator): bool
    {
        // In debug mode the authenticator is wrapped for the profiler.
        if ($authenticator instanceof \Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }

        return $authenticator instanceof \Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
    }
}
