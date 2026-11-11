<?php

namespace App\Controller\Buyer;

use App\Entity\AuditLog;
use App\Entity\Review;
use App\Entity\User;
use App\Form\ReviewFormType;
use App\Repository\ReviewRepository;
use App\Security\Voter\ReviewVoter;
use App\Service\AuditLogger;
use App\Service\Pagination;
use App\Service\ProductRatingUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My reviews": list and edit the buyer's own reviews. Deletion goes through ReviewController::delete (shared with
 * admin moderation; ReviewVoter + CSRF + audit).
 */
#[IsGranted('ROLE_BUYER')]
#[Route('/buyer/reviews')]
class ReviewController extends AbstractController
{
    #[Route('', name: 'buyer_reviews', methods: ['GET'])]
    public function index(Request $request, ReviewRepository $reviews): Response
    {
        $buyer = $this->getUser();
        \assert($buyer instanceof User);

        // SECURITY (rule 4): scoped to the author = logged-in buyer.
        $qb = $reviews->createQueryBuilder('r')
            ->innerJoin('r.product', 'p')->addSelect('p')
            ->leftJoin('r.reply', 'rep')->addSelect('rep')
            ->andWhere('r.author = :me')->setParameter('me', $buyer)
            ->orderBy('r.createdAt', 'DESC');

        return $this->render('buyer/reviews/index.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 10),
            'toReview' => $reviews->findProductsToReview($buyer, 6),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'buyer_review_edit', methods: ['GET', 'POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Review $review,
        EntityManagerInterface $em,
        ProductRatingUpdater $ratings,
        AuditLogger $audit,
    ): Response {
        // SECURITY (rule 4, IDOR): only the author can edit.
        $this->denyAccessUnlessGranted(ReviewVoter::EDIT, $review);

        // SECURITY (rule 9): only rating + comment are fields; author/product/date can't be changed.
        $form = $this->createForm(ReviewFormType::class, $review);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $review->markEdited();
            $ratings->refresh($review->getProduct());
            $em->flush();
            $audit->log(AuditLog::REVIEW_UPDATED, ['review' => (string) $review->getUuid(), 'rating' => $review->getRating()]);
            $this->addFlash('success', 'Your review has been updated.');

            return $this->redirectToRoute('buyer_reviews');
        }
        if ($form->isSubmitted()) {
            $em->refresh($review);
        }

        return $this->render('buyer/reviews/edit.html.twig', ['form' => $form, 'review' => $review], new Response(null, $form->isSubmitted() ? 422 : 200));
    }
}
