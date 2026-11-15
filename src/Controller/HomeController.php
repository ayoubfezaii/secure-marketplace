<?php

/**
 * FILE: src/Controller/HomeController.php
 * WHAT: controller of the home page ("/") and the "About" page.
 * ROLE IN THE PROJECT: a controller receives the HTTP request, asks the repositories for data and returns a page.
 * USED BY: everyone (visitor, buyer, seller, admin). No login needed.
 */

namespace App\Controller;

use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public storefront pages. Read-only: every query returns ACTIVE products only and no personal data
 * (reviews are shown with the author's public display name, never the email).
 */
class HomeController extends AbstractController
{
    /**
     * Home page. GET only, so it changes nothing (SECURITY: CSRF - no state change on GET).
     * Symfony injects the four repositories automatically (autowiring).
     *
     * @return Response the rendered template home/index.html.twig
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(ProductRepository $products, CategoryRepository $categories, ReviewRepository $reviews, UserRepository $users): Response
    {
        // Each key below becomes a variable in the Twig template.
        return $this->render('home/index.html.twig', [
            'categories' => $categories->findAllOrdered(),
            'categoryCounts' => $categories->countProducts(true),
            'bestSellers' => $products->findBestSellers(8),
            'newArrivals' => $products->findLatestActive(4),
            'deals' => $products->findDeals(4),
            'latestReviews' => $reviews->findLatestPositive(9),
            'stats' => [
                'customers' => $users->countByRole(User::ROLE_BUYER),
                'products' => $products->countActive(),
                'positive' => $reviews->positiveRate(),
            ],
            // The promo ends next Sunday at midnight (server time); the countdown only displays it.
            'promoEnd' => (new \DateTimeImmutable('next monday 00:00')),
        ]);
    }

    /** Static "About" page. Reachable by everyone. */
    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }
}
