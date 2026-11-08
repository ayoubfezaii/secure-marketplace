<?php

namespace App\Controller;

use App\Dto\PaymentDto;
use App\Entity\User;
use App\Exception\CheckoutException;
use App\Form\CheckoutFormType;
use App\Repository\CartItemRepository;
use App\Service\CheckoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUYER')]
class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'checkout', methods: ['GET', 'POST'])]
    public function checkout(Request $request, CartItemRepository $cartItems, CheckoutService $checkout): Response
    {
        $buyer = $this->getUser();
        if (!$buyer instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $lines = $cartItems->findForUser($buyer);
        if ([] === $lines) {
            $this->addFlash('info', 'Your cart is empty.');

            return $this->redirectToRoute('cart_index');
        }

        $payment = new PaymentDto();
        $form = $this->createForm(CheckoutFormType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // SECURITY (rule 10): only the payment DTO is passed; prices, quantities and stock are re-read
                // from the database inside a locked transaction.
                $order = $checkout->placeOrder($buyer, $payment);
            } catch (CheckoutException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('cart_index');
            }

            $this->addFlash('success', 'Thank you! Your order has been placed.');

            return $this->redirectToRoute('order_show', ['uuid' => $order->getUuid()]);
        }

        // Summary for display only (DB prices).
        $total = '0.00';
        foreach ($lines as $line) {
            $total = bcadd($total, $line->getLineTotal(), 2);
        }

        $response = $this->render('checkout/index.html.twig', ['form' => $form, 'lines' => $lines, 'total' => $total], new Response(null, $form->isSubmitted() ? 422 : 200));
        // SECURITY (rule 11): a page that can contain card data is never cached by the browser or a proxy.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
