<?php

namespace App\Controller\Seller;

use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control in security.yaml.
#[IsGranted('ROLE_SELLER')]
class DashboardController extends AbstractController
{
    #[Route('/seller', name: 'seller_dashboard', methods: ['GET'])]
    public function index(DashboardStats $stats, ProductRepository $products, OrderRepository $orders): Response
    {
        $seller = $this->getUser();
        \assert($seller instanceof User);

        // SECURITY: everything is scoped to the logged-in seller.
        return $this->render('seller/dashboard.html.twig', [
            'stats' => $stats->forSeller($seller),
            // Passed to the chart as JSON in a data-* attribute (escaped), see sales_chart_controller.js.
            'chart' => $stats->sellerSalesByDay($seller, 30),
            'lowStock' => $products->findLowStock($seller),
            'recentOrders' => $orders->findForSeller($seller, 5),
            'products' => $products->findBySeller($seller),
            'latestReviews' => $stats->latestReviewsForSeller($seller),
        ]);
    }
}
