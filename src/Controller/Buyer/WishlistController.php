<?php

/**
 * FILE: src/Controller/Buyer/WishlistController.php
 * WHAT: controller of the wishlist page and of the "Move to cart" button.
 * ROLE IN THE PROJECT: buyer feature; the add / remove heart button is in App\Controller\WishlistController.
 * USED BY: buyers only (ROLE_BUYER). URLs start with /buyer/wishlist.
 */

namespace App\Controller\Buyer;

use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\ProductRepository;
use App\Repository\WishlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Buyer wishlist page. SECURITY (rule 4): scoped to the logged-in buyer by every query; no wishlist ids in URLs.
 */
#[IsGranted('ROLE_BUYER')]
#[Route('/buyer/wishlist')]
class WishlistController extends AbstractController
{
    /** Wishlist page. GET only. Buyer only. */
    #[Route('', name: 'buyer_wishlist', methods: ['GET'])]
    public function index(WishlistItemRepository $wishlist, ProductRepository $products): Response
    {
        $items = $wishlist->findForUser($this->buyer());
        // Load all the product images in one query (faster page).
        $products->preloadImages(array_map(static fn ($i) => $i->getProduct(), $items));

        return $this->render('buyer/wishlist.html.twig', ['items' => $items]);
    }

    /**
     * Move to cart: adds 1 unit to the cart (if available) and removes the line from the wishlist.
     *
     * @param Product $product loaded by the UUID of the URL
     */
    #[Route('/{uuid}/move-to-cart', name: 'buyer_wishlist_move', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function moveToCart(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product,
        WishlistItemRepository $wishlist,
        CartItemRepository $cartItems,
        EntityManagerInterface $em,
    ): Response {
        // SECURITY (rule 3): POST + per-product CSRF token.
        if (!$this->isCsrfTokenValid('wishlist-move-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $buyer = $this->buyer();
        // SECURITY: IDOR - the line is searched in the wishlist of the logged-in buyer only (404 otherwise).
        $line = $wishlist->findOneForUserAndProduct($buyer, $product) ?? throw $this->createNotFoundException();

        // SECURITY: same rules as "add to cart": only active, in-stock products, never your own.
        if (!$product->isPurchasable() || $product->getSeller()->getId() === $buyer->getId()) {
            $this->addFlash('error', sprintf('"%s" is not available right now.', $product->getName()));

            return $this->redirectToRoute('buyer_wishlist');
        }

        // Add one unit to the cart (new line, or +1 on the existing line while stock allows), then remove from the wishlist.
        $cart = $cartItems->findOneForUserAndProduct($buyer, $product);
        if (null === $cart) {
            $em->persist(new CartItem($buyer, $product, 1));
        } elseif ($cart->getQuantity() < min(CartItem::MAX_QUANTITY, $product->getStock())) {
            $cart->setQuantity($cart->getQuantity() + 1);
        }
        $em->remove($line);
        $em->flush();
        $this->addFlash('success', sprintf('"%s" moved to your cart.', $product->getName()));

        return $this->redirectToRoute('buyer_wishlist');
    }

    /** Returns the logged-in user as a User object (or stops with "access denied"). */
    private function buyer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
