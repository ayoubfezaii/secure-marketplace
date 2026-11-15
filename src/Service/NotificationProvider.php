<?php

/**
 * FILE: src/Service/NotificationProvider.php
 * WHAT: service that builds the list of notifications shown under the bell icon of the dashboards.
 * ROLE IN THE PROJECT: small UX feature; the items are counted live from the database.
 * USED BY: App\Twig\DashboardExtension -> templates/layouts/dashboard.html.twig (buyer, seller, admin).
 */

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\ReviewReply;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\SellerApplicationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Notification bell of the dashboards: a few counters computed on the fly (no notification table).
 *
 * SECURITY (rule 4): every query is scoped to the user passed in by the Twig extension, i.e. the logged-in user,
 * and the admin-only counters are only computed for admins.
 */
final class NotificationProvider
{
    /**
     * @param EntityManagerInterface      $em           Doctrine, to run count queries
     * @param UserRepository              $users        locked accounts (admin)
     * @param AuditLogRepository          $logs         failed logins (admin)
     * @param SellerApplicationRepository $applications pending applications (admin)
     * @param RouterInterface             $router       to check that the target route exists
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $logs,
        private readonly SellerApplicationRepository $applications,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * Returns the notifications of the given user, depending on the role.
     *
     * @param User $user the logged-in user
     *
     * @return list<array{icon: string, text: string, route: string, params: array<string, string>, tone: string}>
     */
    public function forUser(User $user): array
    {
        $items = [];
        $routes = $this->router->getRouteCollection();
        // Small helper: adds one notification, only if its target route exists.
        $add = static function (string $icon, string $text, string $route, array $params = [], string $tone = '') use (&$items, $routes): void {
            if (null !== $routes->get($route)) {
                $items[] = ['icon' => $icon, 'text' => $text, 'route' => $route, 'params' => $params, 'tone' => $tone];
            }
        };
        // Small helper: "1 order" / "2 orders".
        $plural = static fn (int $n, string $word) => sprintf('%d %s%s', $n, $word, $n > 1 ? 's' : '');

        // --- Admin: applications to review, locked accounts, failed logins ---
        if ($user->isAdmin()) {
            if (($pending = $this->applications->countPending()) > 0) {
                $add('person-badge', $plural($pending, 'seller application').' to review', 'admin_applications', ['status' => 'pending'], 'accent');
            }
            if (($locked = count($this->users->findLocked())) > 0) {
                $add('lock', $plural($locked, 'locked account'), 'admin_security', [], 'danger');
            }
            if (($failed = $this->logs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours'))) > 0) {
                $add('exclamation-triangle', $plural($failed, 'failed login').' in the last 24 h', 'admin_security', [], 'danger');
            }
        // --- Seller: items to ship, low stock, reviews without reply (all filtered on this seller) ---
        } elseif ($user->isSeller()) {
            $toShip = (int) $this->em->createQuery('SELECT COUNT(i.id) FROM '.OrderItem::class.' i WHERE i.seller = :u AND i.status IN (:toShip)')
                ->setParameter('u', $user)->setParameter('toShip', Order::AWAITING_SHIPMENT)->getSingleScalarResult();
            if ($toShip > 0) {
                $add('truck', $plural($toShip, 'item').' waiting to be shipped', 'seller_orders', ['status' => 'to_ship'], 'accent');
            }
            $low = (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.seller = :u AND p.isActive = true AND p.stock < 5')
                ->setParameter('u', $user)->getSingleScalarResult();
            if ($low > 0) {
                $add('exclamation-circle', $plural($low, 'product').' low on stock', 'seller_products', ['stock' => 'low'], 'danger');
            }
            $toReply = (int) $this->em->createQuery('SELECT COUNT(r.id) FROM '.Review::class.' r JOIN r.product p WHERE p.seller = :u AND NOT EXISTS (SELECT rr.id FROM '.ReviewReply::class.' rr WHERE rr.review = r)')
                ->setParameter('u', $user)->getSingleScalarResult();
            if ($toReply > 0) {
                $add('chat-left-text', $plural($toReply, 'review').' without your reply', 'seller_reviews', ['replied' => 'no']);
            }
        // --- Buyer: orders on the way (filtered on this buyer) ---
        } else {
            $shipped = (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.Order::class.' o WHERE o.buyer = :u AND o.status = :s')
                ->setParameter('u', $user)->setParameter('s', Order::STATUS_SHIPPED)->getSingleScalarResult();
            if ($shipped > 0) {
                $add('truck', $plural($shipped, 'order').' on the way', 'buyer_orders', ['status' => 'shipped'], 'accent');
            }
        }

        return $items;
    }
}
