<?php

namespace App\Tests\Security;

use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\Product;
use App\Repository\CartItemRepository;
use App\Tests\AppWebTestCase;

/**
 * Rule 10: the price sent by the client is ignored. Prices, quantities and stock are re-read from the database
 * inside the locked checkout transaction; the payment step accepts no extra field.
 */
final class CheckoutPriceTamperingTest extends AppWebTestCase
{
    public function testExtraPriceFieldInThePaymentFormIsRejected(): void
    {
        $this->loginAs('buyer1@shop.test');
        $this->chooseFirstAddress();

        $crawler = $this->client->request('GET', '/checkout/payment');
        $form = $crawler->selectButton('Review your order')->form([
            'checkout_form[cardHolder]' => 'Alice Martin',
            'checkout_form[cardNumber]' => '4242 4242 4242 4242',
            'checkout_form[expiry]' => '12/30',
            'checkout_form[cvv]' => '123',
        ]);
        $values = $form->getPhpValues();
        $values['checkout_form']['total'] = '0.01';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'extra fields');
    }

    public function testClientPriceIsIgnoredAndTheOrderUsesDatabasePrices(): void
    {
        $buyer = $this->loginAs('buyer1@shop.test');

        // Put a known product in the cart, sending a fake price with the (valid) form.
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e AND p.isActive = true AND p.stock > 5', ['e' => 'seller2@shop.test']);
        $crawler = $this->client->request('GET', '/products/'.$product->getUuid());
        $addForm = $crawler->filter('form[action="/cart/add/'.$product->getUuid().'"]')->form();
        $values = $addForm->getPhpValues();
        $values['quantity'] = '1';
        $values['price'] = '0.01';
        $values['unitPrice'] = '0.01';
        $this->client->request('POST', $addForm->getUri(), $values);
        self::assertResponseRedirects('/cart');

        // Expected total = current DB prices x quantities of the whole cart.
        $expected = '0.00';
        $lines = static::getContainer()->get(CartItemRepository::class)->findForUser($this->user('buyer1@shop.test'));
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            \assert($line instanceof CartItem);
            $expected = bcadd($expected, bcmul($line->getProduct()->getPrice(), (string) $line->getQuantity(), 2), 2);
        }
        $stockBefore = $product->getStock();
        $quantity = 0;
        foreach ($lines as $line) {
            if ($line->getProduct()->getId() === $product->getId()) {
                $quantity = $line->getQuantity();
            }
        }

        // 3-step checkout, tampering with the final POST.
        $this->chooseFirstAddress();
        $crawler = $this->client->request('GET', '/checkout/payment');
        $this->client->submit($crawler->selectButton('Review your order')->form([
            'checkout_form[cardHolder]' => 'Alice Martin',
            'checkout_form[cardNumber]' => '4242 4242 4242 4242',
            'checkout_form[expiry]' => '12/30',
            'checkout_form[cvv]' => '123',
        ]));
        self::assertResponseRedirects('/checkout/review');

        $crawler = $this->client->request('GET', '/checkout/review');
        $placeForm = $crawler->filter('form[action="/checkout/place"]')->form();
        $values = $placeForm->getPhpValues() + ['total' => '0.01', 'price' => '0.01', 'items' => ['x' => ['price' => '0.01']]];
        $this->client->request('POST', $placeForm->getUri(), $values);
        self::assertResponseRedirects();
        self::assertStringContainsString('/checkout/confirmation/', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em()->clear();
        $order = $this->findOne(Order::class, 'SELECT o FROM App\Entity\Order o WHERE o.buyer = :b ORDER BY o.id DESC', ['b' => $this->user('buyer1@shop.test')]);
        self::assertSame($expected, $order->getTotal(), 'The order total must come from the database prices, never from the request.');
        self::assertSame('4242', $order->getCardLast4());
        $fresh = $this->em()->find(Product::class, $product->getId());
        self::assertSame($stockBefore - $quantity, $fresh?->getStock(), 'Stock decremented by the real cart quantity.');
        self::assertSame([], static::getContainer()->get(CartItemRepository::class)->findForUser($this->user('buyer1@shop.test')), 'Cart emptied.');
        unset($buyer);
    }

    private function chooseFirstAddress(): void
    {
        $crawler = $this->client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Continue to payment')->form());
        self::assertResponseRedirects('/checkout/payment');
    }
}
