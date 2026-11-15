<?php

/**
 * FILE: src/Controller/SecuritySettingsController.php
 * WHAT: controller of the "Security" page of the account: turn 2FA on / off, see the login history,
 *   and show the change-password form.
 * ROLE IN THE PROJECT: the 2FA (TOTP) enrolment: generate a secret, show the QR code, confirm with a first code.
 * USED BY: any fully logged-in user (buyer, seller, admin).
 */

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use App\Entity\AuditLog;
use App\Service\AuditLogger;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Security settings": enable / disable TOTP two-factor authentication.
 * Every state change is a POST with a CSRF token (rule 3).
 */
// SECURITY: fully authenticated users only (a remembered or half-2FA session is not enough) (rule 5).
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account/security')]
class SecuritySettingsController extends AbstractController
{
    // Session key of the TOTP secret that waits for confirmation.
    private const PENDING_SECRET_KEY = '_totp_pending_secret';

    /**
     * @param TotpAuthenticatorInterface $totp  scheb/2fa service: creates secrets and checks 6-digit codes
     * @param EntityManagerInterface     $em    Doctrine, to save the user
     * @param AuditLogger                $audit writes into the audit log
     */
    public function __construct(
        private readonly TotpAuthenticatorInterface $totp,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * The security page. GET only. Any logged-in user.
     *
     * @param AuditLogRepository $logs to read the user's own login history
     *
     * @return Response the rendered template security/settings.html.twig
     */
    #[Route('', name: 'app_security_settings', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $logs): Response
    {
        $user = $this->getAppUser();
        $pendingSecret = $request->getSession()->get(self::PENDING_SECRET_KEY);

        // A QR code is shown only while an enrolment is in progress (secret in session, 2FA not enabled yet).
        $qrCode = null;
        if (is_string($pendingSecret) && !$user->isTotpAuthenticationEnabled()) {
            // Build the otpauth:// URI from a detached copy, the managed entity is never modified here.
            $candidate = clone $user;
            $candidate->setTotpSecret($pendingSecret);
            // SECURITY: QR code rendered server side as a data: URI. The secret is never sent to a third-party
            // QR service, and data: images are allowed by the CSP (img-src 'self' data:).
            $qrCode = (new Builder(writer: new SvgWriter(), data: $this->totp->getQRContent($candidate), size: 220, margin: 8))
                ->build()
                ->getDataUri();
        }

        return $this->render('security/settings.html.twig', [
            'totpEnabled' => $user->isTotpAuthenticationEnabled(),
            'qrCode' => $qrCode,
            'pendingSecret' => null !== $qrCode ? $pendingSecret : null,
            // The password form posts to ProfileController::changePassword (same CSRF id, same rate limit).
            'passwordForm' => $this->createForm(ChangePasswordFormType::class, null, ['action' => $this->generateUrl('account_password')]),
            // SECURITY (rule 14): the user's OWN login history only (scoped to the logged-in user).
            'loginHistory' => $logs->findLoginHistory($user, 20),
        ]);
    }

    /**
     * 2FA step 1 - "Enable": creates a secret and shows the QR code. POST only. Any logged-in user.
     */
    #[Route('/2fa/start', name: 'app_2fa_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        // SECURITY: CSRF - token checked first.
        $this->assertCsrf($request, 'totp_start');
        if ($this->getAppUser()->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_security_settings');
        }

        // SECURITY: the candidate secret lives only in the server-side session until the user proves they
        // scanned it correctly. 2FA is never enabled with a secret the user can't use (no self-lockout).
        $request->getSession()->set(self::PENDING_SECRET_KEY, $this->totp->generateSecret());

        return $this->redirectToRoute('app_security_settings');
    }

