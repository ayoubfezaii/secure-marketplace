<?php

/**
 * FILE: src/Controller/ResetPasswordController.php
 * WHAT: controller of "Forgot your password?": ask for a reset link, then choose a new password.
 * ROLE IN THE PROJECT: account recovery, built on symfonycasts/reset-password-bundle. Shows the defences against
 *   user enumeration, brute force of tokens and token leaks.
 * USED BY: visitors (not logged in).
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\NewPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\AuditLog;
use App\Service\AuditLogger;
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

/** Password reset pages. All URLs start with /reset-password. */
#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    // Trait from the bundle: small helpers to keep the token in the session (storeTokenInSession()...).
    use ResetPasswordControllerTrait;

    /**
     * @param ResetPasswordHelperInterface $resetPasswordHelper bundle service that creates and checks reset tokens
     * @param EntityManagerInterface       $em                  Doctrine, to save the new password
     * @param AuditLogger                  $audit               writes into the audit log
     */
    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * STEP 1 - form "enter your email". Visitor.
     *
     * @param UserRepository              $users                       to find the account
     * @param MailerInterface             $mailer                      Symfony mailer, sends the email
     * @param RateLimiterFactoryInterface $passwordResetRequestLimiter limiter from config/packages/rate_limiter.yaml
     * @param string                      $mailerFrom                  sender address (parameter app.mailer_from)
     *
     * @return Response the form, or a redirect to the "check your email" page
     */
    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $users,
        MailerInterface $mailer,
        RateLimiterFactoryInterface $passwordResetRequestLimiter,
        #[Autowire('%app.mailer_from%')] string $mailerFrom,
    ): Response {
        // Symfony form with one field (email) and a CSRF token.
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // SECURITY: per-IP rate limit on the "forgot password" form (rule 6).
            $limit = $passwordResetRequestLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                $this->audit->log(AuditLog::RATE_LIMITED, ['limiter' => 'password_reset_request']);
                $this->addFlash('error', 'Too many requests. Please try again later.');

                return $this->redirectToRoute('app_forgot_password_request');
            }

            // Look for the account. SECURITY: SQL injection - findOneByEmail() uses a bound parameter.
            $email = (string) $form->get('email')->getData();
            $user = $users->findOneByEmail($email);

            // Send the email only when the account exists...
            if ($user instanceof User) {
                $this->sendResetEmail($user, $mailer, $mailerFrom);
            }

            // SECURITY: user enumeration. Same redirect and same page whether or not the email exists,
            // and whether or not the per-account throttle was hit.
            $this->setCanCheckEmailInSession();

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', ['requestForm' => $form]);
    }

    /** STEP 2 - page "If an account exists, an email was sent". Visitor. */
    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // SECURITY: the page always shows the same text; it never says whether an email was actually sent.
        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetimeMinutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
        ]);
    }

    /**
     * STEP 3 - page opened from the link in the email: choose a new password. Visitor.
     * The route only accepts a token made of letters and digits (max 100 characters).
     *
     * @param UserPasswordHasherInterface $passwordHasher           hashes the new password
     * @param RateLimiterFactoryInterface $passwordResetTokenLimiter limiter from config/packages/rate_limiter.yaml
     * @param string|null                 $token                     token from the URL (null after the redirect)
     *
     * @return Response the new-password form, or a redirect
     */
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

        // Second visit (after the redirect): the token is read from the session.
        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found.');
        }

        // SECURITY: per-IP rate limit on token validation (rule 6).
        if (!$passwordResetTokenLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->addFlash('error', 'Too many requests. Please try again later.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        // Ask the bundle: is this token valid and not expired? If yes it returns the user.
        // SECURITY: brute force - tokens are long random values stored hashed; a wrong one is logged.
        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->audit->log(AuditLog::PASSWORD_RESET_INVALID, ['reason' => 'invalid_or_expired']);
            $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        // Form with the new password (typed twice) and the password policy.
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

            // Remove the token from the session and log the success.
            $this->cleanSessionAfterReset();
            $this->audit->log(AuditLog::PASSWORD_RESET_DONE, [], $user);
            $this->addFlash('success', 'Your password has been changed. You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['resetForm' => $form]);
    }

    /**
     * Creates a reset token and sends the email with the link.
     *
     * @param User            $user       the account that asked for the reset
     * @param MailerInterface $mailer     Symfony mailer
     * @param string          $mailerFrom sender address
     */
    private function sendResetEmail(User $user, MailerInterface $mailer, string $mailerFrom): void
    {
        try {
            // Create the token. Only its hash is stored in the database (see ResetPasswordRequest).
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            // Throttled (a request was made less than 15 min ago). Silently ignored: see enumeration note above.
            $this->audit->log(AuditLog::PASSWORD_RESET_REQUESTED, ['throttled' => true], $user);

            return;
        }

        // Build the email from a Twig template; the link contains the token.
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
            $this->audit->log(AuditLog::PASSWORD_RESET_REQUESTED, ['mail_error' => $e->getMessage()], $user);

            return;
        }

        // SECURITY: logging - the request is written in the audit log.
        $this->audit->log(AuditLog::PASSWORD_RESET_REQUESTED, [], $user);
    }
}
