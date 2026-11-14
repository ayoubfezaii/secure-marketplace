<?php

namespace App\Tests\Unit;

use App\Entity\Product;
use App\Service\Money;
use App\Twig\MoneyExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Currency: Tunisian dinar. One formatter for the whole site, and server-side limits on the only price a user can
 * type (a seller's product price): > 0, <= 300 000 DT, at most 3 decimals.
 */
final class MoneyTest extends KernelTestCase
{
    /** @return iterable<string, array{string|int|float|null, string}> */
    public static function amounts(): iterable
    {
        yield 'spec example' => [1250.5, '1 250,500 DT'];
        yield 'decimal string from the database' => ['35.000', '35,000 DT'];
        yield 'millimes' => ['18.500', '18,500 DT'];
        yield 'thousands' => ['1800', '1 800,000 DT'];
        yield 'maximum price' => ['300000.000', '300 000,000 DT'];
        yield 'zero' => [0, '0,000 DT'];
        yield 'null' => [null, '0,000 DT'];
        yield 'not a number' => ['<b>1</b>', '0,000 DT'];
    }

    #[DataProvider('amounts')]
    public function testFormat(string|int|float|null $amount, string $expected): void
    {
        self::assertSame($expected, Money::format($amount));
    }

    public function testDecimalForExports(): void
    {
        self::assertSame('1250.500', Money::decimal('1250.5'));
        self::assertSame('0.000', Money::decimal(null));
    }

    public function testThePriceFilterIsRegisteredAndNotMarkedSafe(): void
    {
        $filters = (new MoneyExtension())->getFilters();
        self::assertCount(1, $filters);
        self::assertSame('price', $filters[0]->getName());
        // Not "is_safe": Twig keeps auto-escaping the output (rule 2).
        self::assertSame([], $filters[0]->getSafe(new \Twig\Node\Nodes()) ?? []);

        self::bootKernel();
        $twig = static::getContainer()->get('twig');
        self::assertSame('1 250,500 DT', $twig->createTemplate('{{ 1250.5|price }}')->render());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function prices(): iterable
    {
        yield 'normal price' => ['35.500', true];
        yield 'no decimals' => ['250', true];
        yield 'one millime' => ['0.001', true];
        yield 'maximum' => ['300000', true];
        yield 'maximum with decimals' => ['300000.000', true];
        yield 'zero' => ['0', false];
        yield 'zero with decimals' => ['0.000', false];
        yield 'negative' => ['-5', false];
        yield 'above the maximum' => ['300000.001', false];
        yield 'far above the maximum' => ['999999', false];
        yield 'four decimals' => ['35.5001', false];
        yield 'scientific notation' => ['1e3', false];
        yield 'comma' => ['35,500', false];
        yield 'text' => ['abc', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('prices')]
    public function testProductPriceRules(string $price, bool $valid): void
    {
        self::bootKernel();
        $violations = static::getContainer()->get(ValidatorInterface::class)->validatePropertyValue(Product::class, 'price', $price);

        self::assertSame($valid, 0 === count($violations), sprintf('Price "%s" should be %s. %s', $price, $valid ? 'accepted' : 'refused', (string) $violations));
    }
}
