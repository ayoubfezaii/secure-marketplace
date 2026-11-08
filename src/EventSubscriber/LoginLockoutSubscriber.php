<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Account lockout (rule 6) and login logging (rule 14).
 *
 * The rate limiter (security.yaml, login_throttling) limits attempts per IP + email.
 * This lockout is stored on the account, so it still applies when the attacker
 * changes IP address.
 */
final class LoginLockoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $identifier = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier() ?? '';
        $ip = $event->getRequest()->getClientIp();
        $exception = $event->getException();

        // SECURITY: only a wrong password counts towards the lockout. Attempts rejected by the
        // rate limiter or by an existing lock don't extend the lock.
        if (!$exception instanceof BadCredentialsException) {
            $this->securityLogger->warning('Login blocked', [
                'email' => $identifier,
                'ip' => $ip,
                'reason' => $exception::class,
            ]);

            return;
        }

        $user = '' !== $identifier ? $this->users->findOneByEmail($identifier) : null;

        // SECURITY: log failures for unknown emails too, without revealing anything to the client.
        $this->securityLogger->warning('Login failure', ['email' => $identifier, 'ip' => $ip]);

        if (!$user instanceof User) {
            return;
        }

        $user->registerFailedLogin();
        $this->em->flush();

        if ($user->isLocked()) {
            $this->securityLogger->warning('Account locked after repeated login failures', [
                'email' => $user->getEmail(),
                'ip' => $ip,
                'locked_until' => $user->getLockedUntil()?->format(\DATE_ATOM),
            ]);
        }
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        // SECURITY: a successful login resets the failure counter, so lockout only follows consecutive failures.
        if (0 !== $user->getFailedAttempts() || null !== $user->getLockedUntil()) {
            $user->resetFailedLogins();
            $this->em->flush();
        }

        $this->securityLogger->info('Login success', [
            'email' => $user->getEmail(),
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }
}
