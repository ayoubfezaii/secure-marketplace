<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\AuditLogRepository;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $logs,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * @return list<array{icon: string, text: string, route: string, params: array<string, string>, tone: string}>
     */
    public function forUser(User $user): array
    {
        $items = [];
        $routes = $this->router->getRouteCollection();
        $add = static function (string $icon, string $text, string $route, array $params = [], string $tone = '') use (&$items, $routes): void {
            if (null !== $routes->get($route)) {
                $items[] = ['icon' => $icon, 'text' => $text, 'route' => $route, 'params' => $params, 'tone' => $tone];
            }
        };

        if ($user->isAdmin()) {
            $pending = $this->countPendingApplications();
            if ($pending > 0) {
                $add('person-badge', sprintf('%d seller application%s to review', $pending, $pending > 1 ? 's' : ''), 'admin_applications', ['status' => 'pending'], 'accent');
            }
            $locked = count($this->users->findLocked());
            if ($locked > 0) {
                $add('lock', sprintf('%d locked account%s', $locked, $locked > 1 ? 's' : ''), 'admin_security', [], 'danger');
            }
            $failed = $this->logs->countSince(AuditLog::LOGIN_FAILURE, new \DateTimeImmutable('-24 hours'));
            if ($failed > 0) {
                $add('exclamation-triangle', sprintf('%d failed login%s in the last 24 h', $failed, $failed > 1 ? 's' : ''), 'admin_security', [], 'danger');
            }
        } elseif ($user->isSeller()) {
            $toShip = (int) $this->em->createQuery('SELECT COUNT(i.id) FROM '.OrderItem::class.' i WHERE i.seller = :u AND i.status = :paid')
                ->setParameter('u', $user)->setParameter('paid', Order::STATUS_PAID)->getSingleScalarResult();
            if ($toShip > 0) {
                $add('truck', sprintf('%d item%s waiting to be shipped', $toShip, $toShip > 1 ? 's' : ''), 'seller_orders', ['status' => 'paid'], 'accent');
            }
            $low = (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Product::class.' p WHERE p.seller = :u AND p.isActive = true AND p.stock < 5')
                ->setParameter('u', $user)->getSingleScalarResult();
            if ($low > 0) {
                $add('exclamation-circle', sprintf('%d product%s low on stock', $low, $low > 1 ? 's' : ''), 'seller_products', ['stock' => 'low'], 'danger');
            }
            $toReply = $this->countUnrepliedReviews($user);
            if ($toReply > 0) {
                $add('chat-left-text', sprintf('%d review%s without your reply', $toReply, $toReply > 1 ? 's' : ''), 'seller_reviews', ['replied' => 'no']);
            }
        } else {
            $shipped = (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.Order::class.' o WHERE o.buyer = :u AND o.status = :s')
                ->setParameter('u', $user)->setParameter('s', Order::STATUS_SHIPPED)->getSingleScalarResult();
            if ($shipped > 0) {
                $add('truck', sprintf('%d order%s on the way', $shipped, $shipped > 1 ? 's' : ''), 'buyer_orders', ['status' => 'shipped'], 'accent');
            }
        }

        return $items;
    }

    private function countPendingApplications(): int
    {
        $class = 'App\Entity\SellerApplication';
        if (!class_exists($class)) {
            return 0;
        }

        return (int) $this->em->createQuery('SELECT COUNT(a.id) FROM '.$class.' a WHERE a.status = :s')
            ->setParameter('s', 'pending')->getSingleScalarResult();
    }

    private function countUnrepliedReviews(User $seller): int
    {
        $replyClass = 'App\Entity\ReviewReply';
        if (!class_exists($replyClass)) {
            return 0;
        }

        return (int) $this->em->createQuery('SELECT COUNT(r.id) FROM App\Entity\Review r JOIN r.product p WHERE p.seller = :u AND NOT EXISTS (SELECT rr.id FROM '.$replyClass.' rr WHERE rr.review = r)')
            ->setParameter('u', $seller)->getSingleScalarResult();
    }
}
