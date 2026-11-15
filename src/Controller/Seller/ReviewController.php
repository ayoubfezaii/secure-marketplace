<?php

/**
 * FILE: src/Controller/Seller/ReviewController.php
 * WHAT: controller of the seller's "Reviews" page: read the reviews of own products and answer them.
 * ROLE IN THE PROJECT: seller side of the review feature; the reply is public text, so it is validated and escaped.
 * USED BY: sellers only (ROLE_SELLER). URLs start with /seller/reviews.
 */

namespace App\Controller\Seller;

use App\Entity\AuditLog;
use App\Entity\Review;
use App\Entity\ReviewReply;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Security\Voter\ReviewVoter;
use App\Service\AuditLogger;
use App\Service\Pagination;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reviews on the seller's products + one public reply per review.
 */
#[IsGranted('ROLE_SELLER')]
#[Route('/seller/reviews')]
class ReviewController extends AbstractController
{
    /**
     * List of the reviews left on the seller's products, with filters (stars, replied or not). Seller only.
     *
     * @return Response the rendered template seller/reviews.html.twig
     */
    #[Route('', name: 'seller_reviews', methods: ['GET'])]
    public function index(Request $request, ReviewRepository $reviews): Response
    {
        $seller = $this->getUser();
        \assert($seller instanceof User);

        // SECURITY (rule 15): filters are whitelisted.
        // ?rating= must be a whole number from 1 to 5; ?replied= must be "yes" or "no". Anything else = no filter.
        $rating = filter_var($request->query->getString('rating'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $rating = false === $rating ? null : $rating;
        $replied = in_array($request->query->getString('replied'), ['yes', 'no'], true) ? $request->query->getString('replied') : null;

        // SECURITY (rule 4): only reviews of MY products.
        // SECURITY: SQL injection - QueryBuilder with bound parameters (:me, :rating).
        $qb = $reviews->createQueryBuilder('r')
            ->innerJoin('r.product', 'p')->addSelect('p')
            ->innerJoin('r.author', 'a')->addSelect('a')
            ->leftJoin('r.reply', 'rep')->addSelect('rep')
            ->andWhere('p.seller = :me')->setParameter('me', $seller)
            ->orderBy('r.createdAt', 'DESC');
        if (null !== $rating) {
            $qb->andWhere('r.rating = :rating')->setParameter('rating', $rating);
        }
        if ('yes' === $replied) {
            $qb->andWhere('rep.id IS NOT NULL');
        } elseif ('no' === $replied) {
            $qb->andWhere('rep.id IS NULL');
        }

        return $this->render('seller/reviews.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 10),
            'rating' => $rating,
            'replied' => $replied,
            'params' => array_filter(['rating' => $rating, 'replied' => $replied]),
        ]);
    }

    /**
     * Saves the seller's reply to a review. POST only. Seller only, owner of the reviewed product, once per review.
     *
     * @param Review             $review    loaded by the UUID of the URL
     * @param ValidatorInterface $validator Symfony Validator, checks the reply text (2 to 1000 characters)
     * @param AuditLogger        $audit     writes into the audit log
     */
    #[Route('/{uuid}/reply', name: 'seller_review_reply', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function reply(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Review $review,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        AuditLogger $audit,
    ): Response {
        // SECURITY (rule 3): POST + per-review CSRF token.
        if (!$this->isCsrfTokenValid('review-reply-'.$review->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // SECURITY (rule 4): only the seller of the reviewed product, only once.
        $this->denyAccessUnlessGranted(ReviewVoter::REPLY, $review);
        $seller = $this->getUser();
        \assert($seller instanceof User);

        // SECURITY (rule 9): author and review come from the session / URL; the body is the only input.
        $reply = (new ReviewReply($review, $seller))->setBody($request->request->getString('body'));
        // Validate the constraints of ReviewReply (not blank, length).
        $errors = $validator->validate($reply);
        if (count($errors) > 0) {
            $this->addFlash('error', (string) $errors[0]->getMessage());

            return $this->redirectToRoute('seller_reviews');
        }

        // Link the reply to the review and save.
        $review->setReply($reply);
        $em->persist($reply);
        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submits: the unique index keeps one reply only.
            $this->addFlash('error', 'This review already has a reply.');

            return $this->redirectToRoute('seller_reviews');
        }

        $audit->log(AuditLog::REVIEW_REPLIED, ['review' => (string) $review->getUuid(), 'product' => (string) $review->getProduct()->getUuid()]);
        $this->addFlash('success', 'Your reply is now public on the product page.');

        return $this->redirectToRoute('seller_reviews');
    }
}
