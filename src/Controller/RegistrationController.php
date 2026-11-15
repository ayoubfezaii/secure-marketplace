<?php

/**
 * FILE: src/Controller/RegistrationController.php
 * WHAT: controller of the "Create an account" page.
 * ROLE IN THE PROJECT: creates new accounts. Shows the defences against mass assignment (nobody can choose
 *   their role), weak password storage and mass account creation.
 * USED BY: visitors (not logged in).
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\AuditLog;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Registration page. */
class RegistrationController extends AbstractController
{
    /**
     * Shows the registration form (GET) and creates the account (POST). Visitor only.
     *
     * @param Request                     $request             the HTTP request
     * @param UserPasswordHasherInterface $passwordHasher      Symfony service that hashes passwords (Argon2id)
     * @param EntityManagerInterface      $em                  Doctrine, to save the new user
     * @param AuditLogger                 $audit               writes into the audit log
     * @param RateLimiterFactoryInterface $registrationLimiter limiter "registration" from config/packages/rate_limiter.yaml
     *
     * @return Response the form page, or a redirect to the login page after success
     */
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        AuditLogger $audit,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        // Already logged in: no need to register again.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Empty user + Symfony form. handleRequest() fills the user from the POST data, checks the CSRF token
        // and validates every constraint (email format, unique email, password policy).
        // SECURITY: mass assignment - the form has only the fields listed in RegistrationFormType; a hacked
        // request with "roles[]=ROLE_ADMIN" or "isVerified=1" is ignored (or rejected as an extra field).
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY (rule 6): max 5 accounts per IP per hour (mass fake-account creation / spam).
            if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $audit->log(AuditLog::RATE_LIMITED, ['limiter' => 'registration']);
                $this->addFlash('error', 'Too many accounts created from your network. Please try again later.');

                return $this->redirectToRoute('app_register');
            }

            // SECURITY (rules 4, 9): every self-registered account is a BUYER, set by the server. Sellers are promoted
            // only after an administrator approves their application; ROLE_ADMIN only via app:create-admin.
            $user->setRoles([User::ROLE_BUYER]);
            // SECURITY: Argon2id hash ("auto" in security.yaml, rule 5); the plain password is never stored.
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));

            // Save the account in the database.
            $em->persist($user);
            $em->flush();

            // SECURITY: logging - every account creation is written in the audit log.
            $audit->log(AuditLog::REGISTER, ['email' => $user->getEmail(), 'role' => $user->getRoles()[0]], $user);

            $this->addFlash('success', 'Your account has been created. You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        // First visit or invalid form: (re)display the form with its error messages.
        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
