<?php

/**
 * FILE: src/Twig/StoreExtension.php
 * WHAT: Twig extension with the functions used by the shop header and product cards.
 * ROLE IN THE PROJECT: categories menu, cart counter, wishlist counter, "is this product in my wishlist?".
 * USED BY: templates/layouts/_store_header.html.twig and the product card macros (everyone; counters for buyers).
 */

namespace App\Twig;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\CategoryRepository;
use App\Repository\WishlistItemRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Header data for the storefront (categories menu, cart and wishlist counters, filled hearts).
 *
 * SECURITY (rule 4): the counters are computed for the LOGGED-IN user only (from the security token), never from a
 * request parameter. Results are memoised for the request so the header doesn't run the same query twice.
 */
final class StoreExtension extends AbstractExtension
{
    // Results kept in memory during the request so the same query is not run twice.
    /** @var list<Category>|null */
    private ?array $categories = null;
    /** @var array<int, true>|null */
    private ?array $wishlistIds = null;
    private ?int $cartCount = null;

    /**
     * @param Security               $security           gives the logged-in user and role checks
     * @param CategoryRepository     $categoryRepository categories of the menu
     * @param CartItemRepository     $cartItems          cart counter
     * @param WishlistItemRepository $wishlist           wishlist counter and hearts
     */
    public function __construct(
        private readonly Security $security,
        private readonly CategoryRepository $categoryRepository,
        private readonly CartItemRepository $cartItems,
        private readonly WishlistItemRepository $wishlist,
    ) {
    }

    /**
     * Declares the functions available in Twig: store_categories(), cart_count(), wishlist_count(),
     * in_wishlist(product) and can_shop().
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('store_categories', $this->categories(...)),
            new TwigFunction('cart_count', $this->cartCount(...)),
            new TwigFunction('wishlist_count', $this->wishlistCount(...)),
            new TwigFunction('in_wishlist', $this->inWishlist(...)),
            new TwigFunction('can_shop', $this->canShop(...)),
        ];
    }

    // All categories, loaded once per request.
    /** @return list<Category> */
    public function categories(): array
    {
        return $this->categories ??= $this->categoryRepository->findAllOrdered();
    }

    /** Buyers only: sellers and admins can't buy (role matrix, phase R5). */
    public function canShop(): bool
    {
        return $this->buyer() instanceof User;
    }

    /** Number of units in the cart of the logged-in buyer (0 for everyone else). */
    public function cartCount(): int
    {
        $buyer = $this->buyer();

        return null === $buyer ? 0 : ($this->cartCount ??= $this->cartItems->countUnitsForUser($buyer));
    }

    /** Number of products in the wishlist of the logged-in buyer (0 for everyone else). */
    public function wishlistCount(): int
    {
        return count($this->wishlistIds());
    }

    /** True when $product is in the wishlist of the logged-in buyer (to draw a filled heart). */
    public function inWishlist(Product $product): bool
    {
        return isset($this->wishlistIds()[(int) $product->getId()]);
    }

    // Product ids of the buyer's wishlist, loaded once per request.
    /** @return array<int, true> */
    private function wishlistIds(): array
    {
        $buyer = $this->buyer();

        return null === $buyer ? [] : ($this->wishlistIds ??= $this->wishlist->productIdsForUser($buyer));
    }

    /** The logged-in user if they are a buyer, null otherwise (visitor, seller, admin). */
    private function buyer(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->security->isGranted('ROLE_BUYER') ? $user : null;
    }
}
