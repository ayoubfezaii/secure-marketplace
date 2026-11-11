<?php

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
    #[Route('', name: 'buyer_wishlist', methods: ['GET'])]
    public function index(WishlistItemRepository $wishlist, ProductRepository $products): Response
    {
        $items = $wishlist->findForUser($this->buyer());
        $products->preloadImages(array_map(static fn ($i) => $i->getProduct(), $items));

        return $this->render('buyer/wishlist.html.twig', ['items' => $items]);
    }

    /**
     * Move to cart: adds 1 unit to the cart (if available) and removes the line from the wishlist.
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
        $line = $wishlist->findOneForUserAndProduct($buyer, $product) ?? throw $this->createNotFoundException();

        // SECURITY: same rules as "add to cart": only active, in-stock products, never your own.
        if (!$product->isPurchasable() || $product->getSeller()->getId() === $buyer->getId()) {
            $this->addFlash('error', sprintf('"%s" is not available right now.', $product->getName()));

            return $this->redirectToRoute('buyer_wishlist');
        }

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

    private function buyer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
