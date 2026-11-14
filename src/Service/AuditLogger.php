<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes security events to the audit_log table AND to the Monolog "security" channel (rule 14).
 *
 * Rows are inserted with DBAL rather than the ORM on purpose: logging must never flush the caller's
 * half-finished entity changes, and it must work inside failure paths (e.g. a login failure).
 */
final class AuditLogger
{
    private const WARNING_ACTIONS = ['login.failure', 'login.blocked', 'account.locked', '2fa.failure', 'rate_limited',
        'upload.rejected', 'ssrf.blocked', 'password.reset_invalid_token', 'access.denied', 'order.cash_blocked'];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    /**
     * @param array<string, mixed> $details never put passwords, tokens, card numbers or IBANs in here
     * @param User|null            $user    the user concerned; defaults to the logged-in user
     */
    public function log(string $action, array $details = [], ?User $user = null): void
    {
        $user ??= $this->currentUser();
        $request = $this->requestStack->getMainRequest();
        // SECURITY: the User-Agent is attacker-controlled: truncated by the entity, stored with a bound parameter
        // (no SQLi) and auto-escaped when displayed (no XSS in the admin log viewer).
        $entry = new AuditLog($action, $details, $user, $request?->getClientIp(), $request?->headers->get('User-Agent'));

        $context = ['action' => $action, 'user' => $user?->getEmail(), 'ip' => $entry->getIp()] + $details;
        if (in_array($action, self::WARNING_ACTIONS, true)) {
            $this->securityLogger->warning($action, $context);
        } else {
            $this->securityLogger->info($action, $context);
        }

        try {
            // SECURITY: parameter binding only (rule 1).
            // The entity defines the row (truncation rules); DBAL inserts it without touching the ORM unit of work.
            $this->connection->insert('audit_log', [
                'user_id' => $entry->getUser()?->getId(),
                'action' => $entry->getAction(),
                'details' => $entry->getDetails(),
                'ip' => $entry->getIp(),
                'user_agent' => $entry->getUserAgent(),
                'created_at' => $entry->getCreatedAt(),
            ], [
                'details' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (\Throwable $e) {
            // A logging failure must never break the user's request; it still reaches Monolog.
            $this->securityLogger->error('Audit log write failed', ['error' => $e->getMessage(), 'action' => $action]);
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
