<?php

/**
 * FILE: src/Security/LoginSuccessHandler.php
 * WHAT: decides which page is shown right after a successful login.
 * ROLE IN THE PROJECT: sends each role to its own dashboard and blocks "open redirect" attacks on the login form.
 * USED BY: Symfony Security (declared as success_handler in config/packages/security.yaml) for every user who
 *   logs in: buyer, seller, admin. DashboardController also reuses dashboardRoute().
 */

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Where to go after login. Used by BOTH form_login (password step) and two_factor (code step).
 *
 * 1. If a 2FA code is still required -> straight to the code form.
 * 2. If a safe internal _target_path was given (or stored by the firewall) -> go there.
 * 3. Otherwise -> the dashboard of the user's role.
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    // Trait from Symfony: gives getTargetPath() / removeTargetPath() to read the page the visitor wanted before login.
    use TargetPathTrait;

    // Name of the firewall in security.yaml.
    private const FIREWALL = 'main';
    // Session key where the wanted page is kept while the user types the 2FA code.
    private const TARGET_SESSION_KEY = '_app.login_target';

    /**
     * @param UrlGeneratorInterface $urls Symfony service that turns a route name into a URL
     */
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * Called by Symfony after the password is correct, and again after the 2FA code is correct.
     *
     * @param Request        $request the login request
     * @param TokenInterface $token   the security token of the user who just logged in
     *
     * @return Response always a redirect
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $session = $request->getSession();

        // Case 1: the password was right but a 2FA code is still needed (the token is only a "half login").
        if ($token instanceof TwoFactorTokenInterface) {
            // Keep the requested target (if safe) for after the code step.
            $target = $this->readTarget($request);
            if (null !== $target) {
                $session->set(self::TARGET_SESSION_KEY, $target);
            }

            return new RedirectResponse($this->urls->generate('2fa_login'));
        }

        // Case 2: fully logged in. Take the wanted page from the request, or the one saved before the 2FA step.
        $target = $this->readTarget($request) ?? $session->get(self::TARGET_SESSION_KEY);
        // Clean the session: these values are used once.
        $session->remove(self::TARGET_SESSION_KEY);
        $this->removeTargetPath($session, self::FIREWALL);

        // SECURITY: open redirect (rule 12). Only internal paths, re-checked here even for session values.
        if (is_string($target) && SafeRedirect::isSafePath($target)) {
            return new RedirectResponse($target);
        }

        // Case 3: no wanted page (or an unsafe one): go to the dashboard of the user's role.
        return new RedirectResponse($this->urls->generate(self::dashboardRoute($token->getUser())));
    }

    /**
     * Name of the home route of a user, depending on the role.
     *
     * @param mixed $user the logged-in user (or anything else when nobody is logged in)
     *
     * @return string a route name: admin_dashboard, seller_dashboard, buyer_dashboard or app_home
     */
    public static function dashboardRoute(mixed $user): string
    {
        if (!$user instanceof User) {
            return 'app_home';
        }

        $roles = $user->getRoles();

        return match (true) {
            in_array(User::ROLE_ADMIN, $roles, true) => 'admin_dashboard',
            in_array(User::ROLE_SELLER, $roles, true) => 'seller_dashboard',
            in_array(User::ROLE_BUYER, $roles, true) => 'buyer_dashboard',
            default => 'app_home',
        };
    }

    /**
     * Reads the page the user wanted to open, and keeps it only if it is safe.
     *
     * @return string|null an internal path like "/orders", or null
     */
    private function readTarget(Request $request): ?string
    {
        // _target_path from the login form, else the page the firewall stored before redirecting to /login.
        $target = $request->request->get('_target_path') ?? $request->query->get('_target_path')
            ?? $this->getTargetPath($request->getSession(), self::FIREWALL);

        if (!is_string($target)) {
            return null;
        }

        // The firewall stores absolute URLs (https://host/path): keep only the path + query of OUR host.
        if (str_starts_with($target, $request->getSchemeAndHttpHost().'/')) {
            $target = substr($target, strlen($request->getSchemeAndHttpHost()));
        }

        // SECURITY: open redirect - a link like /login?_target_path=https://evil.com is ignored, because only paths
        // inside this site pass isSafePath(). The victim can never be sent to a fake site after logging in.
        return SafeRedirect::isSafePath($target) ? $target : null;
    }
}
