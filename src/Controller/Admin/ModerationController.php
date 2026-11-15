<?php

/**
 * FILE: src/Controller/Admin/ModerationController.php
 * WHAT: controller of the admin moderation pages: all products, all orders, all reviews.
 * ROLE IN THE PROJECT: lets an admin hide or delete a product, cancel or refund an order, delete a seller reply.
 *   Every action follows the same 4 steps: POST, CSRF token, Voter, audit log.
 * USED BY: admins only (ROLE_ADMIN). URLs start with /admin.
 */

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\Review;
use App\Entity\ReviewReply;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
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
    /**
     * @param EntityManagerInterface $em    Doctrine, to save / delete
     * @param AuditLogger            $audit writes into the audit log
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ Products

    /**
     * List of ALL products (active or hidden) with search, filters, sorting, pagination. Admin only.
     *
     * @return Response the rendered template admin/products.html.twig
     */
    #[Route('/products', name: 'admin_products', methods: ['GET'])]
    public function products(Request $request, ProductRepository $products, CategoryRepository $categories, UserRepository $users): Response
    {
        // Search text and sort. SECURITY: SQL injection - the sort column comes from this whitelist only.
        $list = ListQuery::fromRequest($request, ['name' => 'p.name', 'price' => 'p.price', 'stock' => 'p.stock', 'created' => 'p.createdAt'], 'created');
        // SECURITY (rule 15): every filter is whitelisted or resolved to an existing row.
        $state = in_array($request->query->getString('state'), ['active', 'hidden'], true) ? $request->query->getString('state') : null;
        $category = 1 === preg_match('/^[a-z0-9-]{1,60}$/', $request->query->getString('category')) ? $categories->findOneBySlug($request->query->getString('category')) : null;
        $seller = $this->sellerFilter($request, $users);

        // Build the query, apply the sort, read one page of 20 and preload the images.
        $qb = $products->createAdminQueryBuilder($list->q, $state, $category, $seller);
        $list->applySort($qb)->addOrderBy('p.id', 'DESC');
        $pager = Pagination::fromQueryBuilder($qb, $request, 20);
        $rows = array_values(iterator_to_array($pager->items));
        $products->preloadImages($rows);

        // Active filters, kept in the pagination links.
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

    /**
     * Hides or shows any product. POST only. Admin only.
     */
    #[Route('/products/{uuid}/toggle', name: 'admin_product_toggle', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function toggleProduct(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY: CSRF then access control - the token, then ProductVoter::MODERATE (admin).
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

    /**
     * Deletes any product and its image files. POST only. Admin only.
     *
     * @param ImageStorage $images to delete the files from the disk
     */
    #[Route('/products/{uuid}/delete', name: 'admin_product_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function deleteProduct(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product, ImageStorage $images): Response
    {
        // SECURITY: CSRF then access control - the token, then ProductVoter::MODERATE (admin).
        $this->assertCsrf($request, 'admin-delete-'.$product->getUuid());
        $this->denyAccessUnlessGranted(ProductVoter::MODERATE, $product);

        // Keep what is needed after the deletion (log data, files to delete).
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

    /**
     * List of ALL orders with filters (status, payment, dates, seller, buyer search). Admin only.
     *
     * @return Response the rendered template admin/orders/index.html.twig
     */
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
        // SECURITY (rule 15): the payment filters are converted to enums with tryFrom(); an unknown value is ignored
        // (null) and never reaches the query.
        $paymentMethod = PaymentMethod::tryFrom($request->query->getString('payment'));
        $paymentStatus = PaymentStatus::tryFrom($request->query->getString('payment_status'));

        // Base query from the repository, then the optional filters. All values are bound parameters.
        $qb = $orders->createAdminQueryBuilder($status, $paymentMethod, $paymentStatus);
        if ('' !== $list->q) {
            // Buyer email or name (bound, LIKE-escaped).
            $qb->andWhere('(b.email LIKE :q OR b.displayName LIKE :q)')->setParameter('q', ListQuery::like($list->q));
        }
        if (null !== $from) {
            $qb->andWhere('o.createdAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            // "to" is a day: add one day and use "<" so the whole last day is included.
            $qb->andWhere('o.createdAt < :to')->setParameter('to', $to->modify('+1 day'));
        }
        // Only orders that contain an item of the chosen seller (sub-query written by us, seller bound).
        if (null !== $seller) {
            $qb->andWhere('o.id IN (SELECT IDENTITY(si.order) FROM App\Entity\OrderItem si WHERE si.seller = :seller)')->setParameter('seller', $seller);
        }
        $list->applySort($qb);

        // Active filters, kept in the pagination links.
        $filters = array_filter(['status' => $status, 'payment' => $paymentMethod?->value, 'payment_status' => $paymentStatus?->value, 'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d'), 'seller' => $seller ? (string) $seller->getUuid() : null, 'q' => $list->q]);

        return $this->render('admin/orders/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 20),
            'list' => $list,
            'status' => $status,
            'statuses' => Order::STATUSES,
            'paymentMethod' => $paymentMethod,
            'paymentMethods' => PaymentMethod::cases(),
            'paymentStatus' => $paymentStatus,
            'paymentStatuses' => PaymentStatus::cases(),
            'seller' => $seller,
            'sellers' => $users->createAdminQueryBuilder(null, User::ROLE_SELLER)->getQuery()->getResult(),
            'filters' => $filters,
            'params' => $filters + $list->params(),
        ]);
    }

    /** Detail of any order. Admin only (OrderVoter::VIEW accepts admins). */
    #[Route('/orders/{uuid}', name: 'admin_order_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function order(#[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order): Response
    {
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        return $this->render('admin/orders/show.html.twig', ['order' => $order]);
    }

    /**
     * Cancels an order that has not shipped yet. POST only. Admin only.
     *
     * @param OrderManager $orders service that cancels and restores the stock in one transaction
     */
    #[Route('/orders/{uuid}/cancel', name: 'admin_order_cancel', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function cancelOrder(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        // SECURITY: CSRF then access control - the token, then the voter (admin + order still cancellable).
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

    /**
     * Refunds a paid order (simulated). POST only. Admin only.
     *
     * @param OrderManager $orders service that refunds and restores the stock in one transaction
     */
    #[Route('/orders/{uuid}/refund', name: 'admin_order_refund', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function refundOrder(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        // SECURITY: CSRF then access control - the token, then the voter (admin + order refundable).
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

    /**
     * List of ALL reviews with filters (stars, search in product name or comment). Admin only.
     *
     * @return Response the rendered template admin/reviews.html.twig
     */
    #[Route('/reviews', name: 'admin_reviews', methods: ['GET'])]
    public function reviews(Request $request): Response
    {
        // ?rating= must be a whole number from 1 to 5; the search text is trimmed and limited to 100 characters.
        $rating = filter_var($request->query->getString('rating'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $rating = false === $rating ? null : $rating;
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);

        // SECURITY: SQL injection - QueryBuilder; :rating and :q are bound parameters, LIKE wildcards escaped.
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

    /**
     * Deletes a seller's reply to a review. POST only. Admin only.
     */
    #[Route('/replies/{uuid}/delete', name: 'admin_reply_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function deleteReply(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] ReviewReply $reply): Response
    {
        // SECURITY: CSRF then access control - the token, then ReviewVoter::REPLY_DELETE (admin).
        $this->assertCsrf($request, 'reply-delete-'.$reply->getUuid());
        $this->denyAccessUnlessGranted(ReviewVoter::REPLY_DELETE, $reply);

        $this->audit->log(AuditLog::REVIEW_REPLY_DELETED, ['review' => (string) $reply->getReview()->getUuid(), 'seller' => $reply->getAuthor()->getEmail()]);
        // Unlink the reply from its review, then delete it.
        $reply->getReview()->setReply(null);
        $this->em->remove($reply);
        $this->em->flush();
        $this->addFlash('success', 'Seller reply deleted.');

        return $this->redirectToRoute('admin_reviews');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Reads the ?seller=<uuid> filter and returns the matching seller.
     *
     * @return User|null null when the value is not a UUID, or not a seller account
     */
    private function sellerFilter(Request $request, UserRepository $users): ?User
    {
        $uuid = $request->query->getString('seller');
        if (!\Symfony\Component\Uid\Uuid::isValid($uuid)) {
            return null;
        }
        $user = $users->findOneBy(['uuid' => \Symfony\Component\Uid\Uuid::fromString($uuid)]);

        return $user instanceof User && $user->isSeller() ? $user : null;
    }

    /**
     * Stops the request when the hidden "_token" field is not the token of this session.
     *
     * @param string $id name of the token (one per action and per object)
     */
    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Parses a date written YYYY-MM-DD.
     *
     * @return \DateTimeImmutable|null null when the text is not a real date
     */
    private static function date(string $raw): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date && $date->format('Y-m-d') === $raw ? $date : null;
    }
}
