<?php

/**
 * FILE: src/Controller/ReviewController.php
 * WHAT: controller that creates and deletes product reviews.
 * ROLE IN THE PROJECT: user text shown publicly: shows the defences against fake reviews, mass assignment,
 *   CSRF and (with Twig escaping) stored XSS.
 * USED BY: buyers (create, delete own review) and admins (delete any review = moderation).
 */

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Form\ReviewFormType;
use App\Security\Voter\ReviewVoter;
use App\Service\AuditLogger;
use App\Service\ProductRatingUpdater;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Review actions. Both routes are POST (they change data). */
class ReviewController extends AbstractController
{
    /**
     * @param EntityManagerInterface $em      Doctrine, to save / delete
     * @param AuditLogger            $audit   writes into the audit log
     * @param ProductRatingUpdater   $ratings recomputes the average rating of a product
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ProductRatingUpdater $ratings,
    ) {
    }

    /**
     * Saves a new review sent from the product page. Buyer only.
     *
     * @param Request $request the HTTP request (form fields: rating, comment, CSRF token)
     * @param Product $product the reviewed product, loaded by the UUID of the URL
     *
     * @return Response redirect back to the product page
     */
    #[IsGranted('ROLE_BUYER')]
    #[Route('/products/{uuid}/reviews', name: 'review_new', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function new(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY (rule 4): only verified buyers of this product, once. Checked on the server, so hiding the form
        // is not the only protection.
        $this->denyAccessUnlessGranted(ReviewVoter::CREATE, $product);

        $author = $this->getUser();
        \assert($author instanceof User);

        // SECURITY (rule 9): product and author come from the URL entity and the session, not the form.
        $review = new Review($product, $author);
        // Symfony form: fills only rating and comment, checks the CSRF token and validates the entity.
        // SECURITY: CSRF - Symfony forms add and verify a hidden token automatically.
        $form = $this->createForm(ReviewFormType::class, $review);
        $form->handleRequest($request);

        // Invalid form: show the error messages on the product page.
        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }

            return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid(), '_fragment' => 'reviews']);
        }

        // Save the review.
        $this->em->persist($review);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submits: the DB unique index stops the second one.
            $this->addFlash('error', 'You have already reviewed this product.');

            return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid()]);
        }

        // Update the average rating stored on the product, then save again.
        $this->ratings->refresh($product);
        $this->em->flush();

        $this->audit->log(AuditLog::REVIEW_POSTED, ['product' => (string) $product->getUuid(), 'review' => (string) $review->getUuid(), 'rating' => $review->getRating()]);
        $this->addFlash('success', 'Thank you for your review!');

        return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid(), '_fragment' => 'reviews']);
    }

    /**
     * Deletes a review. Its author (buyer) or an admin.
     *
     * @param Review $review the review, loaded by the UUID of the URL
     *
     * @return Response redirect to the page the user came from
     */
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/reviews/{uuid}/delete', name: 'review_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function delete(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Review $review): Response
    {
        // SECURITY (rule 4): author or admin only.
        $this->denyAccessUnlessGranted(ReviewVoter::DELETE, $review);
        // SECURITY (rule 3): POST + per-review CSRF token.
        if (!$this->isCsrfTokenValid('delete-review-'.$review->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Keep what the audit log needs before the review is deleted.
        $product = $review->getProduct();
        $me = $this->getUser();
        // True when the review is deleted by someone who is not its author (= admin moderation).
        $isModeration = !$me instanceof User || $review->getAuthor()->getId() !== $me->getId();
        $this->audit->log(AuditLog::REVIEW_DELETED, [
            'review' => (string) $review->getUuid(),
            'product' => (string) $product->getUuid(),
            'author' => $review->getAuthor()->getEmail(),
            'moderation' => $isModeration,
        ]);

        // Delete, then recompute the rating of the product.
        $this->em->remove($review);
        $this->em->flush();
        $this->ratings->refresh($product);
        $this->em->flush();
        $this->addFlash('success', 'Review deleted.');

        // SECURITY (rule 12): fixed internal destinations only.
        if ('admin' === $request->request->getString('back') && $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_reviews');
        }
        if ('buyer' === $request->request->getString('back') && $this->isGranted('ROLE_BUYER')) {
            return $this->redirectToRoute('buyer_reviews');
        }

        return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid(), '_fragment' => 'reviews']);
    }
}
