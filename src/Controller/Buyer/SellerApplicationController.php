<?php

/**
 * FILE: src/Controller/Buyer/SellerApplicationController.php
 * WHAT: controller of the "Become a seller" page.
 * ROLE IN THE PROJECT: a buyer can only ASK to become a seller; the role change itself is done by an admin.
 *   This is the defence against privilege escalation.
 * USED BY: buyers only (ROLE_BUYER).
 */

namespace App\Controller\Buyer;

use App\Entity\AuditLog;
use App\Entity\SellerApplication;
use App\Entity\User;
use App\Form\SellerApplicationFormType;
use App\Repository\SellerApplicationRepository;
use App\Security\Voter\SellerApplicationVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Become a seller": application form + status of the latest application. Approval (by an admin) switches the
 * account to ROLE_SELLER and creates the shop (AccountManager).
 */
#[IsGranted('ROLE_BUYER')]
class SellerApplicationController extends AbstractController
{
    /**
     * Shows the status of the last application and, when allowed, the application form. Buyer only.
     *
     * @return Response the rendered template buyer/seller_application.html.twig
     */
    #[Route('/buyer/become-a-seller', name: 'buyer_seller_application', methods: ['GET', 'POST'])]
    public function index(Request $request, SellerApplicationRepository $applications, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $buyer = $this->getUser();
        \assert($buyer instanceof User);
        // The most recent application of this buyer (to display "pending", "approved" or "rejected").
        $latest = $applications->findLatestFor($buyer);

        $form = null;
        // SECURITY (rule 4): one pending application at a time (SellerApplicationVoter::APPLY).
        if ($this->isGranted(SellerApplicationVoter::APPLY)) {
            // SECURITY (rule 9): the applicant comes from the session.
            $application = new SellerApplication($buyer);
            // The form has only two fields: shop name and description (see SellerApplicationFormType).
            $form = $this->createForm(SellerApplicationFormType::class, $application);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $em->persist($application);
                $em->flush();
                $audit->log(AuditLog::APPLICATION_SUBMITTED, ['application' => (string) $application->getUuid(), 'shop' => $application->getShopName()]);
                $this->addFlash('success', 'Thank you! Your application has been sent. We usually answer within 2 business days.');

                return $this->redirectToRoute('buyer_seller_application');
            }
        // A POST while an application is already pending is refused.
        } elseif ($request->isMethod('POST')) {
            throw $this->createAccessDeniedException('You already have a pending application.');
        }

        return $this->render('buyer/seller_application.html.twig', [
            'latest' => $latest,
            'form' => $form,
        ], new Response(null, null !== $form && $form->isSubmitted() ? 422 : 200));
    }
}
