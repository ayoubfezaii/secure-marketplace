<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Security\Voter\OrderVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OrderController extends AbstractController
{
    #[IsGranted('ROLE_BUYER')]
    #[Route('/buyer/orders', name: 'buyer_orders', methods: ['GET'])]
    public function buyerOrders(OrderRepository $orders): Response
    {
        // SECURITY: scoped to the logged-in buyer by the query itself.
        return $this->render('order/buyer_index.html.twig', ['orders' => $orders->findForBuyer($this->getAppUser())]);
    }

    #[IsGranted('ROLE_SELLER')]
    #[Route('/seller/orders', name: 'seller_orders', methods: ['GET'])]
    public function sellerOrders(OrderRepository $orders): Response
    {
        return $this->render('order/seller_index.html.twig', ['orders' => $orders->findForSeller($this->getAppUser())]);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/orders/{uuid}', name: 'order_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order): Response
    {
        // SECURITY (rule 4, IDOR): another user's order UUID -> 403.
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);
        $user = $this->getAppUser();

        $isBuyer = $order->getBuyer()->getId() === $user->getId();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        // SECURITY: a seller sees only their own lines of a multi-seller order, not what the buyer bought elsewhere.
        $items = ($isBuyer || $isAdmin) ? $order->getItems()->toArray() : $order->getItemsForSeller($user);

        return $this->render('order/show.html.twig', [
            'order' => $order,
            'items' => $items,
            'isBuyer' => $isBuyer,
            'isAdmin' => $isAdmin,
        ]);
    }

    #[IsGranted('ROLE_SELLER')]
    #[Route('/seller/order-items/{uuid}/status', name: 'seller_order_item_status', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function updateItemStatus(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] OrderItem $item,
        EntityManagerInterface $em,
        AuditLogger $audit,
    ): Response {
        // SECURITY (rule 4): only the seller of THIS item.
        $this->denyAccessUnlessGranted(OrderVoter::ITEM_UPDATE_STATUS, $item);
        // SECURITY (rule 3): POST + per-item CSRF token.
        if (!$this->isCsrfTokenValid('item-status-'.$item->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $from = $item->getStatus();
        try {
            // SECURITY: state machine, only the next step is accepted (paid -> shipped -> delivered).
            $item->advanceStatus($request->request->getString('status'));
        } catch (\DomainException) {
            $this->addFlash('error', 'This status change is not allowed.');

            return $this->redirect($this->backUrl($request, $item));
        }
        $em->flush();

        $audit->log(AuditLog::ORDER_STATUS_CHANGED, [
            'order' => (string) $item->getOrder()->getUuid(),
            'item' => (string) $item->getUuid(),
            'from' => $from,
            'to' => $item->getStatus(),
        ]);
        $this->addFlash('success', sprintf('"%s" marked as %s.', $item->getProductName(), $item->getStatus()));

        return $this->redirect($this->backUrl($request, $item));
    }

    private function backUrl(Request $request, OrderItem $item): string
    {
        // SECURITY (rule 12): fixed set of internal destinations, never a user-supplied URL.
        return match ($request->request->getString('back')) {
            'dashboard' => $this->generateUrl('seller_dashboard'),
            'orders' => $this->generateUrl('seller_orders'),
            default => $this->generateUrl('order_show', ['uuid' => $item->getOrder()->getUuid()]),
        };
    }

    private function getAppUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
