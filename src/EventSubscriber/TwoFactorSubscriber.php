<?php

/**
 * FILE: src/EventSubscriber/TwoFactorSubscriber.php
 * WHAT: event subscriber for the 2FA code step of the login: limits the number of tries and logs the results.
 * ROLE IN THE PROJECT: a 6-digit code has only 1 000 000 values, so without a limit it could be guessed.
 *   This is the BRUTE FORCE defence of the second factor.
 * USED BY: scheb/2fa-bundle, which sends an event for each code attempt (users with 2FA enabled).
 */

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogger;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Brute-force protection (rule 6) and logging (rule 14) for the 2FA code step of the login.
 * scheb/2fa-bundle doesn't limit the number of code attempts on its own.
 */
final class TwoFactorSubscriber implements EventSubscriberInterface
{
    /**
     * @param RateLimiterFactoryInterface $twoFactorCodeLimiter limiter "two_factor_code" from config/packages/rate_limiter.yaml
     * @param AuditLogger                 $audit                writes into the audit log
     */
    public function __construct(
        private readonly RateLimiterFactoryInterface $twoFactorCodeLimiter,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Tells Symfony which 2FA events this class listens to and which method handles each one. */
    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAttempt',
            TwoFactorAuthenticationEvents::FAILURE => 'onFailure',
            TwoFactorAuthenticationEvents::SUCCESS => 'onSuccess',
        ];
    }

    /**
     * Called BEFORE a typed code is checked.
     *
     * @param TwoFactorAuthenticationEvent $event contains the half-logged-in user
     */
    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        // SECURITY: limiter keyed on the ACCOUNT, not the IP or session, so logging in again with the
        // password doesn't give the attacker new tries. Runs before the code is checked.
        $limit = $this->twoFactorCodeLimiter->create('2fa_'.$event->getToken()->getUserIdentifier())->consume();

        if (!$limit->isAccepted()) {
            $this->audit->log(AuditLog::RATE_LIMITED, ['limiter' => '2fa_code'], self::user($event));

            // Turned into an authentication failure by the authenticator: the code isn't even checked.
            throw new CustomUserMessageAuthenticationException('Too many invalid codes. Please wait a few minutes and try again.');
        }
    }

    /** Called after a WRONG code: written in the audit log. */
    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $this->audit->log(AuditLog::TWO_FA_FAILURE, [], self::user($event));
    }

    /** Called after a CORRECT code: written in the audit log. */
    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $this->audit->log(AuditLog::TWO_FA_SUCCESS, [], self::user($event));
    }

    /** The user of the event as a User object, or null. */
    private static function user(TwoFactorAuthenticationEvent $event): ?User
    {
        $user = $event->getToken()->getUser();

        return $user instanceof User ? $user : null;
    }
}
