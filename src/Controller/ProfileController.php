<?php

namespace App\Controller;

use App\Dto\ProfileDto;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ProfileFormType;
use App\Service\AuditLogger;
use App\Service\FieldEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/profile', name: 'account_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, FieldEncryptor $encryptor): Response
    {
        $user = $this->getAppUser();
        $isSeller = in_array(User::ROLE_SELLER, $user->getRoles(), true);

        $dto = ProfileDto::fromUser($user);
        $form = $this->createForm(ProfileFormType::class, $dto, ['is_seller' => $isSeller]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY (rule 9): explicit copy of the allowed fields only.
            $user->setDisplayName($dto->displayName);
            $user->setPhone($dto->phone);

            $ibanChanged = false;
            if ($isSeller) {
                $iban = $dto->normalizedIban();
                if ($dto->removeIban) {
                    $user->removeIban();
                    $ibanChanged = true;
                } elseif (null !== $iban) {
                    // SECURITY (rule 11): encrypted before it touches the entity; the clear IBAN is never persisted.
                    $user->setEncryptedIban($encryptor->encrypt($iban), substr($iban, -4));
                    $ibanChanged = true;
                }
            }

            $this->em->flush();

            $this->audit->log(AuditLog::PROFILE_UPDATED, ['fields' => ['displayName', 'phone']]);
            if ($ibanChanged) {
                // SECURITY (rule 14): logged without the IBAN itself.
                $this->audit->log(AuditLog::IBAN_UPDATED, ['removed' => $dto->removeIban]);
            }
            $this->addFlash('success', 'Profile updated.');

            return $this->redirectToRoute('account_profile');
        }

        return $this->render('account/profile.html.twig', [
            'form' => $form,
            'user' => $user,
            'isSeller' => $isSeller,
        ]);
    }

    #[Route('/password', name: 'account_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        RateLimiterFactoryInterface $passwordChangeLimiter,
        TokenStorageInterface $tokenStorage,
    ): Response {
        $user = $this->getAppUser();
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

    private function getAppUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
