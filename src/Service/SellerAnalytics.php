<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only sales figures of ONE seller.
 *
 * SECURITY (rule 4 + privilege matrix "seller can never see other sellers' revenue"): every query has
 * "i.seller = :seller" (or "p.seller = :seller") bound to the User object given by the controller, which is always
 * the logged-in seller. DQL with bound parameters only (rule 1).
 *
 * Revenue rule: an item counts only when its order's payment was really received (paymentStatus paid or collected).
 * A cash-on-delivery order therefore enters the figures when the seller confirms "Cash collected", never before.
 */
final class SellerAnalytics
{
    /** Allowed analytics ranges (whitelist, rule 15): key => days. */
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    /** Simulated marketplace commission on payouts. */
    public const COMMISSION = '0.10';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{revenue: float, units: int, orders: int}
     */
    public function totals(User $seller, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(i.unitPrice * i.quantity), 0) AS revenue, COALESCE(SUM(i.quantity), 0) AS units, COUNT(DISTINCT o.id) AS orders')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :seller')->andWhere('i.status IN (:sold)')
            ->andWhere('o.createdAt >= :from')->andWhere('o.createdAt < :to')
            ->setParameter('seller', $seller)->setParameter('sold', Order::SOLD_STATUSES)
            ->setParameter('from', $from)->setParameter('to', $to)
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->getQuery()->getSingleResult();

        return ['revenue' => round((float) $row['revenue'], Money::SCALE), 'units' => (int) $row['units'], 'orders' => (int) $row['orders']];
    }

    /**
     * Overview cards.
     *
     * @return array{revenueMonth: float, revenueDelta: float|null, unitsMonth: int, pendingShipments: int, activeProducts: int, avgRating: float|null, reviewCount: int}
     */
    public function overview(User $seller): array
    {
        $monthStart = new \DateTimeImmutable('first day of this month 00:00');
        $prevStart = $monthStart->modify('-1 month');
        // Same number of days into last month, for a fair comparison.
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $monthStart->getTimestamp();
        $prevEnd = $prevStart->modify(sprintf('+%d seconds', $elapsed));

        $current = $this->totals($seller, $monthStart, new \DateTimeImmutable('+1 second'));
        $previous = $this->totals($seller, $prevStart, min($prevEnd, $monthStart));

        $rating = $this->em->createQueryBuilder()
            ->select('AVG(r.rating) AS avg, COUNT(r.id) AS cnt')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')
            ->where('p.seller = :seller')->setParameter('seller', $seller)
            ->getQuery()->getSingleResult();

        return [
            'revenueMonth' => $current['revenue'],
            'revenueDelta' => $previous['revenue'] > 0 ? round(($current['revenue'] - $previous['revenue']) / $previous['revenue'] * 100, 1) : null,
            'unitsMonth' => $current['units'],
            'pendingShipments' => (int) $this->em->createQuery('SELECT COUNT(i.id) FROM '.OrderItem::class.' i WHERE i.seller = :s AND i.status IN (:p)')
                ->setParameter('s', $seller)->setParameter('p', Order::AWAITING_SHIPMENT)->getSingleScalarResult(),
            'activeProducts' => (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.seller = :s AND p.isActive = true')
                ->setParameter('s', $seller)->getSingleScalarResult(),
            'avgRating' => null !== $rating['avg'] ? round((float) $rating['avg'], 1) : null,
            'reviewCount' => (int) $rating['cnt'],
        ];
    }

    /**
     * Revenue and units per day for the last $days days (days without sales included, so the chart has no gaps).
     *
     * @return array{labels: list<string>, revenue: list<float>, units: list<int>}
     */
    public function salesByDay(User $seller, int $days): array
    {
        $since = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));
        $rows = $this->em->createQueryBuilder()
            ->select('o.createdAt AS createdAt, i.unitPrice AS unitPrice, i.quantity AS quantity')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :seller')->andWhere('i.status IN (:sold)')->andWhere('o.createdAt >= :since')
            ->setParameter('seller', $seller)->setParameter('sold', Order::SOLD_STATUSES)->setParameter('since', $since)
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
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
                $revenue[$key] += (float) bcmul((string) $row['unitPrice'], (string) $row['quantity'], Money::SCALE);
                $units[$key] += (int) $row['quantity'];
            }
        }

        return [
            'labels' => array_map(static fn (string $k) => (new \DateTimeImmutable($k))->format('d M'), array_keys($revenue)),
            'revenue' => array_map(static fn (float $v) => round($v, Money::SCALE), array_values($revenue)),
            'units' => array_values($units),
        ];
    }

    /**
     * @return array{labels: list<string>, data: list<float>}
     */
    public function revenueByCategory(User $seller, int $days): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('c.name AS name, SUM(i.unitPrice * i.quantity) AS revenue')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')->innerJoin('i.product', 'p')->innerJoin('p.category', 'c')
            ->where('i.seller = :seller')->andWhere('i.status IN (:sold)')->andWhere('o.createdAt >= :since')
            ->setParameter('seller', $seller)->setParameter('sold', Order::SOLD_STATUSES)
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->groupBy('c.id')->orderBy('revenue', 'DESC')
            ->getQuery()->getArrayResult();

        return ['labels' => array_column($rows, 'name'), 'data' => array_map(static fn ($r) => round((float) $r['revenue'], Money::SCALE), $rows)];
    }

    /**
     * @return list<array{product: Product, units: int, revenue: float}>
     */
    public function topProducts(User $seller, int $days, int $limit = 5): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('p', 'SUM(i.quantity) AS units', 'SUM(i.unitPrice * i.quantity) AS revenue')
            // Product is the root entity (Doctrine can only hydrate a root alias), joined to its order lines.
            ->from(Product::class, 'p')->innerJoin(OrderItem::class, 'i', 'WITH', 'i.product = p')->innerJoin('i.order', 'o')
            ->where('i.seller = :seller')->andWhere('i.status IN (:sold)')->andWhere('o.createdAt >= :since')
            ->setParameter('seller', $seller)->setParameter('sold', Order::SOLD_STATUSES)
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->groupBy('p.id')->orderBy('units', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();

        return array_map(static fn (array $r) => ['product' => $r[0], 'units' => (int) $r['units'], 'revenue' => round((float) $r['revenue'], Money::SCALE)], $rows);
    }

    /**
     * Latest order items of this seller still waiting to be shipped.
     *
     * @return list<OrderItem>
     */
    public function itemsToShip(User $seller, int $limit = 6): array
    {
        return $this->em->createQueryBuilder()
            ->select('i', 'o')->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :seller')->andWhere('i.status IN (:toShip)')
            ->setParameter('seller', $seller)->setParameter('toShip', Order::AWAITING_SHIPMENT)
            ->orderBy('o.createdAt', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Simulated payouts: delivered items grouped by month, minus the marketplace commission. A month is "paid" once
     * it is over, the current month is "scheduled".
     *
     * @return list<array{month: \DateTimeImmutable, gross: string, fee: string, net: string, items: int, status: string}>
     */
    public function payouts(User $seller): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('o.createdAt AS createdAt, i.unitPrice AS unitPrice, i.quantity AS quantity')
            ->from(OrderItem::class, 'i')->innerJoin('i.order', 'o')
            ->where('i.seller = :seller')->andWhere('i.status = :delivered')
            ->setParameter('seller', $seller)->setParameter('delivered', Order::STATUS_DELIVERED)
            // No payout on money that was never received (cash on delivery not collected yet).
            ->andWhere('o.paymentStatus IN (:settled)')->setParameter('settled', PaymentStatus::settledValues())
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()->getArrayResult();

        $months = [];
        foreach ($rows as $row) {
            $key = $row['createdAt']->format('Y-m');
            $months[$key] ??= ['gross' => Money::ZERO, 'items' => 0];
            $months[$key]['gross'] = bcadd($months[$key]['gross'], bcmul((string) $row['unitPrice'], (string) $row['quantity'], Money::SCALE), Money::SCALE);
            $months[$key]['items'] += (int) $row['quantity'];
        }

        $current = (new \DateTimeImmutable())->format('Y-m');
        $result = [];
        foreach ($months as $key => $m) {
            $fee = bcmul($m['gross'], self::COMMISSION, Money::SCALE);
            $result[] = [
                'month' => new \DateTimeImmutable($key.'-01'),
                'gross' => $m['gross'],
                'fee' => $fee,
                'net' => bcsub($m['gross'], $fee, Money::SCALE),
                'items' => $m['items'],
                'status' => $key === $current ? 'scheduled' : 'paid',
            ];
        }

        return $result;
    }
}
