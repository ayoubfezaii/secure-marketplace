<?php

namespace App\Controller;

use App\Service\Money;
use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Buyer cart. All changes are POST + CSRF (rule 3). Lines are always looked up by (logged-in user, product), so a
 * buyer can never read or modify another buyer's cart (rule 4).
 */
#[IsGranted('ROLE_BUYER')]
#[Route('/cart')]
class CartController extends AbstractController
{
    public function __construct(
        private readonly CartItemRepository $cartItems,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'cart_index', methods: ['GET'])]
    public function index(): Response
    {
        $lines = $this->cartItems->findForUser($this->getBuyer());
        $total = Money::ZERO;
        foreach ($lines as $line) {
            // Display only: totals come from the CURRENT DB price. Checkout recomputes everything anyway.
            $total = bcadd($total, $line->getLineTotal(), Money::SCALE);
        }

        return $this->render('cart/index.html.twig', ['lines' => $lines, 'total' => $total]);
    }

    #[Route('/add/{uuid}', name: 'cart_add', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function add(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        $this->assertCsrf($request, 'cart-add-'.$product->getUuid());
        $buyer = $this->getBuyer();

        // SECURITY: hidden or out-of-stock products can't be added, even by forging the request.
        if (!$product->isPurchasable()) {
            $this->addFlash('error', 'This product is not available.');

            return $this->redirectToRoute('product_index');
        }
        // SECURITY: no buying your own listing.
        if ($product->getSeller()->getId() === $buyer->getId()) {
            throw $this->createAccessDeniedException('You cannot buy your own product.');
        }

        $quantity = $this->readQuantity($request);
        $line = $this->cartItems->findOneForUserAndProduct($buyer, $product);
        if (null === $line) {
            $line = new CartItem($buyer, $product, $quantity);
            $this->em->persist($line);
        } else {
            $line->setQuantity($line->getQuantity() + $quantity);
        }
        // Never more than the current stock (the checkout re-checks under lock anyway).
        if ($line->getQuantity() > $product->getStock()) {
            $line->setQuantity($product->getStock());
            $this->addFlash('warning', sprintf('Only %d unit(s) available; quantity adjusted.', $product->getStock()));
        }
        $this->em->flush();

        $this->addFlash('success', sprintf('"%s" added to your cart.', $product->getName()));

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/update/{uuid}', name: 'cart_update', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function update(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        $this->assertCsrf($request, 'cart-update-'.$product->getUuid());
        $line = $this->cartItems->findOneForUserAndProduct($this->getBuyer(), $product)
            ?? throw $this->createNotFoundException();

        $line->setQuantity(min($this->readQuantity($request), max(1, $product->getStock())));
        $this->em->flush();

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/remove/{uuid}', name: 'cart_remove', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function remove(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        $this->assertCsrf($request, 'cart-remove-'.$product->getUuid());
        $line = $this->cartItems->findOneForUserAndProduct($this->getBuyer(), $product);
        if (null !== $line) {
            $this->em->remove($line);
            $this->em->flush();
        }

        return $this->redirectToRoute('cart_index');
    }

    private function readQuantity(Request $request): int
    {
        // SECURITY (rule 15): integer 1..99 only; "-5", "1e9", "abc" are refused.
        $quantity = filter_var($request->request->getString('quantity', '1'), \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => CartItem::MAX_QUANTITY],
        ]);
        if (false === $quantity) {
            throw $this->createAccessDeniedException('Invalid quantity.');
        }

        return $quantity;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function getBuyer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
