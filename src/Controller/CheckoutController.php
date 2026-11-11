<?php

namespace App\Controller;

use App\Dto\PaymentDto;
use App\Entity\Address;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\User;
use App\Exception\CheckoutException;
use App\Form\AddressFormType;
use App\Form\CheckoutFormType;
use App\Repository\AddressRepository;
use App\Repository\CartItemRepository;
use App\Security\Voter\OrderVoter;
use App\Service\CheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 3-step checkout: Address -> Payment -> Review -> confirmation.
 *
 * SECURITY design:
 *  - Buyers only (rule 4). Nothing the order is made of comes from the request: products, quantities, prices and
 *    stock are re-read from the database inside CheckoutService's locked transaction (rule 10).
 *  - Step 1 stores only the UUID of an address that was looked up SCOPED TO THE BUYER (rule 4, IDOR); it is
 *    re-checked at the last step (it may have been deleted meanwhile).
 *  - Step 2 "tokenises" the card like a real payment provider would: the number and CVV are validated (Luhn,
 *    expiry...) and immediately discarded. Only the holder name, the last 4 digits and a random single-use token
 *    (15 min) are kept in the server-side session (rule 11). The full card number is never stored, not even in the
 *    session.
 *  - Step 3 places the order with a POST carrying a CSRF token AND the payment token (rule 3); the token is
 *    compared in constant time and deleted after use (no replay / double order).
 */
#[IsGranted('ROLE_BUYER')]
#[Route('/checkout')]
class CheckoutController extends AbstractController
{
    private const SESSION_ADDRESS = '_checkout_address';
    private const SESSION_PAYMENT = '_checkout_payment';
    private const PAYMENT_TTL = 900;

