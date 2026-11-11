<?php

namespace App\Tests\Security;

use App\Entity\Address;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Security\Voter\UserManagementVoter;
use App\Tests\AppWebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Object-level access control (IDOR) and the "hard rules" of the privilege matrix.
 */
final class ObjectAccessTest extends AppWebTestCase
{
    public function testBuyerCannotOpenAnotherBuyersOrder(): void
    {
        $order = $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o JOIN o.buyer b WHERE b.email = :e', ['e' => 'buyer1@shop.test']);

        $this->loginAs('buyer2@shop.test');
        $this->client->request('GET', '/orders/'.$order->getUuid());
        self::assertResponseStatusCodeSame(403);

        // Control: the owner can open it.
        $this->loginAs('buyer1@shop.test');
        $this->client->request('GET', '/orders/'.$order->getUuid());
        self::assertResponseIsSuccessful();
    }

    public function testBuyerCannotEditOrDeleteAnotherBuyersAddress(): void
    {
        $address = $this->findOne(Address::class, 'SELECT a FROM App\Entity\Address a JOIN a.user u WHERE u.email = :e', ['e' => 'buyer1@shop.test']);

        $this->loginAs('buyer2@shop.test');
        $this->client->request('GET', '/buyer/addresses/'.$address->getUuid().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSellerCannotEditAnotherSellersProduct(): void
    {
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e', ['e' => 'seller1@shop.test']);

        $this->loginAs('seller2@shop.test');
        $this->client->request('GET', '/seller/products/'.$product->getUuid().'/edit');
        self::assertResponseStatusCodeSame(403);

        // Control: the owner can.
        $this->loginAs('seller1@shop.test');
        $this->client->request('GET', '/seller/products/'.$product->getUuid().'/edit');
        self::assertResponseIsSuccessful();
    }

    public function testSellerSeesOnlyOwnItemsAndNeverTheBuyerEmail(): void
    {
        $order = $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o JOIN o.items i JOIN i.seller s WHERE s.email = :e', ['e' => 'seller1@shop.test']);
        $buyerEmail = $order->getBuyer()->getEmail();

        $this->loginAs('seller1@shop.test');
        $this->client->request('GET', '/seller/orders/'.$order->getUuid());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($buyerEmail, $html, 'A seller must never see the buyer email.');
        self::assertStringNotContainsString($order->getCardHolder().' ·', $html, 'A seller must never see payment data.');
        foreach ($order->getItems() as $item) {
            if ('seller1@shop.test' !== $item->getSeller()->getEmail()) {
                self::assertStringNotContainsString($item->getProductName(), $html, 'Items of other sellers must be hidden.');
            }
        }

        // A seller with no item in this order gets a 403.
        $other = array_filter(['seller2@shop.test', 'seller3@shop.test', 'seller4@shop.test'], static fn (string $e) => !in_array($e, array_map(static fn ($i) => $i->getSeller()->getEmail(), $order->getItems()->toArray()), true));
        if ([] !== $other) {
            $this->loginAs(reset($other));
            $this->client->request('GET', '/seller/orders/'.$order->getUuid());
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testBuyerCannotReviewAProductHeNeverBought(): void
    {
        $buyer = $this->user('buyer2@shop.test');
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p WHERE p.isActive = true AND p.id NOT IN (SELECT IDENTITY(i.product) FROM App\Entity\OrderItem i JOIN i.order o WHERE o.buyer = :b AND i.product IS NOT NULL)', ['b' => $buyer]);

        $this->loginAs('buyer2@shop.test');
        $this->client->request('POST', '/products/'.$product->getUuid().'/reviews', ['review_form' => ['rating' => 5, 'comment' => 'Fake review']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotManageHimselfNorAnotherAdmin(): void
    {
        $admin = $this->user('admin@shop.test');
        $buyer = $this->user('buyer2@shop.test');
        $decisions = static::getContainer()->get(AccessDecisionManagerInterface::class);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        self::assertFalse($decisions->decide($token, [UserManagementVoter::MANAGE], $admin), 'An admin must not lock/demote/reset himself.');
        self::assertTrue($decisions->decide($token, [UserManagementVoter::MANAGE], $buyer));

        $otherAdmin = (new User())->setEmail('second.admin@shop.test')->setRoles([User::ROLE_ADMIN])->setPassword('x');
        self::assertFalse($decisions->decide($token, [UserManagementVoter::MANAGE], $otherAdmin), 'An admin must not manage another admin from the web.');

        // End to end: the admin's own page has no action form, and a lock POST on himself is refused.
        $this->loginAs('admin@shop.test');
        $crawler = $this->client->request('GET', '/admin/users/'.$admin->getUuid());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/block"]'));
        $this->client->request('POST', '/admin/users/'.$admin->getUuid().'/block', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotGrantTheAdminRole(): void
    {
        $buyer = $this->user('buyer4@shop.test');
        $this->loginAs('admin@shop.test');
        $crawler = $this->client->request('GET', '/admin/users/'.$buyer->getUuid());
        $form = $crawler->filter('form[action$="/role"]')->form();

        // Tamper with the hidden "role" value (Burp / DevTools): "admin" is not in the server whitelist.
        $values = $form->getPhpValues();
        $values['role'] = 'admin';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame([User::ROLE_BUYER, 'ROLE_USER'], $this->user('buyer4@shop.test')->getRoles());
    }
}
