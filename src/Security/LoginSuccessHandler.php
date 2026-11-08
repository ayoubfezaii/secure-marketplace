<?php

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
    use TargetPathTrait;

    private const FIREWALL = 'main';
    private const TARGET_SESSION_KEY = '_app.login_target';

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $session = $request->getSession();

        if ($token instanceof TwoFactorTokenInterface) {
            // Keep the requested target (if safe) for after the code step.
            $target = $this->readTarget($request);
            if (null !== $target) {
                $session->set(self::TARGET_SESSION_KEY, $target);
            }

            return new RedirectResponse($this->urls->generate('2fa_login'));
        }

        $target = $this->readTarget($request) ?? $session->get(self::TARGET_SESSION_KEY);
        $session->remove(self::TARGET_SESSION_KEY);
        $this->removeTargetPath($session, self::FIREWALL);

        // SECURITY: open redirect (rule 12). Only internal paths, re-checked here even for session values.
        if (is_string($target) && SafeRedirect::isSafePath($target)) {
            return new RedirectResponse($target);
        }

        return new RedirectResponse($this->urls->generate(self::dashboardRoute($token->getUser())));
    }

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

        return SafeRedirect::isSafePath($target) ? $target : null;
    }
}
