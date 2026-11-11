<?php

namespace App\Tests\Security;

use App\Tests\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase R5 privilege matrix: every role requests the key routes of the OTHER roles and must be refused.
 * Server-side enforcement (access_control + #[IsGranted]); hiding links in the UI is not what is tested here.
 */
final class PrivilegeMatrixTest extends AppWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function protectedUrls(): iterable
    {
        foreach (['/buyer', '/buyer/orders', '/cart', '/checkout', '/buyer/wishlist', '/seller', '/seller/products', '/seller/payouts', '/admin', '/admin/users', '/admin/audit', '/account/profile'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('protectedUrls')]
    public function testVisitorIsSentToLogin(string $url): void
    {
        $this->client->request('GET', $url);
        $this->assertRedirectsToLogin();
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbidden(): iterable
    {
        $matrix = [
            'buyer1@shop.test' => ['/seller', '/seller/products', '/seller/products/new', '/seller/orders', '/seller/analytics', '/seller/payouts', '/admin', '/admin/users', '/admin/applications', '/admin/audit', '/admin/audit/export.csv', '/admin/security'],
            'seller1@shop.test' => ['/buyer', '/buyer/orders', '/buyer/wishlist', '/buyer/addresses', '/buyer/become-a-seller', '/cart', '/checkout', '/admin', '/admin/users', '/admin/categories', '/admin/audit'],
            'admin@shop.test' => ['/buyer', '/buyer/orders', '/cart', '/checkout', '/buyer/wishlist', '/seller', '/seller/products', '/seller/payouts', '/seller/shop'],
        ];
        foreach ($matrix as $email => $urls) {
            foreach ($urls as $url) {
                yield $email.' '.$url => [$email, $url];
            }
        }
    }

    #[DataProvider('forbidden')]
    public function testRoleCannotOpenOtherRolesArea(string $email, string $url): void
    {
        $this->loginAs($email);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403, sprintf('%s must not access %s', $email, $url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowed(): iterable
    {
        yield 'buyer dashboard' => ['buyer1@shop.test', '/buyer'];
        yield 'buyer cart' => ['buyer1@shop.test', '/cart'];
        yield 'seller dashboard' => ['seller1@shop.test', '/seller'];
        yield 'seller products' => ['seller1@shop.test', '/seller/products'];
        yield 'admin dashboard' => ['admin@shop.test', '/admin'];
        yield 'admin security center' => ['admin@shop.test', '/admin/security'];
        yield 'shared profile (seller)' => ['seller2@shop.test', '/account/profile'];
        yield 'shared security page (buyer)' => ['buyer2@shop.test', '/account/security'];
    }

    #[DataProvider('allowed')]
    public function testRoleCanOpenItsOwnArea(string $email, string $url): void
    {
        $this->loginAs($email);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testBlockedUserSessionIsEnded(): void
    {
        // buyer8 is blocked by an admin in the fixtures: even a valid session is ended on the next request.
        $this->loginAs('buyer8@shop.test');
        $this->client->request('GET', '/buyer');
        $this->assertRedirectsToLogin();
    }
}
