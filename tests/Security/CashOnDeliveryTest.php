<?php

namespace App\Tests\Security;

use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Exception\CashOnDeliveryException;
use App\Repository\AddressRepository;
use App\Repository\OrderRepository;
use App\Security\Voter\OrderVoter;
use App\Service\CashOnDeliveryPolicy;
use App\Service\CheckoutService;
use App\Service\Money;
use App\Service\SellerAnalytics;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Cash on delivery: the payment method is a server-side whitelist, the card is only validated for card payments,
 * and the anti-abuse rules (max total, max pending orders, rate limit) and the seller-only "cash collected" action
 * are enforced on the server whatever the browser sends.
 *
 * Each test uses its own fixture buyer, so the tests don't depend on each other's orders.
 */
final class CashOnDeliveryTest extends AppWebTestCase
{
    private const CASH = 'cash_on_delivery';

    public function testCashOrderWithoutCardFieldsSucceeds(): void
    {
        $buyer = $this->loginAs('buyer5@shop.test');
        $product = $this->cheapProduct();
        $stockBefore = $product->getStock();
        $this->fillCart($buyer, $product, 2);
        $placedBefore = $this->countAudit(AuditLog::CASH_ORDER_PLACED, 'buyer5@shop.test');

        // Payment step: cash selected, card fields left EMPTY.
        $this->submitPayment(['checkout_form[paymentMethod]' => self::CASH]);
        self::assertResponseRedirects('/checkout/review');

        $crawler = $this->client->request('GET', '/checkout/review');
        self::assertSelectorTextContains('body', 'Cash on delivery');
        $this->client->submit($crawler->filter('form[action="/checkout/place"]')->form());
        self::assertResponseRedirects();
        self::assertStringContainsString('/checkout/confirmation/', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em()->clear();
        $order = $this->lastOrder('buyer5@shop.test');
        self::assertSame(PaymentMethod::CashOnDelivery, $order->getPaymentMethod());
        self::assertSame(PaymentStatus::Pending, $order->getPaymentStatus(), 'A cash order is not paid at checkout.');
        self::assertSame(Order::STATUS_PLACED, $order->getStatus());
        self::assertNull($order->getCardLast4(), 'No card data is stored for a cash order.');
        self::assertNull($order->getCardHolder());
        self::assertSame(bcmul($product->getPrice(), '2', Money::SCALE), $order->getTotal(), 'Total computed from the database price.');
        foreach ($order->getItems() as $item) {
            self::assertSame(Order::STATUS_PLACED, $item->getStatus());
        }
        // Stock is reserved exactly like for a card order.
        self::assertSame($stockBefore - 2, $this->em()->find(Product::class, $product->getId())?->getStock());
        self::assertSame($placedBefore + 1, $this->countAudit(AuditLog::CASH_ORDER_PLACED, 'buyer5@shop.test'), 'Cash order placed is audit-logged.');

        // The buyer can cancel it while it is not shipped: stock comes back.
        $crawler = $this->client->request('GET', '/orders/'.$order->getUuid());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action="/buyer/orders/'.$order->getUuid().'/cancel"]')->form());
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(Order::STATUS_CANCELLED, $this->lastOrder('buyer5@shop.test')->getStatus());
        self::assertSame($stockBefore, $this->em()->find(Product::class, $product->getId())?->getStock(), 'Stock restored after the cancellation.');
    }

