<?php

namespace App\Controller\Admin;

use App\Service\Money;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Service\AdminStats;
use App\Service\AuditLogger;
use App\Service\CsvSanitizer;
use App\Service\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only views of the audit log (rule 14) + security center. There is no edit/delete action: logs are
 * append-only. The CSV export is protected against CSV/formula injection (CsvSanitizer).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class AuditController extends AbstractController
{
    private const EXPORT_LIMIT = 10000;

    #[Route('/audit', name: 'admin_audit', methods: ['GET'])]
    public function audit(Request $request, AuditLogRepository $logs): Response
    {
        $filters = $this->filters($request, $logs);

        return $this->render('admin/audit.html.twig', [
            'pager' => Pagination::fromQueryBuilder($logs->createFilteredQueryBuilder($filters['action'], $filters['user'], $filters['from'], $filters['to'], $filters['ip']), $request, 50),
            'actions' => $logs->findDistinctActions(),
            'filters' => $filters,
            'params' => $this->params($filters),
        ]);
    }

    /**
     * CSV export of the (filtered) audit log. GET is fine here: it changes nothing (the export itself is logged).
     */
    #[Route('/audit/export.csv', name: 'admin_audit_export', methods: ['GET'])]
    public function export(Request $request, AuditLogRepository $logs, AuditLogger $audit): StreamedResponse
    {
        $filters = $this->filters($request, $logs);
        $rows = $logs->createFilteredQueryBuilder($filters['action'], $filters['user'], $filters['from'], $filters['to'], $filters['ip'])
            ->setMaxResults(self::EXPORT_LIMIT)->getQuery()->getResult();

        $audit->log(AuditLog::AUDIT_EXPORTED, ['rows' => count($rows), 'filters' => $this->params($filters)]);

        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if (false === $out) {
                return;
            }
            // UTF-8 BOM so spreadsheet apps detect the encoding.
            fwrite($out, "\xEF\xBB\xBF");
            // Amounts are in Tunisian dinars: the currency is stated in the header AND on every row that has an
            // amount, always with 3 decimals and a dot ("1250.500"), so a spreadsheet can't misread them.
            fputcsv($out, ['date', 'action', 'user', 'ip', 'user_agent', 'amount', 'currency (Currency: '.Money::CURRENCY.')', 'details'], ',', '"', '');
            /** @var AuditLog $log */
            foreach ($rows as $log) {
                // SECURITY (CSV injection): every cell goes through CsvSanitizer. A value such as
                // "=HYPERLINK(...)" or "@SUM(...)" (e.g. typed as an email at login or sent as User-Agent) is
                // prefixed with a quote, so Excel/LibreOffice show it as text instead of executing it.
                fputcsv($out, CsvSanitizer::row([
                    $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    $log->getAction(),
                    $log->getUser()?->getEmail() ?? '',
                    $log->getIp() ?? '',
                    $log->getUserAgent() ?? '',
                    ...self::amountCells($log->getDetails()),
                    json_encode($log->getDetails(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '',
                ]), ',', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'audit-log-'.date('Ymd-His').'.csv'));
        // SECURITY: sensitive export, never cached.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Amount of an order event (audit details "total"), as two CSV cells: ["1250.500", "TND"], or two empty cells.
     *
     * @param array<string, mixed> $details
     *
     * @return array{string, string}
     */
    private static function amountCells(array $details): array
    {
        $total = $details['total'] ?? null;

        // SECURITY: only a plain decimal number is exported as an amount; anything else stays in the JSON column,
        // which CsvSanitizer neutralises like every other cell.
        return is_string($total) && 1 === preg_match('/^\d{1,12}(\.\d{1,3})?$/', $total) ? [Money::decimal($total), Money::CURRENCY] : ['', ''];
    }

    #[Route('/security', name: 'admin_security', methods: ['GET'])]
    public function security(Request $request, AuditLogRepository $logs, UserRepository $users, AdminStats $stats): Response
    {
        // SECURITY (rule 15): whitelisted period.
        $period = '7d' === $request->query->getString('period') ? '7d' : '24h';

        return $this->render('admin/security.html.twig', [
            'period' => $period,
            'failedSeries' => $stats->failedLoginSeries($period),
            'topIps' => $stats->topFailingIps(8),
            'lockedUsers' => $users->findLocked(),
            'blockedUploads' => $logs->findRecentByActions([AuditLog::UPLOAD_REJECTED], 8),
            'blockedSsrf' => $logs->findRecentByActions([AuditLog::SSRF_BLOCKED], 8),
            'denied' => $logs->findRecentByActions([AuditLog::ACCESS_DENIED, AuditLog::RATE_LIMITED, AuditLog::PASSWORD_RESET_INVALID], 8),
            'without2fa' => $stats->usersWithout2fa(8),
            'without2faCount' => $stats->countWithout2fa(),
            'failed24h' => $logs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours')),
            'ssrf7d' => $logs->countSince(AuditLog::SSRF_BLOCKED, new \DateTimeImmutable('-7 days')),
            'uploads7d' => $logs->countSince(AuditLog::UPLOAD_REJECTED, new \DateTimeImmutable('-7 days')),
        ]);
    }

    /**
     * @return array{action: ?string, user: string, ip: string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
     */
    private function filters(Request $request, AuditLogRepository $logs): array
    {
        $action = $request->query->getString('action');
        $ip = trim($request->query->getString('ip'));

        // SECURITY (rule 15): action must be an existing value; user text length-capped (bound + escaped in the
        // query); IP must be a valid IPv4/IPv6 prefix of hex digits, dots and colons; dates strictly parsed.
        return [
            'action' => in_array($action, $logs->findDistinctActions(), true) ? $action : null,
            'user' => mb_substr(trim($request->query->getString('user')), 0, 180),
            'ip' => 1 === preg_match('/^[0-9a-fA-F.:]{1,45}$/', $ip) ? $ip : '',
            'from' => self::date($request->query->getString('from')),
            'to' => self::date($request->query->getString('to')),
        ];
    }

    /**
     * @param array{action: ?string, user: string, ip: string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable} $f
     *
     * @return array<string, string>
     */
    private function params(array $f): array
    {
        return array_filter(['action' => $f['action'], 'user' => $f['user'], 'ip' => $f['ip'], 'from' => $f['from']?->format('Y-m-d'), 'to' => $f['to']?->format('Y-m-d')]);
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
