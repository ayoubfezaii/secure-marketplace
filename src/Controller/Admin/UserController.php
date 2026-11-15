<?php

/**
 * FILE: src/Controller/Admin/UserController.php
 * WHAT: controller of the admin "Users" pages: list, detail, lock / unlock, change role, force a password
 *   reset, reset 2FA.
 * ROLE IN THE PROJECT: the most powerful screens of the site, so they are the most protected: every action is
 *   POST + CSRF token + UserManagementVoter + audit log.
 * USED BY: admins only (ROLE_ADMIN). URLs start with /admin/users.
 */

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\SellerApplicationRepository;
use App\Repository\UserRepository;
use App\Security\Voter\UserManagementVoter;
use App\Service\AccountManager;
use App\Service\AuditLogger;
use App\Service\ListQuery;
use App\Service\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * User management. Every action: POST + per-user, per-action CSRF token + UserManagementVoter + audit log
 * (rules 3, 4, 14).
 *
 * SECURITY policy: an admin can only manage BUYER and SELLER accounts and never himself. Admin accounts are managed
 * with console commands; nobody can be promoted to admin from the web. The admin never sees a password hash, a 2FA
 * secret or a full IBAN: none of them is passed to the templates.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
class UserController extends AbstractController
{
    /** Sort whitelist (rule 1). */
    private const SORTS = ['email' => 'u.email', 'created' => 'u.createdAt', 'login' => 'u.lastLoginAt'];
    // Values accepted for the ?role= filter of the list.
    private const ROLE_FILTERS = ['buyer' => User::ROLE_BUYER, 'seller' => User::ROLE_SELLER, 'admin' => User::ROLE_ADMIN];

    /**
     * @param EntityManagerInterface $em    Doctrine, to save
     * @param AuditLogger            $audit writes into the audit log
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * List of users with search, filters (role, locked, 2FA), sorting and pagination. Admin only.
     *
     * @return Response the rendered template admin/users/index.html.twig
     */
    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        // Search text and sort (sort column taken from the SORTS whitelist).
        $list = ListQuery::fromRequest($request, self::SORTS, 'created');
        // SECURITY (rule 15): whitelisted filters.
        $role = $request->query->getString('role');
        $role = array_key_exists($role, self::ROLE_FILTERS) ? $role : null;
        $status = in_array($request->query->getString('status'), ['active', 'locked'], true) ? $request->query->getString('status') : null;
        $twofa = in_array($request->query->getString('twofa'), ['on', 'off'], true) ? $request->query->getString('twofa') : null;

        // Base query from the repository, then the optional filters (all bound parameters).
        $qb = $users->createAdminQueryBuilder($list->q, null !== $role ? self::ROLE_FILTERS[$role] : null);
        if ('locked' === $status) {
            $qb->andWhere('(u.blocked = true OR u.lockedUntil > :now)')->setParameter('now', new \DateTimeImmutable());
        } elseif ('active' === $status) {
            $qb->andWhere('u.blocked = false AND (u.lockedUntil IS NULL OR u.lockedUntil <= :now)')->setParameter('now', new \DateTimeImmutable());
        }
        if (null !== $twofa) {
            $qb->andWhere('u.totpEnabled = :tfa')->setParameter('tfa', 'on' === $twofa);
        }
        $list->applySort($qb)->addOrderBy('u.id', 'DESC');

        $filters = array_filter(['role' => $role, 'status' => $status, 'twofa' => $twofa, 'q' => $list->q]);

