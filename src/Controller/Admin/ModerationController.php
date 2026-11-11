<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\Review;
use App\Entity\ReviewReply;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Security\Voter\OrderVoter;
use App\Security\Voter\ProductVoter;
use App\Security\Voter\ReviewVoter;
use App\Service\AuditLogger;
use App\Service\ImageStorage;
use App\Service\ListQuery;
use App\Service\OrderManager;
use App\Service\Pagination;
use App\Service\ProductRatingUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Products, orders and reviews moderation. Every state change: POST + per-object CSRF token + Voter + audit log.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class ModerationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ Products

    #[Route('/products', name: 'admin_products', methods: ['GET'])]
    public function products(Request $request, ProductRepository $products, CategoryRepository $categories, UserRepository $users): Response
    {
        $list = ListQuery::fromRequest($request, ['name' => 'p.name', 'price' => 'p.price', 'stock' => 'p.stock', 'created' => 'p.createdAt'], 'created');
        // SECURITY (rule 15): every filter is whitelisted or resolved to an existing row.
        $state = in_array($request->query->getString('state'), ['active', 'hidden'], true) ? $request->query->getString('state') : null;
        $category = 1 === preg_match('/^[a-z0-9-]{1,60}$/', $request->query->getString('category')) ? $categories->findOneBySlug($request->query->getString('category')) : null;
        $seller = $this->sellerFilter($request, $users);

        $qb = $products->createAdminQueryBuilder($list->q, $state, $category, $seller);
        $list->applySort($qb)->addOrderBy('p.id', 'DESC');
        $pager = Pagination::fromQueryBuilder($qb, $request, 20);
        $rows = iterator_to_array($pager->items);
        $products->preloadImages($rows);

        $filters = array_filter(['state' => $state, 'category' => $category?->getSlug(), 'seller' => $seller ? (string) $seller->getUuid() : null, 'q' => $list->q]);

        return $this->render('admin/products.html.twig', [
            'pager' => $pager,
            'products' => $rows,
            'list' => $list,
            'state' => $state,
            'category' => $category,
            'seller' => $seller,
            'categories' => $categories->findAllOrdered(),
            'sellers' => $users->createAdminQueryBuilder(null, User::ROLE_SELLER)->getQuery()->getResult(),
            'filters' => $filters,
            'params' => $filters + $list->params(),
        ]);
    }

    #[Route('/products/{uuid}/toggle', name: 'admin_product_toggle', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function toggleProduct(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        $this->assertCsrf($request, 'admin-toggle-'.$product->getUuid());
        $this->denyAccessUnlessGranted(ProductVoter::MODERATE, $product);

        $product->setIsActive(!$product->isActive());
        $this->em->flush();
        $this->audit->log($product->isActive() ? AuditLog::PRODUCT_REACTIVATED : AuditLog::PRODUCT_DEACTIVATED, [
            'product' => (string) $product->getUuid(), 'name' => $product->getName(), 'seller' => $product->getSeller()->getEmail(), 'by' => 'admin',
        ]);
        $this->addFlash('success', sprintf('"%s" %s.', $product->getName(), $product->isActive() ? 'reactivated' : 'deactivated'));

        return $this->redirectToRoute('admin_products');
    }

    #[Route('/products/{uuid}/delete', name: 'admin_product_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function deleteProduct(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product, ImageStorage $images): Response
    {
        $this->assertCsrf($request, 'admin-delete-'.$product->getUuid());
        $this->denyAccessUnlessGranted(ProductVoter::MODERATE, $product);

        $info = ['product' => (string) $product->getUuid(), 'name' => $product->getName(), 'seller' => $product->getSeller()->getEmail(), 'by' => 'admin'];
        $files = array_map(static fn (ProductImage $i) => $i->getFilename(), $product->getImages()->toArray());
        $this->em->remove($product);
        $this->em->flush();
        foreach ($files as $file) {
            $images->delete($file);
        }
        $this->audit->log(AuditLog::PRODUCT_DELETED, $info);
        $this->addFlash('success', sprintf('"%s" deleted.', $info['name']));

        return $this->redirectToRoute('admin_products');
    }

    // ------------------------------------------------------------------ Orders

    #[Route('/orders', name: 'admin_orders', methods: ['GET'])]
    public function orders(Request $request, OrderRepository $orders, UserRepository $users): Response
    {
        $list = ListQuery::fromRequest($request, ['date' => 'o.createdAt', 'total' => 'o.total'], 'date');
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist + strict date parsing + seller resolved to an existing seller.
        $status = in_array($status, Order::STATUSES, true) ? $status : null;
        $from = self::date($request->query->getString('from'));
        $to = self::date($request->query->getString('to'));
        $seller = $this->sellerFilter($request, $users);

        $qb = $orders->createAdminQueryBuilder($status);
        if ('' !== $list->q) {
            // Buyer email or name (bound, LIKE-escaped).
            $qb->andWhere('(b.email LIKE :q OR b.displayName LIKE :q)')->setParameter('q', ListQuery::like($list->q));
        }
        if (null !== $from) {
            $qb->andWhere('o.createdAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('o.createdAt < :to')->setParameter('to', $to->modify('+1 day'));
        }
        if (null !== $seller) {
            $qb->andWhere('o.id IN (SELECT IDENTITY(si.order) FROM App\Entity\OrderItem si WHERE si.seller = :seller)')->setParameter('seller', $seller);
        }
        $list->applySort($qb);

        $filters = array_filter(['status' => $status, 'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d'), 'seller' => $seller ? (string) $seller->getUuid() : null, 'q' => $list->q]);

        return $this->render('admin/orders/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 20),
            'list' => $list,
            'status' => $status,
            'statuses' => Order::STATUSES,
            'seller' => $seller,
            'sellers' => $users->createAdminQueryBuilder(null, User::ROLE_SELLER)->getQuery()->getResult(),
            'filters' => $filters,
            'params' => $filters + $list->params(),
        ]);
    }

    #[Route('/orders/{uuid}', name: 'admin_order_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function order(#[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order): Response
    {
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        return $this->render('admin/orders/show.html.twig', ['order' => $order]);
    }

    #[Route('/orders/{uuid}/cancel', name: 'admin_order_cancel', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function cancelOrder(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        $this->assertCsrf($request, 'admin-order-cancel-'.$order->getUuid());
        $this->denyAccessUnlessGranted(OrderVoter::ADMIN_CANCEL, $order);
        try {
            $orders->cancel($order, 'admin');
            $this->addFlash('success', sprintf('Order %s cancelled; stock restored.', $order->getNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_order_show', ['uuid' => $order->getUuid()]);
    }

    #[Route('/orders/{uuid}/refund', name: 'admin_order_refund', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function refundOrder(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        $this->assertCsrf($request, 'admin-order-refund-'.$order->getUuid());
        $this->denyAccessUnlessGranted(OrderVoter::REFUND, $order);
        try {
            $orders->refund($order);
            $this->addFlash('success', sprintf('Order %s refunded (simulated); stock restored.', $order->getNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_order_show', ['uuid' => $order->getUuid()]);
    }

    // ------------------------------------------------------------------ Reviews

    #[Route('/reviews', name: 'admin_reviews', methods: ['GET'])]
    public function reviews(Request $request): Response
    {
        $rating = filter_var($request->query->getString('rating'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $rating = false === $rating ? null : $rating;
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);

        $qb = $this->em->createQueryBuilder()
            ->select('r', 'p', 'a', 'rep')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')->innerJoin('r.author', 'a')->leftJoin('r.reply', 'rep')
            ->orderBy('r.createdAt', 'DESC');
        if (null !== $rating) {
            $qb->andWhere('r.rating = :rating')->setParameter('rating', $rating);
        }
        if ('' !== $q) {
            $qb->andWhere('(p.name LIKE :q OR r.comment LIKE :q)')->setParameter('q', ListQuery::like($q));
        }

        // Review deletion goes through ReviewController::delete (ReviewVoter + CSRF + audit), with back=admin.
        return $this->render('admin/reviews.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 15),
            'rating' => $rating,
            'q' => $q,
            'params' => array_filter(['rating' => $rating, 'q' => $q]),
        ]);
    }

    #[Route('/replies/{uuid}/delete', name: 'admin_reply_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function deleteReply(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] ReviewReply $reply): Response
    {
        $this->assertCsrf($request, 'reply-delete-'.$reply->getUuid());
        $this->denyAccessUnlessGranted(ReviewVoter::REPLY_DELETE, $reply);

        $this->audit->log(AuditLog::REVIEW_REPLY_DELETED, ['review' => (string) $reply->getReview()->getUuid(), 'seller' => $reply->getAuthor()->getEmail()]);
        $reply->getReview()->setReply(null);
        $this->em->remove($reply);
        $this->em->flush();
        $this->addFlash('success', 'Seller reply deleted.');

        return $this->redirectToRoute('admin_reviews');
    }

    // ------------------------------------------------------------------ helpers

    private function sellerFilter(Request $request, UserRepository $users): ?User
    {
        $uuid = $request->query->getString('seller');
        if (!\Symfony\Component\Uid\Uuid::isValid($uuid)) {
            return null;
        }
        $user = $users->findOneBy(['uuid' => \Symfony\Component\Uid\Uuid::fromString($uuid)]);

        return $user instanceof User && $user->isSeller() ? $user : null;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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
