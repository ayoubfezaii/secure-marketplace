<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only aggregates for the three dashboards.
 *
 * SECURITY: every query is DQL with bound parameters (rule 1) and every buyer/seller query is scoped to the user
 * object passed in by the controller (the logged-in user), never to an id from the request (rule 4).
 */
final class DashboardStats
{
    /** Statuses that count as real sales. */
    private const SOLD = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    /**
     * @return array{orders: int, spent: string, cartUnits: int, reviews: int}
     */
    public function forBuyer(User $buyer): array
    {
        $orders = $this->em->createQueryBuilder()
            ->select('COUNT(o.id) AS cnt, COALESCE(SUM(o.total), 0) AS spent')
            ->from(Order::class, 'o')
            ->where('o.buyer = :u')->andWhere('o.status != :cancelled')
            ->setParameter('u', $buyer)->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->getQuery()->getSingleResult();

        return [
            'orders' => (int) $orders['cnt'],
            'spent' => number_format((float) $orders['spent'], 2, '.', ''),
            'cartUnits' => $this->scalar('SELECT COALESCE(SUM(c.quantity), 0) FROM '.CartItem::class.' c WHERE c.user = :u', ['u' => $buyer]),
            'reviews' => $this->scalar('SELECT COUNT(r.id) FROM '.Review::class.' r WHERE r.author = :u', ['u' => $buyer]),
        ];
    }

    /**
     * @return array{activeProducts: int, unitsSold: int, revenue: string, pendingShipments: int, avgRating: float|null, reviewCount: int}
     */
    public function forSeller(User $seller): array
    {
        $sales = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(i.quantity), 0) AS units, COALESCE(SUM(i.unitPrice * i.quantity), 0) AS revenue')
            ->from(OrderItem::class, 'i')
            ->where('i.seller = :u')->andWhere('i.status IN (:sold)')
            ->setParameter('u', $seller)->setParameter('sold', self::SOLD)
            ->getQuery()->getSingleResult();

        $rating = $this->em->createQueryBuilder()
            ->select('AVG(r.rating) AS avg, COUNT(r.id) AS cnt')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')
            ->where('p.seller = :u')->setParameter('u', $seller)
            ->getQuery()->getSingleResult();

        return [
            'activeProducts' => $this->scalar('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.seller = :u AND p.isActive = true', ['u' => $seller]),
            'unitsSold' => (int) $sales['units'],
            'revenue' => number_format((float) $sales['revenue'], 2, '.', ''),
            'pendingShipments' => $this->scalar('SELECT COUNT(i.id) FROM '.OrderItem::class.' i WHERE i.seller = :u AND i.status = :paid', ['u' => $seller, 'paid' => Order::STATUS_PAID]),
            'avgRating' => null !== $rating['avg'] ? round((float) $rating['avg'], 1) : null,
            'reviewCount' => (int) $rating['cnt'],
        ];
    }

    /**
     * Revenue per day for the last $days days (including days without sales, so the chart has no gaps).
     *
     * @return array{labels: list<string>, revenue: list<float>, units: list<int>}
     */
    public function sellerSalesByDay(User $seller, int $days = 30): array
    {
        $since = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));

        $rows = $this->em->createQueryBuilder()
            ->select('o.createdAt AS createdAt, i.unitPrice AS unitPrice, i.quantity AS quantity')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :u')->andWhere('i.status IN (:sold)')->andWhere('o.createdAt >= :since')
            ->setParameter('u', $seller)->setParameter('sold', self::SOLD)->setParameter('since', $since)
            ->getQuery()->getArrayResult();

        $revenue = [];
        $units = [];
        for ($d = $since; $d <= new \DateTimeImmutable('today'); $d = $d->modify('+1 day')) {
            $revenue[$d->format('Y-m-d')] = 0.0;
            $units[$d->format('Y-m-d')] = 0;
        }
        foreach ($rows as $row) {
            $key = $row['createdAt']->format('Y-m-d');
            if (isset($revenue[$key])) {
                $revenue[$key] += (float) bcmul((string) $row['unitPrice'], (string) $row['quantity'], 2);
                $units[$key] += (int) $row['quantity'];
            }
        }

        return [
            'labels' => array_map(static fn (string $k) => (new \DateTimeImmutable($k))->format('d/m'), array_keys($revenue)),
            'revenue' => array_map(static fn (float $v) => round($v, 2), array_values($revenue)),
            'units' => array_values($units),
        ];
    }

    /**
     * @return list<Review>
     */
    public function latestReviewsForSeller(User $seller, int $limit = 5): array
    {
        return $this->em->createQueryBuilder()
            ->select('r', 'p', 'a')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')->innerJoin('r.author', 'a')
            ->where('p.seller = :u')->setParameter('u', $seller)
            ->orderBy('r.createdAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return array<string, int|string>
     */
    public function forAdmin(): array
    {
        $revenue = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(o.total), 0)')->from(Order::class, 'o')
            ->where('o.status != :c')->setParameter('c', Order::STATUS_CANCELLED)
            ->getQuery()->getSingleScalarResult();

        return [
            'buyers' => $this->users->countByRole(User::ROLE_BUYER),
            'sellers' => $this->users->countByRole(User::ROLE_SELLER),
            'admins' => $this->users->countByRole(User::ROLE_ADMIN),
            'products' => $this->scalar('SELECT COUNT(p.id) FROM '.Product::class.' p'),
            'activeProducts' => $this->scalar('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.isActive = true'),
            'orders' => $this->scalar('SELECT COUNT(o.id) FROM '.Order::class.' o'),
            'revenue' => number_format((float) $revenue, 2, '.', ''),
            'locked' => count($this->users->findLocked()),
            'failedLogins24h' => $this->auditLogs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours')),
            'securityEvents24h' => $this->scalar('SELECT COUNT(a.id) FROM '.AuditLog::class.' a WHERE a.action IN (:actions) AND a.createdAt >= :since', [
                'actions' => AuditLog::SECURITY_EVENTS, 'since' => new \DateTimeImmutable('-24 hours'),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scalar(string $dql, array $params = []): int
    {
        $query = $this->em->createQuery($dql);
        foreach ($params as $name => $value) {
            // SECURITY (rule 1): bound parameters; the DQL strings above are constants.
            $query->setParameter($name, $value);
        }

        return (int) $query->getSingleScalarResult();
    }
}
