<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User management. Every action: POST + per-user CSRF token + audit log (rules 3, 14).
 *
 * SECURITY policy: from the web an admin can only manage BUYER and SELLER accounts. Admin accounts (including
 * their own) can't be demoted or blocked here; they are managed with console commands. A single stolen admin
 * session therefore can't lock out or demote the other administrators, and nobody can be promoted to admin here.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
class UserController extends AbstractController
{
    // SECURITY (rule 9): fixed whitelist. ROLE_ADMIN is not reachable.
    private const ASSIGNABLE_ROLES = ['buyer' => User::ROLE_BUYER, 'seller' => User::ROLE_SELLER];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly \App\Service\AccountManager $accounts,
    ) {
    }

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);
        $role = $request->query->getString('role');
        $role = in_array($role, [User::ROLE_BUYER, User::ROLE_SELLER, User::ROLE_ADMIN], true) ? $role : null;

        return $this->render('admin/users.html.twig', [
            'pager' => Pagination::fromQueryBuilder($users->createAdminQueryBuilder($q, $role), $request, 20),
            'params' => array_filter(['q' => $q, 'role' => $role]),
            'q' => $q,
            'role' => $role,
        ]);
    }

    #[Route('/{uuid}/block', name: 'admin_user_block', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function block(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target): Response
    {
        $this->guard($request, $target, 'block');
        $target->block();
        $this->em->flush();
        $this->audit->log(AuditLog::USER_LOCKED, ['target' => $target->getEmail()]);
        $this->addFlash('success', sprintf('%s is blocked. Their open sessions end on their next request.', $target->getEmail()));

        return $this->redirectToRoute('admin_users', $this->listParams($request));
    }

    #[Route('/{uuid}/unblock', name: 'admin_user_unblock', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function unblock(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target): Response
    {
        $this->guard($request, $target, 'unblock');
        $target->unblock();
        $this->em->flush();
        $this->audit->log(AuditLog::USER_UNLOCKED, ['target' => $target->getEmail()]);
        $this->addFlash('success', sprintf('%s is unlocked.', $target->getEmail()));

        return $this->redirectToRoute('admin_users', $this->listParams($request));
    }

    #[Route('/{uuid}/role', name: 'admin_user_role', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function changeRole(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] User $target): Response
    {
        $this->guard($request, $target, 'role');

        $key = $request->request->getString('role');
        if (!isset(self::ASSIGNABLE_ROLES[$key])) {
            throw $this->createAccessDeniedException('Invalid role.');
        }

        // SECURITY: AccountManager keeps roles exclusive (creates the shop / hides the products) and audit-logs the
        // change. Symfony notices the role change on the target's next request and logs them out.
        $this->accounts->switchRole($target, $key);
        $this->addFlash('success', sprintf('%s is now a %s.', $target->getEmail(), $key));

        return $this->redirectToRoute('admin_users', $this->listParams($request));
    }

    private function guard(Request $request, User $target, string $action): void
    {
        // SECURITY (rule 3): per-user, per-action CSRF token.
        if (!$this->isCsrfTokenValid('admin-user-'.$action.'-'.$target->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // SECURITY: UserManagementVoter: never on yourself (no self-lockout / self-demotion), never on another admin.
        $this->denyAccessUnlessGranted(\App\Security\Voter\UserManagementVoter::MANAGE, $target);
    }

    /**
     * @return array<string, string>
     */
    private function listParams(Request $request): array
    {
        // Only re-use the (re-validated) list filters, never an arbitrary return URL (rule 12).
        $role = $request->request->getString('return_role');

        return array_filter([
            'q' => mb_substr($request->request->getString('return_q'), 0, 100),
            'role' => in_array($role, [User::ROLE_BUYER, User::ROLE_SELLER, User::ROLE_ADMIN], true) ? $role : '',
        ]);
    }
}
