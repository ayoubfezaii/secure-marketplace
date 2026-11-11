<?php

namespace App\Controller;

use App\Dto\ProductSearchCriteria;
use App\Entity\Review;
use App\Repository\ProductRepository;
use App\Repository\ShopRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public seller shop page: /shop/{slug}.
 */
class ShopController extends AbstractController
{
    // SECURITY (rule 15): the slug must match the strict slug format in the route itself.
    #[Route('/shop/{slug}', name: 'shop_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function show(string $slug, Request $request, ShopRepository $shops, ProductRepository $products, EntityManagerInterface $em): Response
    {
        $shop = $shops->findOneBySlug($slug);
        // SECURITY: a shop whose owner is no longer a seller (or is blocked) is not public any more.
        if (null === $shop || !$shop->getOwner()->isSeller() || $shop->getOwner()->isBlocked()) {
            throw $this->createNotFoundException();
        }

        $criteria = ProductSearchCriteria::fromRequest($request)->withCategory(null);
        $results = $products->search($criteria, null, $shop->getOwner());
        $total = count($results);

        $rating = $em->createQueryBuilder()
            ->select('AVG(r.rating) AS avg, COUNT(r.id) AS cnt')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')
            ->where('p.seller = :seller')->setParameter('seller', $shop->getOwner())
            ->getQuery()->getSingleResult();

        return $this->render('shop/show.html.twig', [
            'shop' => $shop,
            'products' => $results,
            'total' => $total,
            'criteria' => $criteria,
            'pages' => max(1, (int) ceil($total / ProductSearchCriteria::PER_PAGE)),
            'sorts' => ProductSearchCriteria::SORT_LABELS,
            'rating' => ['avg' => null !== $rating['avg'] ? round((float) $rating['avg'], 1) : null, 'count' => (int) $rating['cnt']],
        ]);
    }
}
