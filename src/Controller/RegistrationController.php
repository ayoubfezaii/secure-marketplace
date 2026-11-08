<?php

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

class RegistrationController extends AbstractController
{
    // SECURITY: fixed whitelist from the form choice to a role; ROLE_ADMIN can't be reached from here (rule 9).
    private const ACCOUNT_TYPE_TO_ROLE = [
        RegistrationFormType::ACCOUNT_BUYER => User::ROLE_BUYER,
        RegistrationFormType::ACCOUNT_SELLER => User::ROLE_SELLER,
    ];

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        AuditLogger $audit,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

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

            $accountType = $form->get('accountType')->getData();
            // SECURITY: second check after form validation: an unknown value is rejected, never mapped to a role.
            if (!isset(self::ACCOUNT_TYPE_TO_ROLE[$accountType])) {
                throw $this->createAccessDeniedException();
            }

            $user->setRoles([self::ACCOUNT_TYPE_TO_ROLE[$accountType]]);
            // SECURITY: Argon2id hash ("auto" in security.yaml, rule 5); the plain password is never stored.
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));

            $em->persist($user);
            $em->flush();

            $audit->log(AuditLog::REGISTER, ['email' => $user->getEmail(), 'role' => $user->getRoles()[0]], $user);

            $this->addFlash('success', 'Your account has been created. You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
