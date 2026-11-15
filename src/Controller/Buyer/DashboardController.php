<?php

/**
 * FILE: src/Controller/Buyer/DashboardController.php
 * WHAT: controller of the buyer dashboard (/buyer).
 * ROLE IN THE PROJECT: home page of a buyer after login: numbers, recent orders, products to review, suggestions.
 * USED BY: buyers only (ROLE_BUYER).
 */

namespace App\Controller\Buyer;

use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control in security.yaml.
#[IsGranted('ROLE_BUYER')]
class DashboardController extends AbstractController
{
    /**
     * Buyer dashboard page. GET only. Buyer only.
     *
     * @param DashboardStats $stats service that computes the numbers shown in the cards
     *
     * @return Response the rendered template buyer/dashboard.html.twig
     */
    #[Route('/buyer', name: 'buyer_dashboard', methods: ['GET'])]
    public function index(DashboardStats $stats, OrderRepository $orders, ReviewRepository $reviews, ProductRepository $products): Response
    {
        // The logged-in buyer.
        $buyer = $this->getUser();
        \assert($buyer instanceof User);

        // Up to 4 products the buyer bought but did not review yet.
        $toReview = $reviews->findProductsToReview($buyer, 4);

        // SECURITY: every query is scoped to the logged-in buyer.
        return $this->render('buyer/dashboard.html.twig', [
            'stats' => $stats->forBuyer($buyer),
            'recentOrders' => $orders->findForBuyer($buyer, 5),
            'toReview' => $products->preloadImages($toReview),
            'recommended' => $stats->recommendedFor($buyer, 4),
        ]);
    }
}
