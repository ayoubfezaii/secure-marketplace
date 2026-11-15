<?php

/**
 * FILE: src/Controller/CatalogController.php
 * WHAT: controller of the catalogue: product list with search / filters, and the product detail page.
 * ROLE IN THE PROJECT: main public pages of the shop. Good file to show the SQL injection and IDOR defences.
 * USED BY: everyone (visitor, buyer, seller, admin). No login needed.
 */

namespace App\Controller;

use App\Dto\ProductSearchCriteria;
use App\Entity\Product;
use App\Form\ReviewFormType;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Security\Voter\ProductVoter;
use App\Security\Voter\ReviewVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/** Public catalogue pages (read-only: both routes are GET). */
class CatalogController extends AbstractController
{
    /**
     * Product list with search, filters, sorting and pagination. Reachable by everyone.
     *
     * @param Request $request the HTTP request (filters come from the query string: ?q=...&sort=...)
     *
     * @return Response the rendered template catalog/index.html.twig
     */
    #[Route('/products', name: 'product_index', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products, CategoryRepository $categories): Response
    {
        // SECURITY (rules 1, 15): raw query-string values never reach the query; they are normalised first.
        $criteria = ProductSearchCriteria::fromRequest($request);
        // SECURITY: the slug is resolved to an existing category (bound parameter); unknown = no filter.
        $category = null !== $criteria->category ? $categories->findOneBySlug($criteria->category) : null;
        // The category in the URL does not exist: remove it from the criteria (no filter).
        if (null === $category && null !== $criteria->category) {
            $criteria = $criteria->withCategory(null);
        }

        // Run the search (one page of results) and count the total number of matches.
        $results = $products->search($criteria, $category);
        $total = count($results);

        return $this->render('catalog/index.html.twig', [
            'criteria' => $criteria,
            'category' => $category,
            'products' => $results,
            'total' => $total,
            // Number of pages, at least 1.
            'pages' => max(1, (int) ceil($total / ProductSearchCriteria::PER_PAGE)),
            'categories' => $categories->findAllOrdered(),
            'categoryCounts' => $categories->countProducts(true),
            'priceBounds' => $products->priceBounds(),
            'sorts' => ProductSearchCriteria::SORT_LABELS,
            'ratings' => ProductSearchCriteria::RATINGS,
        ]);
    }

    /**
     * Product detail page. Reachable by everyone for an active product.
     * SECURITY: IDOR - the URL contains a random UUID v4 (checked by the route "requirements"), not a number that
     * could be increased to walk through all products. #[MapEntity] loads the product by this UUID (404 if unknown).
     *
     * @return Response the rendered template catalog/show.html.twig
     */
    #[Route('/products/{uuid}', name: 'product_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product, ReviewRepository $reviews, ProductRepository $products): Response
    {
        // SECURITY (rule 4): hidden products are visible only to their owner and admins.
        // 404 rather than 403, so their existence isn't revealed.
        if (!$this->isGranted(ProductVoter::VIEW, $product)) {
            throw $this->createNotFoundException();
        }

        // The review form is created only for a buyer who is allowed to review (the voter decides).
        // The form posts to ReviewController::new, where the permission is checked AGAIN.
        $reviewForm = null;
        if ($this->isGranted(ReviewVoter::CREATE, $product)) {
            $reviewForm = $this->createForm(ReviewFormType::class, null, [
                'action' => $this->generateUrl('review_new', ['uuid' => $product->getUuid()]),
            ]);
        }

        // Everything the page needs: the product, its reviews, the rating numbers and 4 similar products.
        return $this->render('catalog/show.html.twig', [
            'product' => $product,
            'reviews' => $reviews->findForProduct($product),
            'rating' => $reviews->getRatingSummary($product),
            'distribution' => $reviews->getDistribution($product),
            'reviewForm' => $reviewForm,
            'similar' => $products->findSimilar($product, 4),
        ]);
    }
}
