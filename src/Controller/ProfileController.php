<?php

/**
 * FILE: src/Controller/ProfileController.php
 * WHAT: controller of "My profile" (display name, phone) and "Change password".
 * ROLE IN THE PROJECT: shows the defence against mass assignment with a DTO, and a safe password change.
 * USED BY: any fully logged-in user (buyer, seller, admin).
 */

namespace App\Controller;

use App\Dto\ProfileDto;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ProfileFormType;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Account pages. All URLs start with /account.
 * SECURITY: access control - IS_AUTHENTICATED_FULLY: must be logged in, 2FA step finished.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account')]
class ProfileController extends AbstractController
{
    /**
     * @param EntityManagerInterface $em    Doctrine, to save the user
     * @param AuditLogger            $audit writes into the audit log
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Profile page: shows and saves the display name and the phone. Any logged-in user.
     *
     * @return Response the rendered template account/profile.html.twig
     */
    #[Route('/profile', name: 'account_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        // SECURITY: IDOR - the user comes from the session; there is no user id in the URL to change.
        $user = $this->getAppUser();

        // SECURITY: mass assignment - the form fills a small DTO (2 fields), not the User entity. So a forged
        // field such as "roles" or "email" has nowhere to go.
        $dto = ProfileDto::fromUser($user);
        $form = $this->createForm(ProfileFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY (rule 9): explicit copy of the allowed fields only.
            $user->setDisplayName($dto->displayName);
            $user->setPhone($dto->phone);
            $this->em->flush();

            $this->audit->log(AuditLog::PROFILE_UPDATED, ['fields' => ['displayName', 'phone']]);
            $this->addFlash('success', 'Profile updated.');

            return $this->redirectToRoute('account_profile');
        }

        // HTTP 422 when the form has errors, 200 otherwise.
        return $this->render('account/profile.html.twig', [
            'form' => $form,
            'user' => $user,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Change password: asks for the current password and a new one. Any logged-in user.
     *
     * @param UserPasswordHasherInterface $hasher                hashes the new password
     * @param RateLimiterFactoryInterface $passwordChangeLimiter limiter from config/packages/rate_limiter.yaml
     * @param TokenStorageInterface       $tokenStorage          holds the login of the current request
     *
     * @return Response the form, or a redirect to the login page after success
     */
    #[Route('/password', name: 'account_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        RateLimiterFactoryInterface $passwordChangeLimiter,
        TokenStorageInterface $tokenStorage,
    ): Response {
        $user = $this->getAppUser();
        // The form checks the CSRF token, the current password and the password policy.
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // SECURITY (rule 6): limits guessing of the current password through a stolen session.
            if (!$passwordChangeLimiter->create('pwchange_'.$user->getId())->consume()->isAccepted()) {
                $this->audit->log(AuditLog::RATE_LIMITED, ['limiter' => 'password_change']);
                $this->addFlash('error', 'Too many attempts. Please try again later.');

                return $this->redirectToRoute('account_password');
            }

            if ($form->isValid()) {
                // Store the HASH of the new password (Argon2id), never the text.
                $user->setPassword($hasher->hashPassword($user, (string) $form->get('newPassword')->getData()));
                $this->em->flush();
                $this->audit->log(AuditLog::PASSWORD_CHANGED, [], $user);

                // SECURITY (rule 5): every session of this user is now invalid (the stored hash changed), including
                // this one: we log out cleanly and ask for a fresh login with the new password.
                $tokenStorage->setToken(null);
                $request->getSession()->invalidate();
                $this->addFlash('success', 'Password changed. Please log in again with your new password.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('account/password.html.twig', ['form' => $form]);
    }

    /** Returns the logged-in user as a User object (or stops with "access denied"). */
    private function getAppUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
