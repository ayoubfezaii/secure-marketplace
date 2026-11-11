<?php

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
    #[Route('', name: 'admin_applications', methods: ['GET'])]
    public function index(Request $request, SellerApplicationRepository $applications): Response
    {
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist.
        $status = in_array($status, SellerApplication::STATUSES, true) ? $status : null;
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);

        return $this->render('admin/applications/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($applications->createAdminQueryBuilder($status, $q), $request, 15),
            'status' => $status,
            'q' => $q,
            'params' => array_filter(['status' => $status, 'q' => $q]),
            'pendingCount' => $applications->countPending(),
        ]);
    }

    #[Route('/{uuid}', name: 'admin_application_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] SellerApplication $application): Response
    {
        $this->denyAccessUnlessGranted(SellerApplicationVoter::VIEW, $application);

        return $this->render('admin/applications/show.html.twig', ['application' => $application]);
    }

    #[Route('/{uuid}/approve', name: 'admin_application_approve', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function approve(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] SellerApplication $application, AccountManager $accounts): Response
    {
        $this->assertCsrf($request, 'application-approve-'.$application->getUuid());
        // SECURITY (rule 4): admin, pending only, never your own.
        $this->denyAccessUnlessGranted(SellerApplicationVoter::REVIEW, $application);
        if (!$application->getApplicant()->isBuyer()) {
            $this->addFlash('error', 'This account is no longer a buyer account.');

            return $this->redirectToRoute('admin_application_show', ['uuid' => $application->getUuid()]);
        }

        // Approve + switch to seller + create the shop, in ONE transaction, audit-logged.
        $accounts->approveApplication($application, $this->admin());
        $this->addFlash('success', sprintf('Approved. %s is now a seller and "%s" is open.', $application->getApplicant()->getEmail(), $application->getShopName()));

        return $this->redirectToRoute('admin_applications');
    }

    #[Route('/{uuid}/reject', name: 'admin_application_reject', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function reject(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] SellerApplication $application, AccountManager $accounts): Response
    {
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

    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function admin(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
