<?php

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
    /** @var list<Category>|null */
    private ?array $categories = null;
    /** @var array<int, true>|null */
    private ?array $wishlistIds = null;
    private ?int $cartCount = null;

    public function __construct(
        private readonly Security $security,
        private readonly CategoryRepository $categoryRepository,
        private readonly CartItemRepository $cartItems,
        private readonly WishlistItemRepository $wishlist,
    ) {
    }

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

    public function cartCount(): int
    {
        $buyer = $this->buyer();

        return null === $buyer ? 0 : ($this->cartCount ??= $this->cartItems->countUnitsForUser($buyer));
    }

    public function wishlistCount(): int
    {
        return count($this->wishlistIds());
    }

    public function inWishlist(Product $product): bool
    {
        return isset($this->wishlistIds()[(int) $product->getId()]);
    }

    /** @return array<int, true> */
    private function wishlistIds(): array
    {
        $buyer = $this->buyer();

        return null === $buyer ? [] : ($this->wishlistIds ??= $this->wishlist->productIdsForUser($buyer));
    }

    private function buyer(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->security->isGranted('ROLE_BUYER') ? $user : null;
    }
}
