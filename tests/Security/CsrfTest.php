<?php

namespace App\Tests\Security;

use App\Entity\Address;
use App\Entity\Order;
use App\Entity\Product;
use App\Tests\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rule 3: every state change is a POST carrying a valid CSRF token. A forged request (no / wrong token) is
 * rejected, and a GET on a POST-only route never changes anything (405).
 */
final class CsrfTest extends AppWebTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function forgedPosts(): iterable
    {
        yield 'add to cart' => ['buyer1@shop.test', 'cart-add'];
        yield 'wishlist toggle' => ['buyer1@shop.test', 'wishlist'];
        yield 'cancel order' => ['buyer1@shop.test', 'order-cancel'];
        yield 'delete address' => ['buyer1@shop.test', 'address-delete'];
        yield 'delete product' => ['seller1@shop.test', 'product-delete'];
        yield 'toggle product' => ['seller1@shop.test', 'product-toggle'];
        yield 'admin lock user' => ['admin@shop.test', 'admin-block'];
        yield 'admin refund' => ['admin@shop.test', 'admin-refund'];
    }

    #[DataProvider('forgedPosts')]
    public function testPostWithoutValidCsrfTokenIsRejected(string $email, string $action): void
    {
        $this->loginAs($email);
        $url = $this->url($action);

        $this->client->request('POST', $url, ['_token' => 'forged-token']);
        self::assertResponseStatusCodeSame(403, 'Forged token must be refused: '.$url);

        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403, 'Missing token must be refused: '.$url);
    }

    public function testStateChangingRoutesRefuseGet(): void
    {
        $this->loginAs('seller1@shop.test');
        $this->client->request('GET', $this->url('product-delete'));
        self::assertResponseStatusCodeSame(405);
    }

    public function testLogoutRequiresAToken(): void
    {
        $this->loginAs('buyer1@shop.test');
        $this->client->request('POST', '/logout', ['_csrf_token' => 'forged']);
        // Still logged in afterwards.
        $this->client->request('GET', '/buyer');
        self::assertResponseIsSuccessful();
    }

    private function url(string $action): string
    {
        $product = fn (string $seller) => $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e AND p.isActive = true AND p.stock > 0', ['e' => $seller]);

        return match ($action) {
            'cart-add' => '/cart/add/'.$product('seller2@shop.test')->getUuid(),
            'wishlist' => '/wishlist/'.$product('seller2@shop.test')->getUuid().'/toggle',
            'order-cancel' => '/buyer/orders/'.$this->findOne(Order::class, "SELECT o FROM App\Entity\Order o JOIN o.buyer b WHERE b.email = :e AND o.status = 'paid'", ['e' => 'buyer1@shop.test'])->getUuid().'/cancel',
            'address-delete' => '/buyer/addresses/'.$this->findOne(Address::class, 'SELECT a FROM App\Entity\Address a JOIN a.user u WHERE u.email = :e', ['e' => 'buyer1@shop.test'])->getUuid().'/delete',
            'product-delete' => '/seller/products/'.$product('seller1@shop.test')->getUuid().'/delete',
            'product-toggle' => '/seller/products/'.$product('seller1@shop.test')->getUuid().'/toggle',
            'admin-block' => '/admin/users/'.$this->user('buyer3@shop.test')->getUuid().'/block',
            'admin-refund' => '/admin/orders/'.$this->findOne(Order::class, "SELECT o FROM App\Entity\Order o WHERE o.status = 'delivered'")->getUuid().'/refund',
            default => throw new \LogicException($action),
        };
    }
}
