<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Form\ReviewFormType;
use App\Security\Voter\ReviewVoter;
use App\Service\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ReviewController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

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
        $form = $this->createForm(ReviewFormType::class, $review);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }

            return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid(), '_fragment' => 'reviews']);
        }

        $this->em->persist($review);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submits: the DB unique index stops the second one.
            $this->addFlash('error', 'You have already reviewed this product.');

            return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid()]);
        }

        $this->audit->log(AuditLog::REVIEW_POSTED, ['product' => (string) $product->getUuid(), 'review' => (string) $review->getUuid(), 'rating' => $review->getRating()]);
        $this->addFlash('success', 'Thank you for your review!');

        return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid(), '_fragment' => 'reviews']);
    }

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

        $product = $review->getProduct();
        $me = $this->getUser();
        $isModeration = !$me instanceof User || $review->getAuthor()->getId() !== $me->getId();
        $this->audit->log(AuditLog::REVIEW_DELETED, [
            'review' => (string) $review->getUuid(),
            'product' => (string) $product->getUuid(),
            'author' => $review->getAuthor()->getEmail(),
            'moderation' => $isModeration,
        ]);

        $this->em->remove($review);
        $this->em->flush();
        $this->addFlash('success', 'Review deleted.');

        // SECURITY (rule 12): fixed internal destinations only.
        if ('admin' === $request->request->getString('back') && $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_reviews');
        }

        return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid(), '_fragment' => 'reviews']);
    }
}
