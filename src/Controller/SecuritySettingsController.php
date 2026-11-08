<?php

namespace App\Controller;

use App\Entity\User;
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
    private const PENDING_SECRET_KEY = '_totp_pending_secret';

    public function __construct(
        private readonly TotpAuthenticatorInterface $totp,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_security_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getAppUser();
        $pendingSecret = $request->getSession()->get(self::PENDING_SECRET_KEY);

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
        ]);
    }

    #[Route('/2fa/start', name: 'app_2fa_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->assertCsrf($request, 'totp_start');
        if ($this->getAppUser()->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_security_settings');
        }

        // SECURITY: the candidate secret lives only in the server-side session until the user proves they
        // scanned it correctly. 2FA is never enabled with a secret the user can't use (no self-lockout).
        $request->getSession()->set(self::PENDING_SECRET_KEY, $this->totp->generateSecret());

        return $this->redirectToRoute('app_security_settings');
    }

    #[Route('/2fa/confirm', name: 'app_2fa_confirm', methods: ['POST'])]
    public function confirm(Request $request, RateLimiterFactoryInterface $twoFactorSettingsLimiter): Response
    {
        $this->assertCsrf($request, 'totp_confirm');
        $user = $this->getAppUser();
        $session = $request->getSession();
        $pendingSecret = $session->get(self::PENDING_SECRET_KEY);

        if (!is_string($pendingSecret) || $user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_security_settings');
        }
        if (!$this->consumeLimiter($twoFactorSettingsLimiter, $user)) {
            return $this->redirectToRoute('app_security_settings');
        }

        $code = $this->readCode($request);
        $candidate = clone $user;
        $candidate->setTotpSecret($pendingSecret);

        if (null === $code || !$this->totp->checkCode($candidate, $code)) {
            $this->addFlash('error', 'Invalid code. Check the time on your phone and try again.');

            return $this->redirectToRoute('app_security_settings');
        }

        $user->setTotpSecret($pendingSecret);
        $user->enableTotp();
        $this->em->flush();
        $session->remove(self::PENDING_SECRET_KEY);

        $this->audit->log(AuditLog::TWO_FA_ENABLED);
        $this->addFlash('success', 'Two-factor authentication is now enabled. You will need a code at every login.');

        return $this->redirectToRoute('app_security_settings');
    }

    #[Route('/2fa/cancel', name: 'app_2fa_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        $this->assertCsrf($request, 'totp_cancel');
        $request->getSession()->remove(self::PENDING_SECRET_KEY);

        return $this->redirectToRoute('app_security_settings');
    }

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

        $password = $request->request->getString('current_password');
        $code = $this->readCode($request);

        // SECURITY: re-authentication with BOTH factors. A stolen session (XSS, unlocked computer) is not
        // enough to remove 2FA from the account.
        if (!$passwordHasher->isPasswordValid($user, $password) || null === $code || !$this->totp->checkCode($user, $code)) {
            $this->audit->log(AuditLog::TWO_FA_FAILURE, ['context' => 'disable_rejected']);
            $this->addFlash('error', 'Wrong password or code. Two-factor authentication is still enabled.');

            return $this->redirectToRoute('app_security_settings');
        }

        $user->disableTotp();
        $this->em->flush();

        $this->audit->log(AuditLog::TWO_FA_DISABLED);
        $this->addFlash('success', 'Two-factor authentication has been disabled.');

        return $this->redirectToRoute('app_security_settings');
    }

    private function getAppUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY: CSRF check on every state-changing action (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function readCode(Request $request): ?string
    {
        $code = preg_replace('/\s+/', '', $request->request->getString('code'));

        // SECURITY: strict format check before the value reaches the TOTP library (rule 15).
        return 1 === preg_match('/^\d{6}$/', (string) $code) ? (string) $code : null;
    }

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