    public function __construct(
        private readonly CartItemRepository $cartItems,
        private readonly AddressRepository $addresses,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'checkout', methods: ['GET', 'POST'])]
    public function address(Request $request): Response
    {
        $buyer = $this->buyer();
        if ([] === ($lines = $this->cartItems->findForUser($buyer))) {
            return $this->emptyCart();
        }

        $saved = $this->addresses->findForUser($buyer);
        $session = $request->getSession();

        // Choose a saved address.
        if ($request->isMethod('POST') && $request->request->has('address')) {
            // SECURITY (rule 3).
            if (!$this->isCsrfTokenValid('checkout-address', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            // SECURITY (rule 4): the UUID is resolved within the buyer's own addresses only.
            $address = $this->addresses->findOneForUser($buyer, $request->request->getString('address'));
            if (null === $address) {
                $this->addFlash('error', 'Please choose one of your addresses.');

                return $this->redirectToRoute('checkout');
            }
            $session->set(self::SESSION_ADDRESS, (string) $address->getUuid());

            return $this->redirectToRoute('checkout_payment');
        }

        // Or add a new one (max 5 per buyer).
        $newAddress = new Address($buyer);
        $newAddress->setFullName($buyer->getDisplayName());
        $form = $this->createForm(AddressFormType::class, $newAddress, ['action' => $this->generateUrl('checkout')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY (rule 15): hard cap on saved addresses per account.
            if (count($saved) >= Address::MAX_PER_USER) {
                $this->addFlash('error', sprintf('You can save up to %d addresses. Please remove one in your account first.', Address::MAX_PER_USER));

                return $this->redirectToRoute('checkout');
            }
            $this->em->persist($newAddress);
            if ($newAddress->isDefault() || [] === $saved) {
                $newAddress->setIsDefault(true);
                $this->em->flush();
                $this->addresses->makeDefault($newAddress);
            }
            $this->em->flush();
            $session->set(self::SESSION_ADDRESS, (string) $newAddress->getUuid());

            return $this->redirectToRoute('checkout_payment');
        }

        $selected = $session->get(self::SESSION_ADDRESS);

        return $this->render('checkout/address.html.twig', [
            'addresses' => $saved,
            'selected' => is_string($selected) ? $selected : ($saved[0] ?? null)?->getUuid()->toRfc4122(),
            'form' => $form,
            'showForm' => [] === $saved || $form->isSubmitted(),
            'canAdd' => count($saved) < Address::MAX_PER_USER,
            'summary' => $this->summary($lines),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/payment', name: 'checkout_payment', methods: ['GET', 'POST'])]
    public function payment(Request $request): Response
    {
        $buyer = $this->buyer();
        if ([] === ($lines = $this->cartItems->findForUser($buyer))) {
            return $this->emptyCart();
        }
        $address = $this->sessionAddress($request->getSession(), $buyer);
        if (null === $address) {
            return $this->redirectToRoute('checkout');
        }

        $payment = new PaymentDto();
        $form = $this->createForm(CheckoutFormType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY (rule 11): simulated tokenisation. Only what is needed later is kept; the full number and the
            // CVV are wiped from memory right now and never written anywhere (DB, session, logs).
            $request->getSession()->set(self::SESSION_PAYMENT, [
                'token' => bin2hex(random_bytes(16)),
                'holder' => trim((string) $payment->cardHolder),
                'last4' => $payment->getLast4(),
                'expiry' => (string) $payment->expiry,
                'expires' => time() + self::PAYMENT_TTL,
            ]);
            $payment->eraseSensitiveData();

            return $this->redirectToRoute('checkout_review');
        }

        return $this->noStore($this->render('checkout/payment.html.twig', [
            'form' => $form,
            'address' => $address,
            'summary' => $this->summary($lines),
        ], new Response(null, $form->isSubmitted() ? 422 : 200)));
    }

    #[Route('/review', name: 'checkout_review', methods: ['GET'])]
    public function review(Request $request): Response
    {
        $buyer = $this->buyer();
        if ([] === ($lines = $this->cartItems->findForUser($buyer))) {
            return $this->emptyCart();
        }
        $session = $request->getSession();
        $address = $this->sessionAddress($session, $buyer);
        $payment = $this->sessionPayment($session);
        if (null === $address) {
            return $this->redirectToRoute('checkout');
        }
        if (null === $payment) {
            return $this->redirectToRoute('checkout_payment');
        }

        return $this->noStore($this->render('checkout/review.html.twig', [
            'lines' => $lines,
            'address' => $address,
            'payment' => $payment,
            'summary' => $this->summary($lines),
        ]));
    }

    #[Route('/place', name: 'checkout_place', methods: ['POST'])]
    public function place(Request $request, CheckoutService $checkout): Response
    {
        $buyer = $this->buyer();
        // SECURITY (rule 3): CSRF token.
        if (!$this->isCsrfTokenValid('checkout-place', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $session = $request->getSession();
        $address = $this->sessionAddress($session, $buyer);
        $payment = $this->sessionPayment($session);
        // SECURITY: the payment token must match the one issued to THIS session (constant-time comparison).
        if (null === $address || null === $payment || !hash_equals($payment['token'], $request->request->getString('payment_token'))) {
            $this->addFlash('error', 'Your payment session has expired. Please enter your card again.');

            return $this->redirectToRoute(null === $address ? 'checkout' : 'checkout_payment');
        }
        // SECURITY: single use. Removed BEFORE placing the order, so a double submit can't create two orders.
        $session->remove(self::SESSION_PAYMENT);

        try {
            // SECURITY (rule 10): only the buyer, their own address and the masked card are passed. Prices,
            // quantities and stock are read from the database under lock by the service.
            $order = $checkout->placeOrder($buyer, $address, $payment['holder'], $payment['last4']);
        } catch (CheckoutException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('cart_index');
        }
        $session->remove(self::SESSION_ADDRESS);

        return $this->redirectToRoute('checkout_confirmation', ['uuid' => $order->getUuid()]);
    }

    #[Route('/confirmation/{uuid}', name: 'checkout_confirmation', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function confirmation(#[MapEntity(mapping: ['uuid' => 'uuid'])] Order $order): Response
    {
        // SECURITY (rule 4, IDOR): only the buyer of this order.
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);
        if ($order->getBuyer()->getId() !== $this->buyer()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('checkout/confirmation.html.twig', ['order' => $order]);
    }

    private function sessionAddress(SessionInterface $session, User $buyer): ?Address
    {
        $uuid = $session->get(self::SESSION_ADDRESS);

        // SECURITY (rule 4): re-resolved within the buyer's own addresses at every step.
        return is_string($uuid) ? $this->addresses->findOneForUser($buyer, $uuid) : null;
    }

    /**
     * @return array{token: string, holder: string, last4: string, expiry: string, expires: int}|null
     */
    private function sessionPayment(SessionInterface $session): ?array
    {
        $payment = $session->get(self::SESSION_PAYMENT);
        if (!is_array($payment) || !isset($payment['token'], $payment['holder'], $payment['last4'], $payment['expires']) || $payment['expires'] < time()) {
            $session->remove(self::SESSION_PAYMENT);

            return null;
        }

        return $payment;
    }

    /**
     * Display-only totals from the CURRENT database prices (the service recomputes everything anyway).
     *
     * @param list<CartItem> $lines
     *
     * @return array{lines: list<CartItem>, subtotal: string, shipping: string, total: string, units: int}
     */
    private function summary(array $lines): array
    {
        $subtotal = '0.00';
        $units = 0;
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
            $units += $line->getQuantity();
        }

        return ['lines' => $lines, 'subtotal' => $subtotal, 'shipping' => '0.00', 'total' => $subtotal, 'units' => $units];
    }

    private function emptyCart(): Response
    {
        $this->addFlash('info', 'Your cart is empty.');

        return $this->redirectToRoute('cart_index');
    }

    private function noStore(Response $response): Response
    {
        // SECURITY (rule 11): pages that can contain card data are never cached by the browser or a proxy.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function buyer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
