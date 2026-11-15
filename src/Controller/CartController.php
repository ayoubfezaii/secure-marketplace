<?php

/**
 * FILE: src/Controller/CartController.php
 * WHAT: controller of the shopping cart: show it, add a product, change a quantity, remove a product.
 * ROLE IN THE PROJECT: first step of buying. The cart stores only (user, product, quantity), never a price.
 * USED BY: buyers only (ROLE_BUYER). Sellers and admins get "access denied".
 */

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
    /**
     * @param CartItemRepository     $cartItems queries on the cart lines
     * @param EntityManagerInterface $em        Doctrine, to save changes
     */
    public function __construct(
        private readonly CartItemRepository $cartItems,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Cart page. Buyer only.
     *
     * @return Response the rendered template cart/index.html.twig
     */
    #[Route('', name: 'cart_index', methods: ['GET'])]
    public function index(): Response
    {
        // The cart lines of the logged-in buyer only.
        $lines = $this->cartItems->findForUser($this->getBuyer());
        $total = Money::ZERO;
        foreach ($lines as $line) {
            // Display only: totals come from the CURRENT DB price. Checkout recomputes everything anyway.
            $total = bcadd($total, $line->getLineTotal(), Money::SCALE);
        }

        return $this->render('cart/index.html.twig', ['lines' => $lines, 'total' => $total]);
    }

    /**
     * Adds a product to the cart. POST only. Buyer only.
     * The product is found by the UUID in the URL (404 if unknown).
     *
     * @param Request $request the HTTP request (fields: quantity, _token)
     * @param Product $product the product to add
     */
    #[Route('/add/{uuid}', name: 'cart_add', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function add(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY: CSRF - the token is checked first; a forged request from another site stops here.
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

        // Quantity typed by the buyer, validated (1..99).
        $quantity = $this->readQuantity($request);
        // Already in the cart? Then add to the existing line instead of creating a second one.
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
        // Save to the database.
        $this->em->flush();

        $this->addFlash('success', sprintf('"%s" added to your cart.', $product->getName()));

        return $this->redirectToRoute('cart_index');
    }

    /**
     * Changes the quantity of a product already in the cart. POST only. Buyer only.
     */
    #[Route('/update/{uuid}', name: 'cart_update', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function update(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY: CSRF - token checked first.
        $this->assertCsrf($request, 'cart-update-'.$product->getUuid());
        // SECURITY: IDOR - the line is searched in the cart of the logged-in buyer; 404 if the product is not there.
        $line = $this->cartItems->findOneForUserAndProduct($this->getBuyer(), $product)
            ?? throw $this->createNotFoundException();

        // New quantity: what was asked, but never more than the stock (and at least 1).
        $line->setQuantity(min($this->readQuantity($request), max(1, $product->getStock())));
        $this->em->flush();

        return $this->redirectToRoute('cart_index');
    }

    /**
     * Removes a product from the cart. POST only (SECURITY: CSRF - a simple link/GET could not delete). Buyer only.
     */
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

    /**
     * Reads and validates the "quantity" field of the form.
     *
     * @return int a whole number between 1 and 99
     */
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

    /**
     * SECURITY: CSRF - compares the hidden "_token" field with the token stored in the user's session.
     * Another website cannot know this token, so it cannot send a valid form in the buyer's name.
     *
     * @param string $id name of the token (different for each action and product)
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Returns the logged-in user as a User object (or stops with "access denied").
     */
    private function getBuyer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
