<?php

namespace App\Security;

/**
 * Open-redirect protection (rule 12): only same-site relative paths are accepted as redirect targets.
 */
final class SafeRedirect
{
    /**
     * Accepts "/orders?page=2"; rejects "https://evil.com", "//evil.com", "/\evil.com", "javascript:..."
     * and anything with control characters (header injection).
     */
    public static function isSafePath(?string $target): bool
    {
        if (null === $target || '' === $target || strlen($target) > 2048) {
            return false;
        }

        // SECURITY: must be an absolute PATH on this site, not a URL.
        if ('/' !== $target[0]) {
            return false;
        }

        // SECURITY: "//host" and "/\host" are protocol-relative URLs in browsers -> external site.
        if (isset($target[1]) && ('/' === $target[1] || '\\' === $target[1])) {
            return false;
        }

        // SECURITY: backslashes are normalised to "/" by some browsers; control chars enable header injection.
        if (str_contains($target, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return false;
        }

        $parts = parse_url($target);

        // SECURITY: a path must never parse to a scheme or a host.
        return false !== $parts && !isset($parts['scheme']) && !isset($parts['host']);
    }
}