    public function testCardFieldsAreStillRequiredForACardPayment(): void
    {
        $buyer = $this->loginAs('buyer5@shop.test');
        $this->fillCart($buyer, $this->cheapProduct(), 1);

        // Method "card" with empty card fields: the cash option must not weaken the card validation.
        $this->submitPayment(['checkout_form[paymentMethod]' => 'card']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCashOrderAboveTheLimitIsRejected(): void
    {
        $buyer = $this->loginAs('buyer6@shop.test');
        [$product, $quantity] = $this->expensiveLine();
        $this->fillCart($buyer, $product, $quantity);
        $ordersBefore = $this->countOrders('buyer6@shop.test');
        $blockedBefore = $this->countAudit(AuditLog::CASH_ORDER_BLOCKED, 'buyer6@shop.test');

        // 1. Refused at the payment step, with a clear message.
        $this->submitPayment(['checkout_form[paymentMethod]' => self::CASH]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cash on delivery is limited to orders up to 500,000 DT');
        $this->client->request('GET', '/checkout/review');
        self::assertResponseRedirects('/checkout/payment', null, 'No payment session was opened.');

        // 2. Bypass attempt: pass the payment step with a small cart, then grow the cart before placing the order.
        //    The server recomputes the total from the database inside the transaction and refuses.
        $cheap = $this->cheapProduct();
        $this->fillCart($buyer, $cheap, 1);
        $this->submitPayment(['checkout_form[paymentMethod]' => self::CASH]);
        self::assertResponseRedirects('/checkout/review');
        $crawler = $this->client->request('GET', '/checkout/review');
        $placeForm = $crawler->filter('form[action="/checkout/place"]')->form();

        $stockBefore = $product->getStock();
        $this->fillCart($buyer, $product, $quantity);
        // A forged total in the request changes nothing either.
        $this->client->request('POST', $placeForm->getUri(), $placeForm->getPhpValues() + ['total' => '10.00']);
        self::assertResponseRedirects('/checkout/payment');

        $this->em()->clear();
        self::assertSame($ordersBefore, $this->countOrders('buyer6@shop.test'), 'No order was created.');
        self::assertSame($stockBefore, $this->em()->find(Product::class, $product->getId())?->getStock(), 'The transaction was rolled back: stock untouched.');
        self::assertSame($blockedBefore + 2, $this->countAudit(AuditLog::CASH_ORDER_BLOCKED, 'buyer6@shop.test'), 'Both blocked attempts are audit-logged.');
    }

    public function testThirdPendingCashOrderIsRejected(): void
    {
        $buyer = $this->loginAs('buyer7@shop.test');
        $product = $this->cheapProduct();
        self::assertSame(0, static::getContainer()->get(OrderRepository::class)->countPendingCashOrders($buyer));

        for ($i = 0; $i < CashOnDeliveryPolicy::MAX_PENDING_ORDERS; ++$i) {
            $this->fillCart($buyer, $product, 1);
            $this->submitPayment(['checkout_form[paymentMethod]' => self::CASH]);
            self::assertResponseRedirects('/checkout/review');
            $crawler = $this->client->request('GET', '/checkout/review');
            $this->client->submit($crawler->filter('form[action="/checkout/place"]')->form());
            self::assertStringContainsString('/checkout/confirmation/', (string) $this->client->getResponse()->headers->get('Location'));
        }

        // Third one: refused at the payment step with a clear message...
        $this->fillCart($buyer, $product, 1);
        $this->submitPayment(['checkout_form[paymentMethod]' => self::CASH]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'cash-on-delivery orders waiting to be paid');

        // ...and by the checkout service itself (the real gate, inside the transaction), whatever the entry point.
        $fresh = $this->user('buyer7@shop.test');
        $address = static::getContainer()->get(AddressRepository::class)->findForUser($fresh)[0];
        try {
            static::getContainer()->get(CheckoutService::class)->placeOrder($fresh, $address, PaymentMethod::CashOnDelivery);
            self::fail('A third pending cash order must be refused.');
        } catch (CashOnDeliveryException $e) {
            self::assertSame(CashOnDeliveryException::REASON_MAX_PENDING, $e->reason);
        }
        self::ensureKernelShutdown();
        $this->client = static::createClient(['environment' => 'test'], ['HTTPS' => 'on']);
        self::assertSame(CashOnDeliveryPolicy::MAX_PENDING_ORDERS, static::getContainer()->get(OrderRepository::class)->countPendingCashOrders($this->user('buyer7@shop.test')));

        // Paying by card is still possible.
        $this->loginAs('buyer7@shop.test');
        $this->submitPayment([
            'checkout_form[paymentMethod]' => 'card',
            'checkout_form[cardHolder]' => 'Gabriel Roux',
            'checkout_form[cardNumber]' => '4242 4242 4242 4242',
            'checkout_form[expiry]' => '12/30',
            'checkout_form[cvv]' => '123',
        ]);
        self::assertResponseRedirects('/checkout/review');
    }

    public function testCashOrdersAreRateLimitedPerBuyer(): void
    {
        $buyer = $this->loginAs('buyer5@shop.test');
        $this->fillCart($buyer, $this->cheapProduct(), 1);
        $ordersBefore = $this->countOrders('buyer5@shop.test');

        // The buyer already used every attempt of the window.
        $limiter = static::getContainer()->get('limiter.cash_order')->create('cash_order_'.$buyer->getId());
        $limiter->reset();
        $limiter->consume(5);

        $this->submitPayment(['checkout_form[paymentMethod]' => self::CASH]);
        self::assertResponseRedirects('/checkout/review');
        $crawler = $this->client->request('GET', '/checkout/review');
        $this->client->submit($crawler->filter('form[action="/checkout/place"]')->form());
        self::assertResponseRedirects('/checkout/payment');

        $this->em()->clear();
        self::assertSame($ordersBefore, $this->countOrders('buyer5@shop.test'));
        static::getContainer()->get('limiter.cash_order')->create('cash_order_'.$buyer->getId())->reset();
    }

    public function testForgedPaymentMethodIsRejected(): void
    {
        $buyer = $this->loginAs('buyer6@shop.test');
        $this->fillCart($buyer, $this->cheapProduct(), 1);
        $ordersBefore = $this->countOrders('buyer6@shop.test');

        foreach (['free', 'CASH_ON_DELIVERY', 'cash', '', '1'] as $forged) {
            $crawler = $this->paymentPage();
            $values = $crawler->selectButton('Review your order')->form()->getPhpValues();
            $values['checkout_form']['paymentMethod'] = $forged;
            $this->client->request('POST', '/checkout/payment', $values);
            self::assertResponseStatusCodeSame(422, 'Forged payment method must be refused: '.var_export($forged, true));

            // Nothing was accepted: there is no payment session, so the review step sends the buyer back.
            $this->client->request('GET', '/checkout/review');
            self::assertResponseRedirects('/checkout/payment');
        }

        // The method can't be switched at the last step either: a card session + a forged field stays a card order.
        $this->submitPayment([
            'checkout_form[paymentMethod]' => 'card',
            'checkout_form[cardHolder]' => 'Farid Haddad',
            'checkout_form[cardNumber]' => '4242 4242 4242 4242',
            'checkout_form[expiry]' => '12/30',
            'checkout_form[cvv]' => '123',
        ]);
        $crawler = $this->client->request('GET', '/checkout/review');
        $placeForm = $crawler->filter('form[action="/checkout/place"]')->form();
        $this->client->request('POST', $placeForm->getUri(), $placeForm->getPhpValues() + ['paymentMethod' => self::CASH, 'payment_method' => self::CASH, 'paymentStatus' => 'collected']);
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertSame($ordersBefore + 1, $this->countOrders('buyer6@shop.test'));
        $order = $this->lastOrder('buyer6@shop.test');
        self::assertSame(PaymentMethod::Card, $order->getPaymentMethod());
        self::assertSame(PaymentStatus::Paid, $order->getPaymentStatus());
    }

    public function testSellerCannotMarkCashCollectedOnAnotherSellersOrder(): void
    {
        $order = $this->findOne(Order::class, "SELECT o FROM App\Entity\Order o JOIN o.items i JOIN i.seller s WHERE s.email = :e AND o.paymentMethod = 'cash_on_delivery' AND o.paymentStatus = 'pending' AND o.status = 'delivered'", ['e' => 'seller1@shop.test']);
        $uuid = (string) $order->getUuid();

        // The voter refuses another seller, the buyer and (for this action) the admin.
        $decisions = static::getContainer()->get(AccessDecisionManagerInterface::class);
        foreach (['seller2@shop.test', $order->getBuyer()->getEmail(), 'admin@shop.test'] as $email) {
            $user = $this->user($email);
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            self::assertFalse($decisions->decide($token, [OrderVoter::CASH_COLLECT], $order), $email.' must not be able to collect the cash.');
            self::assertFalse($decisions->decide($token, [OrderVoter::CASH_REFUSE], $order), $email.' must not be able to refuse the delivery.');
        }
        $owner = $this->user('seller1@shop.test');
        self::assertTrue($decisions->decide(new UsernamePasswordToken($owner, 'main', $owner->getRoles()), [OrderVoter::CASH_COLLECT], $order));

        // Over HTTP: another seller gets a 403 on both actions and nothing changes.
        $this->loginAs('seller2@shop.test');
        $this->client->request('POST', '/seller/orders/'.$uuid.'/cash-collected', ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/seller/orders/'.$uuid.'/refused', ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);
        // State changes are POST only.
        $this->client->request('GET', '/seller/orders/'.$uuid.'/cash-collected');
        self::assertResponseStatusCodeSame(405);

        // The buyer can't mark their own order as paid.
        $this->loginAs($order->getBuyer()->getEmail());
        $this->client->request('POST', '/seller/orders/'.$uuid.'/cash-collected', ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertSame(PaymentStatus::Pending, $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o WHERE o.id = :id', ['id' => $order->getId()])->getPaymentStatus());

        // Control: the seller of the order confirms the cash with the real form (CSRF token included), once.
        $revenueBefore = $this->sellerRevenue('seller1@shop.test');
        $this->loginAs('seller1@shop.test');
        $crawler = $this->client->request('GET', '/seller/orders/'.$uuid);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/seller/orders/'.$uuid.'/cash-collected"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/seller/orders/'.$uuid);

        $this->em()->clear();
        $fresh = $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o WHERE o.id = :id', ['id' => $order->getId()]);
        self::assertSame(PaymentStatus::Collected, $fresh->getPaymentStatus());
        // Revenue: the cash order was NOT counted while pending, and is counted once collected.
        self::assertSame(bcadd($revenueBefore, $fresh->getTotal(), Money::SCALE), $this->sellerRevenue('seller1@shop.test'));

        // Replaying the same request does nothing (already collected -> voter refuses).
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());
        self::assertResponseStatusCodeSame(403);
    }

    public function testRefusedAtDeliveryCancelsTheOrderAndRestoresStock(): void
    {
        $order = $this->findOne(Order::class, "SELECT o FROM App\Entity\Order o JOIN o.items i JOIN i.seller s WHERE s.email = :e AND o.paymentMethod = 'cash_on_delivery' AND o.paymentStatus = 'pending' AND o.status = 'shipped'", ['e' => 'seller2@shop.test']);
        $uuid = (string) $order->getUuid();
        $item = $order->getItems()->first();
        $productId = $item->getProduct()->getId();
        $stockBefore = $item->getProduct()->getStock();
        $quantity = $item->getQuantity();

        $this->loginAs('seller2@shop.test');
        $crawler = $this->client->request('GET', '/seller/orders/'.$uuid);
        $this->client->submit($crawler->filter('form[action="/seller/orders/'.$uuid.'/refused"]')->form());
        self::assertResponseRedirects('/seller/orders/'.$uuid);

        $this->em()->clear();
        $fresh = $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o WHERE o.id = :id', ['id' => $order->getId()]);
        self::assertSame(Order::STATUS_CANCELLED, $fresh->getStatus());
        self::assertSame(PaymentStatus::Pending, $fresh->getPaymentStatus(), 'Nothing was ever collected.');
        self::assertFalse($fresh->isCashPending(), 'A refused order no longer counts as pending cash.');
        self::assertSame($stockBefore + $quantity, $this->em()->find(Product::class, $productId)?->getStock(), 'Stock restored.');
    }

    public function testAdminFiltersByPaymentAndSeesCashToCollect(): void
    {
        $this->loginAs('admin@shop.test');

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Cash to collect');

        $crawler = $this->client->request('GET', '/admin/orders?payment=cash_on_delivery&payment_status=pending');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr');
        self::assertGreaterThan(0, $rows->count());
        $rows->each(static function (Crawler $row): void {
            self::assertStringContainsString('Cash on delivery', $row->text());
        });

        // A forged filter value is ignored (whitelist), it never reaches the query.
        $this->client->request('GET', '/admin/orders?payment=x%27%20OR%201=1--&payment_status=free');
        self::assertResponseIsSuccessful();

        // The other dashboards render with cash orders too.
        $this->loginAs('seller1@shop.test');
        foreach (['/seller', '/seller/orders', '/seller/orders?status=to_ship', '/seller/analytics', '/seller/payouts'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        $this->loginAs('buyer1@shop.test');
        foreach (['/buyer', '/buyer/orders', '/buyer/orders?status=placed'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        self::assertSelectorTextContains('body', 'Cash on delivery');
    }

    // ------------------------------------------------------------------ helpers

    private function sellerRevenue(string $email): string
    {
        $totals = static::getContainer()->get(SellerAnalytics::class)->totals($this->user($email), new \DateTimeImmutable('-1 year'), new \DateTimeImmutable('+1 day'));

        return Money::decimal($totals['revenue']);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitPayment(array $values): void
    {
        $crawler = $this->paymentPage();
        $this->client->submit($crawler->selectButton('Review your order')->form($values));
    }

    private function paymentPage(): Crawler
    {
        $crawler = $this->client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Continue to payment')->form());
        self::assertResponseRedirects('/checkout/payment');
        $crawler = $this->client->request('GET', '/checkout/payment');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Replaces the buyer's cart by one line (single seller, known price). */
    private function fillCart(User $buyer, Product $product, int $quantity): void
    {
        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\CartItem c WHERE c.user = :u')->setParameter('u', $buyer->getId())->execute();
        $em->persist(new CartItem($em->getReference(User::class, $buyer->getId()), $em->getReference(Product::class, $product->getId()), $quantity));
        $em->flush();
        $em->clear();
    }

    private function cheapProduct(): Product
    {
        return $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e AND p.isActive = true AND p.stock >= 10 AND p.price <= 100 ORDER BY p.id ASC', ['e' => 'seller3@shop.test']);
    }

    /**
     * A product and a quantity in stock whose total is above the cash limit.
     *
     * @return array{Product, int}
     */
    private function expensiveLine(): array
    {
        /** @var list<Product> $products */
        $products = $this->em()->createQuery('SELECT p FROM App\Entity\Product p WHERE p.isActive = true AND p.stock > 0 ORDER BY p.price DESC')->getResult();
        foreach ($products as $product) {
            $quantity = min($product->getStock(), CartItem::MAX_QUANTITY);
            if (bccomp(bcmul($product->getPrice(), (string) $quantity, Money::SCALE), CashOnDeliveryPolicy::MAX_ORDER_TOTAL, Money::SCALE) > 0) {
                return [$product, $quantity];
            }
        }
        self::fail('No fixture product can exceed the cash-on-delivery limit.');
    }

    private function lastOrder(string $email): Order
    {
        return $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o JOIN o.buyer b WHERE b.email = :e ORDER BY o.id DESC', ['e' => $email]);
    }

    private function countOrders(string $email): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(o.id) FROM App\Entity\Order o JOIN o.buyer b WHERE b.email = :e')->setParameter('e', $email)->getSingleScalarResult();
    }

    private function countAudit(string $action, string $email): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(a.id) FROM App\Entity\AuditLog a JOIN a.user u WHERE a.action = :a AND u.email = :e')
            ->setParameter('a', $action)->setParameter('e', $email)->getSingleScalarResult();
    }
}
