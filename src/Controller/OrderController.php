<?php

/**
 * FILE: src/Controller/OrderController.php
 * WHAT: controller of the buyer's orders (list, detail, cancel) and of the status change of an order line.
 * ROLE IN THE PROJECT: best file to show the IDOR defence: every action on an order first asks OrderVoter.
 * USED BY: buyers (list, detail, cancel), sellers and admins (status of an order line; detail redirects them
 *   to their own order page).
 */

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Security\Voter\OrderVoter;
use App\Service\AuditLogger;
use App\Service\OrderManager;
use App\Service\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Order pages shared between roles. Each method has its own #[IsGranted] role. */
class OrderController extends AbstractController
{
    /** Status filter labels (also the whitelist of accepted values). */
    public const STATUS_LABELS = [
        Order::STATUS_PLACED => 'Placed', Order::STATUS_PAID => 'Paid', Order::STATUS_SHIPPED => 'Shipped', Order::STATUS_DELIVERED => 'Delivered',
        Order::STATUS_CANCELLED => 'Cancelled', Order::STATUS_REFUNDED => 'Refunded',
    ];

    /**
     * "My orders" list with an optional status filter and pagination. Buyer only.
     *
     * @return Response the rendered template buyer/orders.html.twig
     */
    #[IsGranted('ROLE_BUYER')]
    #[Route('/buyer/orders', name: 'buyer_orders', methods: ['GET'])]
    public function buyerOrders(Request $request, OrderRepository $orders): Response
    {
        // Status filter from the URL (?status=shipped).
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist.
        $status = array_key_exists($status, self::STATUS_LABELS) ? $status : null;

        // SECURITY (rule 4): scoped to the logged-in buyer by the query itself.
        return $this->render('buyer/orders.html.twig', [
            'pager' => Pagination::fromQueryBuilder($orders->createBuyerQueryBuilder($this->getAppUser(), $status), $request, 10),
            'status' => $status,
            'statuses' => self::STATUS_LABELS,
            'params' => array_filter(['status' => $status]),
        ]);
    }

    /**
     * Shared order URL (/orders/{uuid}): each role gets its own view of the same order.
     *
     * @param Order $order loaded by the UUID of the URL (404 if unknown)
     */
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/orders/{uuid}', name: 'order_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order): Response
    {
        // SECURITY (rule 4, IDOR): another user's order UUID -> 403.
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);
        $user = $this->getAppUser();

        // Admins and sellers have their own, different order page: send them there.
        if ($user->isAdmin() && $this->routeExists('admin_order_show')) {
            return $this->redirectToRoute('admin_order_show', ['uuid' => $order->getUuid()]);
        }
        if ($user->isSeller() && $this->routeExists('seller_order_show')) {
            return $this->redirectToRoute('seller_order_show', ['uuid' => $order->getUuid()]);
        }

        // Buyer view (the voter guarantees it is their own order).
        return $this->render('buyer/order_show.html.twig', ['order' => $order]);
    }

    /**
     * Buyer cancels their own order while nothing has shipped ("paid" card order or "placed" cash order). Stock is
     * restored in the same transaction.
     *
     * @param OrderManager $orders service that cancels the order and restores the stock in one transaction
     */
    #[IsGranted('ROLE_BUYER')]
    #[Route('/buyer/orders/{uuid}/cancel', name: 'buyer_order_cancel', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function cancel(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        // SECURITY (rule 3): POST + per-order CSRF token.
        if (!$this->isCsrfTokenValid('order-cancel-'.$order->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // SECURITY (rule 4): own order only (IDOR), and only while nothing has shipped.
        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order);

        // The service checks the state again; a DomainException means "too late to cancel".
        try {
            $orders->cancel($order, 'buyer');
            $this->addFlash('success', $order->isCashOnDelivery() ? 'Your order has been cancelled. There is nothing to pay.' : 'Your order has been cancelled. You will not be charged.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('order_show', ['uuid' => $order->getUuid()]);
    }

    /**
     * Seller (own items) or admin (any item) moves an item one step forward: paid -> shipped -> delivered
     * (cash on delivery: placed -> shipped -> delivered).
     *
     * @param OrderItem $item the order line, loaded by the UUID of the URL
     */
    #[IsGranted(new \Symfony\Component\ExpressionLanguage\Expression("is_granted('ROLE_SELLER') or is_granted('ROLE_ADMIN')"))]
    #[Route('/order-items/{uuid}/status', name: 'order_item_status', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function updateItemStatus(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] OrderItem $item,
        EntityManagerInterface $em,
        AuditLogger $audit,
    ): Response {
        // SECURITY (rule 3): POST + per-item CSRF token.
        if (!$this->isCsrfTokenValid('item-status-'.$item->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // SECURITY (rule 4): only the seller of THIS item, or an admin.
        $this->denyAccessUnlessGranted(OrderVoter::ITEM_UPDATE_STATUS, $item);

        // Remember the old status for the audit log.
        $from = $item->getStatus();
        try {
            // SECURITY: state machine, only the next step is accepted (paid -> shipped -> delivered).
            $item->advanceStatus($request->request->getString('status'));
        } catch (\DomainException) {
            $this->addFlash('error', 'This status change is not allowed.');

            return $this->redirect($this->backUrl($request, $item));
        }
        // Save the new status.
        $em->flush();

        // SECURITY: logging - who changed what, kept in the audit log.
        $audit->log(AuditLog::ORDER_STATUS_CHANGED, [
            'order' => (string) $item->getOrder()->getUuid(),
            'item' => (string) $item->getUuid(),
            'from' => $from,
            'to' => $item->getStatus(),
            'by' => $this->getAppUser()->isAdmin() ? 'admin' : 'seller',
        ]);
        $this->addFlash('success', sprintf('"%s" marked as %s.', $item->getProductName(), $item->getStatus()));

        return $this->redirect($this->backUrl($request, $item));
    }

    /**
     * Where to go after the status change, chosen by the hidden field "back".
     *
     * @return string an internal URL
     */
    private function backUrl(Request $request, OrderItem $item): string
    {
        $uuid = ['uuid' => $item->getOrder()->getUuid()];

        // SECURITY (rule 12): fixed set of internal destinations, never a user-supplied URL.
        return match ($request->request->getString('back')) {
            'dashboard' => $this->generateUrl($this->getAppUser()->isAdmin() ? 'admin_dashboard' : 'seller_dashboard'),
            'orders' => $this->generateUrl($this->getAppUser()->isAdmin() ? 'admin_orders' : 'seller_orders'),
            default => $this->generateUrl('order_show', $uuid),
        };
    }

    /** True when a route with this name exists (the seller/admin order pages). */
    private function routeExists(string $name): bool
    {
        return null !== $this->container->get('router')->getRouteCollection()->get($name);
    }

    /** Returns the logged-in user as a User object (or stops with "access denied"). */
    private function getAppUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