    /**
     * 2FA step 2 - "Confirm": the user types a first code from the app; if it is right, 2FA is switched on.
     * POST only. Any logged-in user.
     *
     * @param RateLimiterFactoryInterface $twoFactorSettingsLimiter limiter from config/packages/rate_limiter.yaml
     */
    #[Route('/2fa/confirm', name: 'app_2fa_confirm', methods: ['POST'])]
    public function confirm(Request $request, RateLimiterFactoryInterface $twoFactorSettingsLimiter): Response
    {
        $this->assertCsrf($request, 'totp_confirm');
        $user = $this->getAppUser();
        $session = $request->getSession();
        $pendingSecret = $session->get(self::PENDING_SECRET_KEY);

        // Nothing to confirm (no pending secret, or 2FA already on).
        if (!is_string($pendingSecret) || $user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_security_settings');
        }
        // SECURITY: brute force - limited number of code attempts.
        if (!$this->consumeLimiter($twoFactorSettingsLimiter, $user)) {
            return $this->redirectToRoute('app_security_settings');
        }

        // Check the typed code against the pending secret, on a copy of the user (the real user is not changed yet).
        $code = $this->readCode($request);
        $candidate = clone $user;
        $candidate->setTotpSecret($pendingSecret);

        if (null === $code || !$this->totp->checkCode($candidate, $code)) {
            $this->addFlash('error', 'Invalid code. Check the time on your phone and try again.');

            return $this->redirectToRoute('app_security_settings');
        }

        // Code is right: store the secret on the account, enable 2FA, save, and forget the pending secret.
        $user->setTotpSecret($pendingSecret);
        $user->enableTotp();
        $this->em->flush();
        $session->remove(self::PENDING_SECRET_KEY);

        // SECURITY: logging - 2FA changes are written in the audit log.
        $this->audit->log(AuditLog::TWO_FA_ENABLED);
        $this->addFlash('success', 'Two-factor authentication is now enabled. You will need a code at every login.');

        return $this->redirectToRoute('app_security_settings');
    }

    /** Cancels an enrolment in progress (forgets the pending secret). POST only. */
    #[Route('/2fa/cancel', name: 'app_2fa_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        $this->assertCsrf($request, 'totp_cancel');
        $request->getSession()->remove(self::PENDING_SECRET_KEY);

        return $this->redirectToRoute('app_security_settings');
    }

    /**
     * Turns 2FA off. POST only. Any logged-in user with 2FA enabled.
     *
     * @param UserPasswordHasherInterface $passwordHasher           to check the current password
     * @param RateLimiterFactoryInterface $twoFactorSettingsLimiter limiter from config/packages/rate_limiter.yaml
     */
    #[Route('/2fa/disable', name: 'app_2fa_disable', methods: ['POST'])]
    public function disable(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        RateLimiterFactoryInterface $twoFactorSettingsLimiter,
    ): Response {
        $this->assertCsrf($request, 'totp_disable');
        $user = $this->getAppUser();

        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_security_settings');
        }
        if (!$this->consumeLimiter($twoFactorSettingsLimiter, $user)) {
            return $this->redirectToRoute('app_security_settings');
        }

        // The form asks for the current password AND a current code.
        $password = $request->request->getString('current_password');
        $code = $this->readCode($request);

        // SECURITY: re-authentication with BOTH factors. A stolen session (XSS, unlocked computer) is not
        // enough to remove 2FA from the account.
        if (!$passwordHasher->isPasswordValid($user, $password) || null === $code || !$this->totp->checkCode($user, $code)) {
            $this->audit->log(AuditLog::TWO_FA_FAILURE, ['context' => 'disable_rejected']);
            $this->addFlash('error', 'Wrong password or code. Two-factor authentication is still enabled.');

            return $this->redirectToRoute('app_security_settings');
        }

        // Both are right: wipe the secret and switch 2FA off.
        $user->disableTotp();
        $this->em->flush();

        $this->audit->log(AuditLog::TWO_FA_DISABLED);
        $this->addFlash('success', 'Two-factor authentication has been disabled.');

        return $this->redirectToRoute('app_security_settings');
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

    /**
     * Stops the request when the hidden "_token" field is not the token of this session.
     *
     * @param string $id name of the token (one per action)
     */
    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY: CSRF check on every state-changing action (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Reads the "code" field: spaces are removed, and only exactly 6 digits are accepted.
     *
     * @return string|null the code, or null when the format is wrong
     */
    private function readCode(Request $request): ?string
    {
        $code = preg_replace('/\s+/', '', $request->request->getString('code'));

        // SECURITY: strict format check before the value reaches the TOTP library (rule 15).
        return 1 === preg_match('/^\d{6}$/', (string) $code) ? (string) $code : null;
    }

    /**
     * Uses one attempt of the limiter for this account.
     *
     * @return bool true when the attempt is allowed, false when the limit is reached (a message is shown)
     */
    private function consumeLimiter(RateLimiterFactoryInterface $factory, User $user): bool
    {
        // SECURITY: per-account limit on code checks in the settings page too (rule 6).
        if ($factory->create('2fa_settings_'.$user->getId())->consume()->isAccepted()) {
            return true;
        }

        $this->addFlash('error', 'Too many attempts. Please wait a few minutes.');

        return false;
    }
}
