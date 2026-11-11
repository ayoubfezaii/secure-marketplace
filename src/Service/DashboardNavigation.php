<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Routing\RouterInterface;

/**
 * Sidebar definition of the three dashboards.
 *
 * SECURITY (rule 4): the menu is chosen from the user's role on the SERVER, so each role only sees its own
 * sidebar. This is UX only: every target route is protected by access_control (security.yaml) + #[IsGranted] +
 * Voters, so hiding a link is never the protection itself.
 */
final class DashboardNavigation
{
    /** role => [section label => list of [route, label, icon, extra routes that highlight the item]] */
    private const MENUS = [
        User::ROLE_BUYER => [
            'Shopping' => [
                ['buyer_dashboard', 'Overview', 'grid-1x2', []],
                ['buyer_orders', 'My orders', 'box-seam', ['order_show']],
                ['buyer_wishlist', 'Wishlist', 'heart', []],
                ['buyer_addresses', 'Addresses', 'geo-alt', ['buyer_address_new', 'buyer_address_edit']],
                ['buyer_reviews', 'My reviews', 'star', ['buyer_review_edit']],
                ['buyer_seller_application', 'Become a seller', 'shop', []],
            ],
        ],
        User::ROLE_SELLER => [
            'My shop' => [
                ['seller_dashboard', 'Overview', 'grid-1x2', []],
                ['seller_products', 'Products', 'box-seam', ['seller_product_new', 'seller_product_edit']],
                ['seller_orders', 'Orders', 'receipt', ['seller_order_show', 'order_show']],
                ['seller_reviews', 'Reviews', 'chat-quote', []],
                ['seller_analytics', 'Analytics', 'graph-up-arrow', []],
                ['seller_shop', 'My shop', 'shop-window', []],
                ['seller_payouts', 'Payouts', 'bank', []],
            ],
        ],
        User::ROLE_ADMIN => [
            'Administration' => [
                ['admin_dashboard', 'Overview', 'grid-1x2', []],
                ['admin_users', 'Users', 'people', ['admin_user_show']],
                ['admin_applications', 'Seller applications', 'person-badge', ['admin_application_show']],
                ['admin_products', 'Products', 'box-seam', []],
                ['admin_categories', 'Categories', 'tags', ['admin_category_new', 'admin_category_edit']],
                ['admin_orders', 'Orders', 'receipt', ['admin_order_show']],
                ['admin_reviews', 'Reviews', 'chat-quote', []],
            ],
            'Security' => [
                ['admin_audit', 'Audit log', 'journal-text', []],
                ['admin_security', 'Security center', 'shield-check', []],
            ],
        ],
    ];

    /** Shared by every role. */
    private const ACCOUNT = [
        ['account_profile', 'Profile', 'person-circle', []],
        ['app_security_settings', 'Security', 'shield-lock', ['account_password']],
    ];

    public function __construct(private readonly RouterInterface $router)
    {
    }

    public static function roleOf(User $user): string
    {
        return match (true) {
            $user->isAdmin() => User::ROLE_ADMIN,
            $user->isSeller() => User::ROLE_SELLER,
            default => User::ROLE_BUYER,
        };
    }

    /**
     * @return array<string, list<array{route: string, label: string, icon: string, active: bool}>>
     */
    public function menu(User $user, ?string $currentRoute): array
    {
        $sections = self::MENUS[self::roleOf($user)];
        $sections['Account'] = self::ACCOUNT;

        $routes = $this->router->getRouteCollection();
        $menu = [];
        foreach ($sections as $title => $items) {
            foreach ($items as [$route, $label, $icon, $aliases]) {
                // Items whose route doesn't exist (yet) are skipped instead of breaking the page.
                if (null === $routes->get($route)) {
                    continue;
                }
                $menu[$title][] = [
                    'route' => $route,
                    'label' => $label,
                    'icon' => $icon,
                    'active' => $route === $currentRoute || in_array($currentRoute, $aliases, true),
                ];
            }
        }

        return $menu;
    }

    /** Where the top-bar search goes for each role (a GET list page with a "q" parameter). */
    public function searchRoute(User $user): ?string
    {
        $route = match (self::roleOf($user)) {
            User::ROLE_ADMIN => 'admin_users',
            User::ROLE_SELLER => 'seller_products',
            default => 'product_index',
        };

        return null !== $this->router->getRouteCollection()->get($route) ? $route : null;
    }

    public function searchPlaceholder(User $user): string
    {
        return match (self::roleOf($user)) {
            User::ROLE_ADMIN => 'Search users by email or name…',
            User::ROLE_SELLER => 'Search my products…',
            default => 'Search the shop…',
        };
    }
}
