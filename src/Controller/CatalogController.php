<?php

namespace App\Controller;

use App\Dto\ProductSearchCriteria;
use App\Entity\Product;
use App\Form\ReviewFormType;
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

class CatalogController extends AbstractController
{
    #[Route('/products', name: 'product_index', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): Response
    {
        // SECURITY (rules 1, 15): raw query-string values never reach the query; they are normalised first.
        $criteria = ProductSearchCriteria::fromRequest($request);
        $results = $products->search($criteria);
        $total = count($results);

        return $this->render('catalog/index.html.twig', [
            'criteria' => $criteria,
            'products' => $results,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / ProductSearchCriteria::PER_PAGE)),
            'categories' => Product::CATEGORIES,
            'sorts' => ProductSearchCriteria::SORT_LABELS,
        ]);
    }

    #[Route('/products/{uuid}', name: 'product_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product, ReviewRepository $reviews): Response
    {
        // SECURITY (rule 4): hidden products are visible only to their owner and admins.
        // 404 rather than 403, so their existence isn't revealed.
        if (!$this->isGranted(ProductVoter::VIEW, $product)) {
            throw $this->createNotFoundException();
        }

        $reviewForm = null;
        if ($this->isGranted(ReviewVoter::CREATE, $product)) {
            $reviewForm = $this->createForm(ReviewFormType::class, null, [
                'action' => $this->generateUrl('review_new', ['uuid' => $product->getUuid()]),
            ]);
        }

        return $this->render('catalog/show.html.twig', [
            'product' => $product,
            'reviews' => $reviews->findForProduct($product),
            'rating' => $reviews->getRatingSummary($product),
            'reviewForm' => $reviewForm,
        ]);
    }
}
