<?php

namespace App\Tests\Security;

use App\Entity\CartItem;
use App\Entity\Product;
use App\Service\Money;
use App\Tests\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Currency: Tunisian dinar (TND / "DT", 3 decimals). Pages show DT only, the seller's price field is limited on the
 * server (> 0, <= 300 000 DT, 3 decimals), and the audit CSV export states the currency.
 */
final class CurrencyTest extends AppWebTestCase
{
    public function testStorefrontShowsDinarsAndNoEuro(): void
    {
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p WHERE p.isActive = true AND p.stock > 0 ORDER BY p.id ASC');

        foreach (['/', '/products', '/products/'.$product->getUuid()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('€', $html, $url);
            self::assertStringNotContainsString('EUR', $html, $url);
        }
        // The product page prints the database price through the |price filter.
        self::assertStringContainsString(Money::format($product->getPrice()), $html);
    }

    public function testDashboardsShowDinarsAndNoEuro(): void
    {
        // A cart line for buyer1, so the cart and checkout pages have amounts to show (other tests empty the cart).
        $em = $this->em();
        $buyer = $this->user('buyer1@shop.test');
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e AND p.isActive = true AND p.stock >= 10 ORDER BY p.id ASC', ['e' => 'seller3@shop.test']);
        $em->createQuery('DELETE FROM App\Entity\CartItem c WHERE c.user = :u')->setParameter('u', $buyer->getId())->execute();
        $em->persist(new CartItem($buyer, $product, 1));
        $em->flush();

        foreach ([
            'buyer1@shop.test' => ['/cart', '/checkout', '/buyer', '/buyer/orders'],
            'seller1@shop.test' => ['/seller', '/seller/orders', '/seller/analytics', '/seller/payouts', '/seller/products'],
            'admin@shop.test' => ['/admin', '/admin/orders', '/admin/products'],
        ] as $email => $urls) {
            $this->loginAs($email);
            foreach ($urls as $url) {
                $this->client->request('GET', $url);
                self::assertResponseIsSuccessful($url);
                $html = (string) $this->client->getResponse()->getContent();
                self::assertStringNotContainsString('€', $html, $url);
                self::assertStringNotContainsString('EUR', $html, $url);
                self::assertStringContainsString(' DT', $html, $url);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPrices(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-10'];
        yield 'above 300 000 DT' => ['300000.001'];
        yield 'four decimals (would be silently rounded)' => ['35.5001'];
        yield 'scientific notation' => ['1e2'];
    }

    #[DataProvider('refusedPrices')]
    public function testSellerCannotSaveAnInvalidPrice(string $price): void
    {
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e AND p.isActive = true ORDER BY p.id ASC', ['e' => 'seller1@shop.test']);
        $before = $product->getPrice();

        $this->loginAs('seller1@shop.test');
        $crawler = $this->client->request('GET', '/seller/products/'.$product->getUuid().'/edit');
        self::assertResponseIsSuccessful();
        // Raw POST: the browser's min/max/step attributes are bypassed, like an attacker would do.
        $values = $crawler->selectButton('Save changes')->form()->getPhpValues();
        $values['product_form']['price'] = $price;
        $this->client->request('POST', '/seller/products/'.$product->getUuid().'/edit', $values);
        self::assertResponseStatusCodeSame(422, 'Price "'.$price.'" must be refused.');

        $this->em()->clear();
        self::assertSame($before, $this->em()->find(Product::class, $product->getId())?->getPrice(), 'The stored price did not change.');
    }

    public function testSellerCanSaveAPriceWithMillimes(): void
    {
        $product = $this->findOne(Product::class, 'SELECT p FROM App\Entity\Product p JOIN p.seller s WHERE s.email = :e AND p.isActive = true AND p.compareAtPrice IS NULL ORDER BY p.id DESC', ['e' => 'seller1@shop.test']);
        $before = $product->getPrice();

        $this->loginAs('seller1@shop.test');
        $crawler = $this->client->request('GET', '/seller/products/'.$product->getUuid().'/edit');
        $form = $crawler->selectButton('Save changes')->form();
        $form['product_form[price]'] = '47.250';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em()->clear();
        $fresh = $this->em()->find(Product::class, $product->getId());
        self::assertSame('47.250', $fresh?->getPrice());

        // Put the fixture price back for the other tests.
        $fresh?->setPrice($before);
        $this->em()->flush();
    }

    public function testAuditCsvExportStatesTheCurrency(): void
    {
        $this->loginAs('admin@shop.test');
        $this->client->request('GET', '/admin/audit/export.csv?action=order.placed');
        self::assertResponseIsSuccessful();
        // Streamed response: the test client captures the output in its internal response.
        $csv = (string) $this->client->getInternalResponse()->getContent();

        $lines = array_values(array_filter(explode("\n", $csv)));
        self::assertStringContainsString('Currency: TND', $lines[0], 'The header states the currency.');
        self::assertGreaterThan(1, count($lines));
        // Every exported order amount has exactly 3 decimals and is followed by the currency code.
        foreach (array_slice($lines, 1) as $line) {
            self::assertMatchesRegularExpression('/,\d+\.\d{3},TND,/', $line);
        }
        self::assertStringNotContainsString('€', $csv);
    }
}
