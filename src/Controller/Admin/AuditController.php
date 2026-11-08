<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only views of the audit log (rule 14). There is no edit/delete action: logs are append-only.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class AuditController extends AbstractController
{
    #[Route('/audit', name: 'admin_audit', methods: ['GET'])]
    public function audit(Request $request, AuditLogRepository $logs): Response
    {
        $actions = $logs->findDistinctActions();
        $action = $request->query->getString('action');
        // SECURITY (rule 15): action filter must be an existing value; user text bound + escaped; dates strictly parsed.
        $action = in_array($action, $actions, true) ? $action : null;
        $user = mb_substr(trim($request->query->getString('user')), 0, 180);
        $from = self::date($request->query->getString('from'));
        $to = self::date($request->query->getString('to'));

        return $this->render('admin/audit.html.twig', [
            'pager' => Pagination::fromQueryBuilder($logs->createFilteredQueryBuilder($action, $user, $from, $to), $request, 50),
            'params' => array_filter(['action' => $action, 'user' => $user, 'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')]),
            'actions' => $actions,
            'filters' => ['action' => $action, 'user' => $user, 'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')],
        ]);
    }

    #[Route('/security', name: 'admin_security', methods: ['GET'])]
    public function security(AuditLogRepository $logs, UserRepository $users): Response
    {
        return $this->render('admin/security.html.twig', [
            'failedLogins' => $logs->findRecentByActions([AuditLog::LOGIN_FAILURE, AuditLog::LOGIN_BLOCKED, AuditLog::TWO_FA_FAILURE], 20),
            'lockouts' => $logs->findRecentByActions([AuditLog::ACCOUNT_LOCKED, AuditLog::USER_LOCKED, AuditLog::RATE_LIMITED], 20),
            'blocked' => $logs->findRecentByActions([AuditLog::SSRF_BLOCKED, AuditLog::UPLOAD_REJECTED], 20),
            'denied' => $logs->findRecentByActions([AuditLog::ACCESS_DENIED, AuditLog::PASSWORD_RESET_INVALID], 20),
            'lockedUsers' => $users->findLocked(),
        ]);
    }

    private static function date(string $raw): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date && $date->format('Y-m-d') === $raw ? $date : null;
    }
}
