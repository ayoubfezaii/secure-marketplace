<?php

namespace App\Tests\Unit;

use App\Service\CsvSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV / formula injection protection of the audit-log export.
 */
final class CsvSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cells(): iterable
    {
        yield 'formula' => ['=HYPERLINK("http://evil","x")', '\'=HYPERLINK("http://evil","x")'];
        yield 'plus' => ['+1+1', "'+1+1"];
        yield 'minus' => ['-2+3', "'-2+3"];
        yield 'at' => ['@SUM(A1:A2)', "'@SUM(A1:A2)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'normal email' => ['buyer1@shop.test', 'buyer1@shop.test'];
        yield 'json' => ['{"a":"=1"}', '{"a":"=1"}'];
        yield 'control chars stripped' => ["abc\x07def", 'abcdef'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('cells')]
    public function testCell(string $input, string $expected): void
    {
        self::assertSame($expected, CsvSanitizer::cell($input));
    }
}
