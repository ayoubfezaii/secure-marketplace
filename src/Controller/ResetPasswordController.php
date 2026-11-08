<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\NewPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        MailerInterface $mailer,
        RateLimiterFactoryInterface $passwordResetRequestLimiter,
        #[Autowire('%app.mailer_from%')] string $mailerFrom,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY: per-IP rate limit on the "forgot password" form (rule 6).
            $limit = $passwordResetRequestLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                $this->securityLogger->warning('Password reset rate limit hit', ['ip' => $request->getClientIp()]);
                $this->addFlash('error', 'Too many requests. Please try again later.');

                return $this->redirectToRoute('app_forgot_password_request');
            }

            $email = (string) $form->get('email')->getData();
            $user = $users->findOneByEmail($email);

            if ($user instanceof User) {
                $this->sendResetEmail($user, $mailer, $mailerFrom, $request);
            }

            // SECURITY: user enumeration. Same redirect and same page whether or not the email exists,
            // and whether or not the per-account throttle was hit.
            $this->setCanCheckEmailInSession();

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', ['requestForm' => $form]);
    }

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // SECURITY: the page always shows the same text; it never says whether an email was actually sent.
        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetimeMinutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9]{1,100}'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        RateLimiterFactoryInterface $passwordResetTokenLimiter,
        ?string $token = null,
    ): Response {
        if ($token) {
            // SECURITY: the token is moved from the URL into the session, then we redirect, so it doesn't leak
            // through the Referer header, browser history or proxy logs.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found.');
        }

        // SECURITY: per-IP rate limit on token validation (rule 6).
        if (!$passwordResetTokenLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->addFlash('error', 'Too many requests. Please try again later.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->securityLogger->warning('Invalid or expired password reset token used', ['ip' => $request->getClientIp()]);
            $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(NewPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY: single use. The token is deleted before the password changes.
            $this->resetPasswordHelper->removeResetRequest($token);

            // SECURITY: Argon2id hash (rule 5). Changing the hash also invalidates the user's other sessions
            // (User::__serialize stores a checksum of the hash).
            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            // The owner of the mailbox proved who they are, so the lockout is lifted.
            $user->resetFailedLogins();
            $this->em->flush();

            $this->cleanSessionAfterReset();
            $this->securityLogger->info('Password reset completed', ['email' => $user->getEmail(), 'ip' => $request->getClientIp()]);
            $this->addFlash('success', 'Your password has been changed. You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['resetForm' => $form]);
    }

    private function sendResetEmail(User $user, MailerInterface $mailer, string $mailerFrom, Request $request): void
    {
        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            // Throttled (a request was made less than 15 min ago). Silently ignored: see enumeration note above.
            $this->securityLogger->notice('Password reset throttled', ['email' => $user->getEmail(), 'reason' => $e->getReason()]);

            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($mailerFrom, 'Secure Marketplace'))
            ->to((string) $user->getEmail())
            ->subject('Your password reset request')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['resetToken' => $resetToken]);

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // SECURITY: no difference visible to the client; the error is only logged.
            $this->securityLogger->error('Password reset email could not be sent', ['error' => $e->getMessage()]);

            return;
        }

        $this->securityLogger->info('Password reset requested', ['email' => $user->getEmail(), 'ip' => $request->getClientIp()]);
    }
}