        return $this->render('admin/users/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 20),
            'list' => $list,
            'role' => $role,
            'status' => $status,
            'twofa' => $twofa,
            'filters' => $filters,
            'params' => $filters + $list->params(),
        ]);
    }

    /**
     * Detail page of one account: orders, products, login history, seller application. Admin only.
     *
     * @param User $target the account shown, loaded by the UUID of the URL
     */
    #[Route('/{uuid}', name: 'admin_user_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(
        // Named $target (not $user): a controller argument called $user is reserved for the current user.
        #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target,
        OrderRepository $orders,
        ProductRepository $products,
        AuditLogRepository $logs,
        SellerApplicationRepository $applications,
    ): Response {
        $this->denyAccessUnlessGranted(UserManagementVoter::VIEW, $target);

        return $this->render('admin/users/show.html.twig', [
            'user' => $target,
            // Tells the template whether to show the action buttons (false for myself and for other admins).
            'canManage' => $this->isGranted(UserManagementVoter::MANAGE, $target),
            'orders' => $target->isBuyer() ? $orders->findForBuyer($target, 10) : [],
            'products' => $target->isSeller() ? $products->findBySeller($target) : [],
            'loginHistory' => $logs->findLoginHistory($target, 20),
            'application' => $applications->findLatestFor($target),
        ]);
    }

    /**
     * Locks an account (login refused, sessions ended). POST only. Admin only, not on self, not on an admin.
     */
    #[Route('/{uuid}/block', name: 'admin_user_block', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function block(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target): Response
    {
        // SECURITY: CSRF + access control - guard() checks the token and UserManagementVoter::MANAGE.
        $this->guard($request, $target, 'block');
        $target->block();
        $this->em->flush();
        $this->audit->log(AuditLog::USER_LOCKED, ['target' => $target->getEmail()]);
        $this->addFlash('success', sprintf('%s is locked. Their open sessions end on their next request.', $target->getEmail()));

        return $this->back($request, $target);
    }

    /**
     * Unlocks an account (also clears a brute-force lockout). POST only. Admin only.
     */
    #[Route('/{uuid}/unblock', name: 'admin_user_unblock', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function unblock(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target): Response
    {
        $this->guard($request, $target, 'unblock');
        $target->unblock();
        $this->em->flush();
        $this->audit->log(AuditLog::USER_UNLOCKED, ['target' => $target->getEmail()]);
        $this->addFlash('success', sprintf('%s is unlocked.', $target->getEmail()));

        return $this->back($request, $target);
    }

    /**
     * Switches an account between buyer and seller. POST only. Admin only, not on self, not on an admin.
     *
     * @param AccountManager $accounts service that changes the role safely
     */
    #[Route('/{uuid}/role', name: 'admin_user_role', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function changeRole(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target, AccountManager $accounts): Response
    {
        $this->guard($request, $target, 'role');

        // SECURITY (rule 9): fixed whitelist. "admin" (or anything else) is refused.
        $key = $request->request->getString('role');
        if (!in_array($key, ['buyer', 'seller'], true)) {
            throw $this->createAccessDeniedException('Invalid role.');
        }

        // SECURITY: AccountManager keeps roles exclusive (creates the shop / hides the products) and audit-logs the
        // change. Symfony notices the role change on the target's next request and logs them out.
        $accounts->switchRole($target, $key);
        $this->addFlash('success', sprintf('%s is now a %s.', $target->getEmail(), $key));

        return $this->back($request, $target);
    }

    /**
     * Forces a password reset: the current password stops working immediately (all sessions end) and a single-use
     * reset link is e-mailed to the account owner. The admin never chooses or sees a password.
     *
     * POST only. Admin only, not on self, not on an admin.
     */
    #[Route('/{uuid}/force-reset', name: 'admin_user_force_reset', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function forceReset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target,
        UserPasswordHasherInterface $hasher,
        ResetPasswordHelperInterface $resetHelper,
        MailerInterface $mailer,
        #[Autowire('%app.mailer_from%')] string $mailerFrom,
    ): Response {
        $this->guard($request, $target, 'force-reset');

        // SECURITY: replace the hash by the hash of a random secret nobody knows: the old password (maybe leaked)
        // no longer works and every existing session is invalidated (User::__serialize compares the hash).
        $target->setPassword($hasher->hashPassword($target, bin2hex(random_bytes(32))));
        $this->em->flush();

        // Try to email a reset link to the owner of the account.
        $sent = false;
        try {
            $token = $resetHelper->generateResetToken($target);
            $mailer->send((new TemplatedEmail())
                ->from(new Address($mailerFrom, 'Maison Secure'))
                ->to($target->getEmail())
                ->subject('Please choose a new password')
                ->htmlTemplate('reset_password/email.html.twig')
                ->context(['resetToken' => $token]));
            $sent = true;
        } catch (ResetPasswordExceptionInterface|TransportExceptionInterface) {
            // A recent request is still valid (throttle) or the mailer is down: the user can use "Forgot password".
        }

        // SECURITY: logging - the forced reset is written in the audit log.
        $this->audit->log(AuditLog::USER_PASSWORD_RESET_FORCED, ['target' => $target->getEmail(), 'email_sent' => $sent]);
        $this->addFlash('success', $sent
            ? sprintf('The password of %s was invalidated and a reset link was e-mailed.', $target->getEmail())
            : sprintf('The password of %s was invalidated. They can use "Forgot password" to choose a new one.', $target->getEmail()));

        return $this->back($request, $target);
    }

    /**
     * Removes the 2FA of an account. POST only. Admin only, not on self, not on an admin.
     */
    #[Route('/{uuid}/reset-2fa', name: 'admin_user_reset_2fa', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function reset2fa(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target): Response
    {
        $this->guard($request, $target, 'reset-2fa');
        // For a user who lost their phone. The secret is wiped (never displayed); they can enrol again.
        $target->disableTotp();
        $this->em->flush();
        $this->audit->log(AuditLog::USER_2FA_RESET, ['target' => $target->getEmail()]);
        $this->addFlash('success', sprintf('Two-factor authentication was reset for %s.', $target->getEmail()));

        return $this->back($request, $target);
    }

    /**
     * Common protection of every action above: CSRF token, then the voter.
     *
     * @param User   $target the account the admin wants to change
     * @param string $action name of the action (part of the CSRF token name)
     */
    private function guard(Request $request, User $target, string $action): void
    {
        // SECURITY (rule 3): per-user, per-action CSRF token.
        if (!$this->isCsrfTokenValid('admin-user-'.$action.'-'.$target->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // SECURITY (rule 4): UserManagementVoter: never on yourself (no self-lockout / self-demotion), never on
        // another admin.
        $this->denyAccessUnlessGranted(UserManagementVoter::MANAGE, $target);
    }

    /** Where to go after an action: the user list or the detail page of the account. */
    private function back(Request $request, User $target): Response
    {
        // SECURITY (rule 12): fixed internal destinations only.
        return 'list' === $request->request->getString('back')
            ? $this->redirectToRoute('admin_users')
            : $this->redirectToRoute('admin_user_show', ['uuid' => $target->getUuid()]);
    }
}
