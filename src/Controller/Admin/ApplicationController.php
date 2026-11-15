<?php

/**
 * FILE: src/Controller/Admin/ApplicationController.php
 * WHAT: controller of the admin pages for seller applications: list, detail, approve, reject.
 * ROLE IN THE PROJECT: the only place where a buyer can become a seller. A role change always needs an admin.
 * USED BY: admins only (ROLE_ADMIN). URLs start with /admin/applications.
 */

namespace App\Controller\Admin;

use App\Entity\SellerApplication;
use App\Entity\User;
use App\Repository\SellerApplicationRepository;
use App\Security\Voter\SellerApplicationVoter;
use App\Service\AccountManager;
use App\Service\Pagination;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Seller applications: list (pending first), details, approve / reject (reason required).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/applications')]
class ApplicationController extends AbstractController
{
    /**
     * List of applications (pending first) with a status filter and a search. Admin only.
     *
     * @return Response the rendered template admin/applications/index.html.twig
     */
    #[Route('', name: 'admin_applications', methods: ['GET'])]
    public function index(Request $request, SellerApplicationRepository $applications): Response
    {
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist.
        $status = in_array($status, SellerApplication::STATUSES, true) ? $status : null;
        // Search text: trimmed and limited to 100 characters. It is used as a bound parameter in the repository.
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);

        return $this->render('admin/applications/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($applications->createAdminQueryBuilder($status, $q), $request, 15),
            'status' => $status,
            'q' => $q,
            'params' => array_filter(['status' => $status, 'q' => $q]),
            'pendingCount' => $applications->countPending(),
        ]);
    }

    /**
     * Detail of one application. Admin only.
     *
     * @param SellerApplication $application loaded by the UUID of the URL (404 if unknown)
     */
    #[Route('/{uuid}', name: 'admin_application_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] SellerApplication $application): Response
    {
        $this->denyAccessUnlessGranted(SellerApplicationVoter::VIEW, $application);

        return $this->render('admin/applications/show.html.twig', ['application' => $application]);
    }

    /**
     * Approves an application: the buyer becomes a seller and gets a shop. POST only. Admin only.
     *
     * @param AccountManager $accounts service that does the role change, the shop creation and the audit log
     */
    #[Route('/{uuid}/approve', name: 'admin_application_approve', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function approve(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] SellerApplication $application, AccountManager $accounts): Response
    {
        // SECURITY: CSRF - token checked first.
        $this->assertCsrf($request, 'application-approve-'.$application->getUuid());
        // SECURITY (rule 4): admin, pending only, never your own.
        $this->denyAccessUnlessGranted(SellerApplicationVoter::REVIEW, $application);
        // The account may have changed since it applied (for example already a seller).
        if (!$application->getApplicant()->isBuyer()) {
            $this->addFlash('error', 'This account is no longer a buyer account.');

            return $this->redirectToRoute('admin_application_show', ['uuid' => $application->getUuid()]);
        }

        // Approve + switch to seller + create the shop, in ONE transaction, audit-logged.
        $accounts->approveApplication($application, $this->admin());
        $this->addFlash('success', sprintf('Approved. %s is now a seller and "%s" is open.', $application->getApplicant()->getEmail(), $application->getShopName()));

        return $this->redirectToRoute('admin_applications');
    }

    /**
     * Rejects an application with a reason. POST only. Admin only.
     */
    #[Route('/{uuid}/reject', name: 'admin_application_reject', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function reject(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] SellerApplication $application, AccountManager $accounts): Response
    {
        // SECURITY: CSRF then access control - the token, then the voter (admin, pending, not own application).
        $this->assertCsrf($request, 'application-reject-'.$application->getUuid());
        $this->denyAccessUnlessGranted(SellerApplicationVoter::REVIEW, $application);

        // SECURITY (rule 15): a reason is required (shown to the applicant, auto-escaped), max 500 characters.
        $reason = trim($request->request->getString('reason'));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            $this->addFlash('error', 'Please give a reason (5 to 500 characters). It will be shown to the applicant.');

            return $this->redirectToRoute('admin_application_show', ['uuid' => $application->getUuid()]);
        }

        $accounts->rejectApplication($application, $this->admin(), $reason);
        $this->addFlash('success', 'Application rejected. The applicant can see your reason and apply again.');

        return $this->redirectToRoute('admin_applications');
    }

    /**
     * Stops the request when the hidden "_token" field is not the token of this session.
     *
     * @param string $id name of the token (one per action and per application)
     */
    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** Returns the logged-in admin as a User object. */
    private function admin(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
