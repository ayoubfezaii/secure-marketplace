<?php

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
    public function __construct(
        private readonly RateLimiterFactoryInterface $twoFactorCodeLimiter,
        private readonly AuditLogger $audit,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::ATTEMPT => 'onAttempt',
            TwoFactorAuthenticationEvents::FAILURE => 'onFailure',
            TwoFactorAuthenticationEvents::SUCCESS => 'onSuccess',
        ];
    }

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

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $this->audit->log(AuditLog::TWO_FA_FAILURE, [], self::user($event));
    }

    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $this->audit->log(AuditLog::TWO_FA_SUCCESS, [], self::user($event));
    }

    private static function user(TwoFactorAuthenticationEvent $event): ?User
    {
        $user = $event->getToken()->getUser();

        return $user instanceof User ? $user : null;
    }
}
