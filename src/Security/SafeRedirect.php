<?php

/**
 * FILE: src/Security/SafeRedirect.php
 * WHAT: one small function that says if a redirect target is safe (a path inside this site).
 * ROLE IN THE PROJECT: the defence against open redirect attacks.
 * USED BY: LoginSuccessHandler (after login) and controllers that redirect "back" to a page given in the request.
 */

namespace App\Security;

/**
 * Open-redirect protection (rule 12): only same-site relative paths are accepted as redirect targets.
 */
final class SafeRedirect
{
    /**
     * SECURITY: open redirect - an attacker sends a link to OUR site that redirects to THEIR site (phishing).
     * By accepting only internal paths, a redirect can never leave the site.
     *
     * Accepts "/orders?page=2"; rejects "https://evil.com", "//evil.com", "/\evil.com", "javascript:..."
     * and anything with control characters (header injection).
     *
     * @param string|null $target the redirect target received from the request or the session
     *
     * @return bool true only for a safe internal path
     */
    public static function isSafePath(?string $target): bool
    {
        // Empty or very long values are refused.
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

        // Last check: let PHP parse the value like a URL.
        $parts = parse_url($target);

        // SECURITY: a path must never parse to a scheme or a host.
        return false !== $parts && !isset($parts['scheme']) && !isset($parts['host']);
    }
}
