<?php

namespace App\Controller\Seller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Security\Voter\OrderVoter;
use App\Service\OrderManager;
use App\Service\Pagination;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Seller view of orders.
 *
 * SECURITY (rule 4 + privilege matrix): a seller only sees orders that contain at least one of THEIR items, and in
 * those orders only their own items plus the shipping name and address. The buyer's email, phone and card data
 * are never passed to the templates. For a cash-on-delivery order the seller sees the method and the amount to
 * collect (needed to deliver), nothing else.
 */
#[IsGranted('ROLE_SELLER')]
#[Route('/seller/orders')]
class OrderController extends AbstractController
{
    /**
     * Filter chips: key => [label, item statuses]. Also the whitelist of accepted "status" values (rule 15).
     * "to_ship" groups card items ("paid") and cash-on-delivery items ("placed").
     */
    public const FILTERS = [
        'to_ship' => ['To ship', Order::AWAITING_SHIPMENT],
        Order::STATUS_SHIPPED => ['Shipped', [Order::STATUS_SHIPPED]],
        Order::STATUS_DELIVERED => ['Delivered', [Order::STATUS_DELIVERED]],
        Order::STATUS_CANCELLED => ['Cancelled', [Order::STATUS_CANCELLED]],
        Order::STATUS_REFUNDED => ['Refunded', [Order::STATUS_REFUNDED]],
    ];

    #[Route('', name: 'seller_orders', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders): Response
    {
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist. Anything else means "no filter".
        $status = array_key_exists($status, self::FILTERS) ? $status : null;
        $seller = $this->seller();

        return $this->render('seller/orders/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($orders->createSellerQueryBuilder($seller, null !== $status ? self::FILTERS[$status][1] : []), $request, 15),
            'seller' => $seller,
            'status' => $status,
            'statuses' => array_map(static fn (array $f): string => $f[0], self::FILTERS),
            'params' => array_filter(['status' => $status]),
        ]);
    }

    #[Route('/{uuid}', name: 'seller_order_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order): Response
    {
        // SECURITY (rule 4, IDOR): only if this order contains at least one of my items.
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);
        $seller = $this->seller();
        $items = $order->getItemsForSeller($seller);

        $subtotal = '0.00';
        foreach ($items as $item) {
            $subtotal = bcadd($subtotal, $item->getLineTotal(), 2);
        }

        return $this->render('seller/orders/show.html.twig', [
            'order' => $order,
            // SECURITY: only the seller's own lines (never what the buyer bought from other shops).
            'items' => $items,
            'subtotal' => $subtotal,
            'nextStatuses' => array_filter(array_map(static fn (OrderItem $i) => $i->getNextStatus(), $items)),
        ]);
    }

    /**
     * Cash on delivery: the seller confirms the buyer paid in cash at the door (paymentStatus -> collected).
     */
    #[Route('/{uuid}/cash-collected', name: 'seller_order_cash_collected', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function cashCollected(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        // SECURITY (rule 4, IDOR): only the seller of every item of this order, and only for a delivered, unpaid
        // cash order. Another seller's order UUID -> 403 (and an access.denied audit entry).
        $this->denyAccessUnlessGranted(OrderVoter::CASH_COLLECT, $order);
        // SECURITY (rule 3): POST only + per-order CSRF token.
        if (!$this->isCsrfTokenValid('cash-collected-'.$order->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            // SECURITY: nothing is read from the request; the amount is the order total stored at checkout.
            $orders->collectCash($order);
            $this->addFlash('success', sprintf('Cash collected for order %s. It now counts in your revenue.', $order->getNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('seller_order_show', ['uuid' => $order->getUuid()]);
    }

    /**
     * Cash on delivery: the buyer refused the parcel. The order is cancelled and the stock restored (one transaction).
     */
    #[Route('/{uuid}/refused', name: 'seller_order_refused', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function refusedAtDelivery(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order, OrderManager $orders): Response
    {
        // SECURITY (rule 4, IDOR): same ownership rule as above; only while shipped/delivered and not paid.
        $this->denyAccessUnlessGranted(OrderVoter::CASH_REFUSE, $order);
        // SECURITY (rule 3): POST only + per-order CSRF token.
        if (!$this->isCsrfTokenValid('cash-refused-'.$order->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $orders->refuseDelivery($order);
            $this->addFlash('success', sprintf('Order %s marked as refused at delivery. It is cancelled and the items are back in stock.', $order->getNumber()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('seller_order_show', ['uuid' => $order->getUuid()]);
    }

    private function seller(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
