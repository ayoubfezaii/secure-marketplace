<?php

namespace App\Controller\Seller;

use App\Controller\OrderController as SharedOrderController;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Security\Voter\OrderVoter;
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
 * those orders only their own items plus the shipping name and address. The buyer's email, phone and payment data
 * are never passed to the templates.
 */
#[IsGranted('ROLE_SELLER')]
#[Route('/seller/orders')]
class OrderController extends AbstractController
{
    #[Route('', name: 'seller_orders', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders): Response
    {
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist (item statuses).
        $status = array_key_exists($status, SharedOrderController::STATUS_LABELS) ? $status : null;
        $seller = $this->seller();

        return $this->render('seller/orders/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($orders->createSellerQueryBuilder($seller, $status), $request, 15),
            'seller' => $seller,
            'status' => $status,
            'statuses' => SharedOrderController::STATUS_LABELS,
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

    private function seller(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
