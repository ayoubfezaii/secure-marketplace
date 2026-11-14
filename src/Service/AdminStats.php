<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\PaymentStatus;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Repository\SellerApplicationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Global figures for the admin dashboard and the security center. Read-only, DQL with bound parameters (rule 1).
 */
final class AdminStats
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $logs,
        private readonly SellerApplicationRepository $applications,
        private readonly OrderRepository $orders,
    ) {
    }

    /**
     * @return array<string, int|float>
     */
    public function overview(): array
    {
        $revenue = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(o.total), 0)')->from(Order::class, 'o')
            ->where('o.status IN (:sold)')->setParameter('sold', Order::SOLD_STATUSES)
            // Revenue = money really received: card orders (paid) and cash orders once the cash is collected.
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->getQuery()->getSingleScalarResult();
        // Cash on delivery still to be collected by the sellers (pending, not cancelled).
        $cash = $this->orders->pendingCashTotals();

        return [
            'buyers' => $this->users->countByRole(User::ROLE_BUYER),
            'sellers' => $this->users->countByRole(User::ROLE_SELLER),
            'admins' => $this->users->countByRole(User::ROLE_ADMIN),
            'newUsersWeek' => (int) $this->em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.createdAt >= :since')
                ->setParameter('since', new \DateTimeImmutable('-7 days'))->getSingleScalarResult(),
            'products' => (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Product::class.' p')->getSingleScalarResult(),
            'activeProducts' => (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.isActive = true')->getSingleScalarResult(),
            'orders' => (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.Order::class.' o')->getSingleScalarResult(),
            'revenue' => round((float) $revenue, 2),
            'cashToCollect' => (float) $cash['total'],
            'cashToCollectCount' => $cash['count'],
            'pendingApplications' => $this->applications->countPending(),
            'locked' => count($this->users->findLocked()),
            'failedLogins24h' => $this->logs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours')),
        ];
    }

    /**
     * Orders and revenue per day (last $days days, all statuses for the count; revenue = sold statuses whose
     * payment was received, so an uncollected cash order counts as an order but not as revenue).
     *
     * @return array{labels: list<string>, orders: list<int>, revenue: list<float>}
     */
    public function ordersByDay(int $days = 30): array
    {
        $since = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));
        $rows = $this->em->createQueryBuilder()
            ->select('o.createdAt AS createdAt, o.total AS total, o.status AS status, o.paymentStatus AS paymentStatus')
            ->from(Order::class, 'o')->where('o.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getArrayResult();

        $orders = [];
        $revenue = [];
        for ($d = $since; $d <= new \DateTimeImmutable('today'); $d = $d->modify('+1 day')) {
            $orders[$d->format('Y-m-d')] = 0;
            $revenue[$d->format('Y-m-d')] = 0.0;
        }
        foreach ($rows as $row) {
            $key = $row['createdAt']->format('Y-m-d');
            if (!isset($orders[$key])) {
                continue;
            }
            ++$orders[$key];
            $paymentStatus = $row['paymentStatus'] instanceof PaymentStatus ? $row['paymentStatus']->value : (string) $row['paymentStatus'];
            if (in_array($row['status'], Order::SOLD_STATUSES, true) && in_array($paymentStatus, PaymentStatus::settledValues(), true)) {
                $revenue[$key] += (float) $row['total'];
            }
        }

        return [
            'labels' => array_map(static fn (string $k) => (new \DateTimeImmutable($k))->format('d M'), array_keys($orders)),
            'orders' => array_values($orders),
            'revenue' => array_map(static fn (float $v) => round($v, 2), array_values($revenue)),
        ];
    }

    /**
     * Failed logins per hour (24h) or per day (7d).
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    public function failedLoginSeries(string $period): array
    {
        $hourly = '24h' === $period;
        $since = $hourly ? new \DateTimeImmutable('-23 hours') : (new \DateTimeImmutable('today'))->modify('-6 days');
        $rows = $this->em->createQueryBuilder()
            ->select('a.createdAt AS createdAt')->from(AuditLog::class, 'a')
            ->where('a.action IN (:actions)')->andWhere('a.createdAt >= :since')
            ->setParameter('actions', [AuditLog::LOGIN_FAILURE, AuditLog::TWO_FA_FAILURE])
            ->setParameter('since', $hourly ? $since->setTime((int) $since->format('H'), 0) : $since)
            ->getQuery()->getArrayResult();

        $buckets = [];
        $fmt = $hourly ? 'Y-m-d H' : 'Y-m-d';
        $step = $hourly ? '+1 hour' : '+1 day';
        $cursor = $hourly ? $since->setTime((int) $since->format('H'), 0) : $since;
        for ($i = 0; $i < ($hourly ? 24 : 7); ++$i, $cursor = $cursor->modify($step)) {
            $buckets[$cursor->format($fmt)] = 0;
        }
        foreach ($rows as $row) {
            $key = $row['createdAt']->format($fmt);
            if (isset($buckets[$key])) {
                ++$buckets[$key];
            }
        }

        return [
            'labels' => array_map(static fn (string $k) => $hourly ? substr($k, -2).'h' : (new \DateTimeImmutable($k))->format('D d'), array_keys($buckets)),
            'data' => array_values($buckets),
        ];
    }

    /**
     * IP addresses with the most failed logins over the last 7 days.
     *
     * @return list<array{ip: string, failures: int, last: \DateTimeImmutable}>
     */
    public function topFailingIps(int $limit = 8): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('a.ip AS ip, COUNT(a.id) AS failures, MAX(a.createdAt) AS last')
            ->from(AuditLog::class, 'a')
            ->where('a.action IN (:actions)')->andWhere('a.createdAt >= :since')->andWhere('a.ip IS NOT NULL')
            ->setParameter('actions', [AuditLog::LOGIN_FAILURE, AuditLog::LOGIN_BLOCKED, AuditLog::TWO_FA_FAILURE])
            ->setParameter('since', new \DateTimeImmutable('-7 days'))
            ->groupBy('a.ip')->orderBy('failures', 'DESC')->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => ['ip' => (string) $r['ip'], 'failures' => (int) $r['failures'], 'last' => new \DateTimeImmutable((string) $r['last'])], $rows);
    }

    /**
     * Buyers and sellers who haven't enabled 2FA.
     *
     * @return list<User>
     */
    public function usersWithout2fa(int $limit = 10): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.totpEnabled = false')
            ->orderBy('u.lastLoginAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countWithout2fa(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.totpEnabled = false')->getSingleScalarResult();
    }
}
