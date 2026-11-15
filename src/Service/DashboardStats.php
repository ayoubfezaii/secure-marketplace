<?php

/**
 * FILE: src/Service/DashboardStats.php
 * WHAT: service that computes the numbers shown on the buyer and seller dashboards (and a few admin totals).
 * ROLE IN THE PROJECT: keeps the statistics queries out of the controllers.
 * USED BY: Buyer\DashboardController (buyer), Seller\DashboardController (seller).
 */

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enum\PaymentStatus;
use App\Repository\ProductRepository;
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

    /**
     * @param EntityManagerInterface $em        Doctrine, to run the statistics queries
     * @param UserRepository         $users     user counts
     * @param AuditLogRepository     $auditLogs audit log counts
     * @param ProductRepository      $products  to preload product images
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLogs,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * Numbers of the buyer dashboard: orders, money spent, units in cart, reviews, wishlist size.
     *
     * @param User $buyer the logged-in buyer
     *
     * @return array{orders: int, spent: string, cartUnits: int, reviews: int}
     */
    public function forBuyer(User $buyer): array
    {
        // One query: number of non-cancelled orders and total really paid.
        $orders = $this->em->createQueryBuilder()
            // "Spent" only counts money really paid: an uncollected cash order is an order, not a spend.
            ->select('COUNT(o.id) AS cnt, COALESCE(SUM(CASE WHEN o.paymentStatus IN (:settled) THEN o.total ELSE 0 END), 0) AS spent')
            ->from(Order::class, 'o')
            ->where('o.buyer = :u')->andWhere('o.status != :cancelled')
            ->setParameter('u', $buyer)->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->setParameter('settled', PaymentStatus::settledValues())
            ->getQuery()->getSingleResult();

        return [
            'orders' => (int) $orders['cnt'],
            'spent' => Money::decimal($orders['spent']),
            'cartUnits' => $this->scalar('SELECT COALESCE(SUM(c.quantity), 0) FROM '.CartItem::class.' c WHERE c.user = :u', ['u' => $buyer]),
            'reviews' => $this->scalar('SELECT COUNT(r.id) FROM '.Review::class.' r WHERE r.author = :u', ['u' => $buyer]),
            'wishlist' => $this->scalar('SELECT COUNT(w.id) FROM '.WishlistItem::class.' w WHERE w.user = :u', ['u' => $buyer]),
        ];
    }

    /**
     * "Recommended for you": active products from the categories the buyer already bought in, that they haven't
     * bought yet; completed with best-rated products.
     *
     * @return list<Product>
     */
    public function recommendedFor(User $buyer, int $limit = 4): array
    {
        // What did the buyer already buy? (product ids and category ids)
        $bought = $this->em->createQueryBuilder()
            ->select('IDENTITY(i.product) AS pid, IDENTITY(p.category) AS cid')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')->innerJoin('i.product', 'p')
            ->where('o.buyer = :u')->setParameter('u', $buyer)
            ->getQuery()->getArrayResult();
        $productIds = array_values(array_unique(array_map(static fn (array $r) => (int) $r['pid'], $bought)));
        $categoryIds = array_values(array_unique(array_map(static fn (array $r) => (int) $r['cid'], $bought)));

        // Suggest active, in-stock products of the same categories that were not bought yet, best rated first.
        $qb = $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p')
            ->where('p.isActive = true')->andWhere('p.stock > 0')
            ->orderBy('p.ratingAverage', 'DESC')->addOrderBy('p.createdAt', 'DESC')->setMaxResults($limit);
        if ([] !== $productIds) {
            $qb->andWhere('p.id NOT IN (:bought)')->setParameter('bought', $productIds);
        }
        if ([] !== $categoryIds) {
            $qb->andWhere('p.category IN (:cats)')->setParameter('cats', $categoryIds);
        }
        /** @var list<Product> $result */
        $result = $qb->getQuery()->getResult();

        // Not enough suggestions: complete with the best-rated products of the whole shop.
        if (count($result) < $limit) {
            $more = $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p')
                ->where('p.isActive = true')->andWhere('p.stock > 0')
                ->orderBy('p.ratingAverage', 'DESC')->setMaxResults($limit * 3)
                ->getQuery()->getResult();
            foreach ($more as $p) {
                if (count($result) >= $limit) {
                    break;
                }
                if (!in_array($p, $result, true) && !in_array($p->getId(), $productIds, true)) {
                    $result[] = $p;
                }
            }
        }

        return $this->products->preloadImages($result);
    }

    /**
     * Numbers of the seller dashboard: active products, units sold, revenue, items to ship, average rating.
     *
     * @param User $seller the logged-in seller
     *
     * @return array{activeProducts: int, unitsSold: int, revenue: string, pendingShipments: int, avgRating: float|null, reviewCount: int}
     */
    public function forSeller(User $seller): array
    {
        // Units sold and revenue of this seller (only paid or collected orders).
        $sales = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(i.quantity), 0) AS units, COALESCE(SUM(i.unitPrice * i.quantity), 0) AS revenue')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :u')->andWhere('i.status IN (:sold)')
            ->setParameter('u', $seller)->setParameter('sold', self::SOLD)
            // Cash on delivery counts only once the cash is collected.
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->getQuery()->getSingleResult();

        // Average rating and number of reviews over all the seller's products.
        $rating = $this->em->createQueryBuilder()
            ->select('AVG(r.rating) AS avg, COUNT(r.id) AS cnt')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')
            ->where('p.seller = :u')->setParameter('u', $seller)
            ->getQuery()->getSingleResult();

        return [
            'activeProducts' => $this->scalar('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.seller = :u AND p.isActive = true', ['u' => $seller]),
            'unitsSold' => (int) $sales['units'],
            'revenue' => Money::decimal($sales['revenue']),
            'pendingShipments' => $this->scalar('SELECT COUNT(i.id) FROM '.OrderItem::class.' i WHERE i.seller = :u AND i.status IN (:toShip)', ['u' => $seller, 'toShip' => Order::AWAITING_SHIPMENT]),
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
        // First day of the period (today included).
        $since = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));

        $rows = $this->em->createQueryBuilder()
            ->select('o.createdAt AS createdAt, i.unitPrice AS unitPrice, i.quantity AS quantity')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :u')->andWhere('i.status IN (:sold)')->andWhere('o.createdAt >= :since')
            ->setParameter('u', $seller)->setParameter('sold', self::SOLD)->setParameter('since', $since)
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->getQuery()->getArrayResult();

        // One entry per day with 0, then add each sale to its day.
        $revenue = [];
        $units = [];
        for ($d = $since; $d <= new \DateTimeImmutable('today'); $d = $d->modify('+1 day')) {
            $revenue[$d->format('Y-m-d')] = 0.0;
            $units[$d->format('Y-m-d')] = 0;
        }
        foreach ($rows as $row) {
            $key = $row['createdAt']->format('Y-m-d');
            if (isset($revenue[$key])) {
                $revenue[$key] += (float) bcmul((string) $row['unitPrice'], (string) $row['quantity'], Money::SCALE);
                $units[$key] += (int) $row['quantity'];
            }
        }

        return [
            'labels' => array_map(static fn (string $k) => (new \DateTimeImmutable($k))->format('d/m'), array_keys($revenue)),
            'revenue' => array_map(static fn (float $v) => round($v, Money::SCALE), array_values($revenue)),
            'units' => array_values($units),
        ];
    }

    /**
     * The newest reviews left on the products of $seller (seller dashboard).
     *
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
     * Global totals for an admin overview.
     *
     * @return array<string, int|string>
     */
    public function forAdmin(): array
    {
        $revenue = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(o.total), 0)')->from(Order::class, 'o')
            ->where('o.status != :c')->setParameter('c', Order::STATUS_CANCELLED)
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->getQuery()->getSingleScalarResult();

        return [
            'buyers' => $this->users->countByRole(User::ROLE_BUYER),
            'sellers' => $this->users->countByRole(User::ROLE_SELLER),
            'admins' => $this->users->countByRole(User::ROLE_ADMIN),
            'products' => $this->scalar('SELECT COUNT(p.id) FROM '.Product::class.' p'),
            'activeProducts' => $this->scalar('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.isActive = true'),
            'orders' => $this->scalar('SELECT COUNT(o.id) FROM '.Order::class.' o'),
            'revenue' => Money::decimal($revenue),
            'locked' => count($this->users->findLocked()),
            'failedLogins24h' => $this->auditLogs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours')),
            'securityEvents24h' => $this->scalar('SELECT COUNT(a.id) FROM '.AuditLog::class.' a WHERE a.action IN (:actions) AND a.createdAt >= :since', [
                'actions' => AuditLog::SECURITY_EVENTS, 'since' => new \DateTimeImmutable('-24 hours'),
            ]),
        ];
    }

    /**
     * Runs a DQL query that returns one number.
     *
     * @param string               $dql    the query text (always a constant written in this class)
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
