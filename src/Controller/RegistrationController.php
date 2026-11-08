<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        LoggerInterface $securityLogger,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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

            $securityLogger->info('User registered', ['email' => $user->getEmail(), 'role' => $user->getRoles()[0]]);

            $this->addFlash('success', 'Your account has been created. You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
