<?php

/**
 * FILE: src/Controller/SecurityController.php
 * WHAT: controller of the login page and of the logout route.
 * ROLE IN THE PROJECT: only DISPLAYS the login form. The real login work (CSRF check, password check, lockout,
 *   2FA) is done by Symfony Security, configured in config/packages/security.yaml.
 * USED BY: visitors (login) and every logged-in user (logout).
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** Login / logout routes. */
class SecurityController extends AbstractController
{
    /**
     * Shows the login form. The POST is handled by the form_login authenticator (security.yaml),
     * which checks the CSRF token, the rate limiter, the lockout and the password.
     *
     * @param AuthenticationUtils $authenticationUtils Symfony helper that gives the last error and the last email typed
     *
     * @return Response the rendered template security/login.html.twig
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Already logged in: go to the dashboard.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            // The email typed last time (to refill the field) and the error of the last failed attempt.
            // SECURITY: user enumeration - the error is always the generic "Invalid credentials", whether the
            // email exists or not.
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Never runs: the firewall intercepts this route. It accepts POST only, with a CSRF token (rule 3).
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }
}
