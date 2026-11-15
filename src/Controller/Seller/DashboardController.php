<?php

/**
 * FILE: src/Controller/Seller/DashboardController.php
 * WHAT: controller of the seller dashboard (/seller) and of the analytics page (/seller/analytics).
 * ROLE IN THE PROJECT: home page of a seller after login: sales numbers, chart, items to ship, low stock.
 * USED BY: sellers only (ROLE_SELLER).
 */

namespace App\Controller\Seller;

use App\Service\Money;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\DashboardStats;
use App\Service\SellerAnalytics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control in security.yaml.
#[IsGranted('ROLE_SELLER')]
class DashboardController extends AbstractController
{
    /**
     * Seller dashboard page. GET only. Seller only.
     *
     * @param SellerAnalytics   $analytics service that computes sales figures
     * @param DashboardStats    $stats     service used here for the latest reviews
     * @param ProductRepository $products  to find products with low stock
     *
     * @return Response the rendered template seller/dashboard.html.twig
     */
    #[Route('/seller', name: 'seller_dashboard', methods: ['GET'])]
    public function index(SellerAnalytics $analytics, DashboardStats $stats, ProductRepository $products): Response
    {
        // The logged-in seller. SECURITY: IDOR - all figures are computed for THIS user, taken from the session.
        $seller = $this->getUser();
        \assert($seller instanceof User);

        // SECURITY: everything is scoped to the logged-in seller (never a seller id from the request).
        return $this->render('seller/dashboard.html.twig', [
            'overview' => $analytics->overview($seller),
            // Passed to the chart as JSON in a data-* attribute (escaped), see assets/controllers/chart_controller.js.
            'chart' => $analytics->salesByDay($seller, 30),
            'topProducts' => $analytics->topProducts($seller, 30, 5),
            'lowStock' => $products->findLowStock($seller),
            'toShip' => $analytics->itemsToShip($seller, 6),
            'latestReviews' => $stats->latestReviewsForSeller($seller, 4),
            'shop' => $seller->getShop(),
        ]);
    }

    /**
     * Analytics page: totals, chart and best sellers for 7 / 30 / 90 days (the allowed ranges). Seller only.
     *
     * @return Response the rendered template seller/analytics.html.twig
     */
    #[Route('/seller/analytics', name: 'seller_analytics', methods: ['GET'])]
    public function analytics(\Symfony\Component\HttpFoundation\Request $request, SellerAnalytics $analytics): Response
    {
        $seller = $this->getUser();
        \assert($seller instanceof User);

        // SECURITY (rule 15): the range is one of the whitelisted presets; anything else = 30 days.
        $range = $request->query->getString('range', '30d');
        $range = array_key_exists($range, SellerAnalytics::RANGES) ? $range : '30d';
        $days = SellerAnalytics::RANGES[$range];

        // Period shown: from (today - days + 1) to tomorrow 00:00, so today is included.
        $to = new \DateTimeImmutable('tomorrow');
        $from = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));
        $totals = $analytics->totals($seller, $from, $to);
        // Same length just before, to show the evolution ("+12 % vs previous period").
        $previous = $analytics->totals($seller, $from->modify(sprintf('-%d days', $days)), $from);

        return $this->render('seller/analytics.html.twig', [
            'range' => $range,
            'ranges' => array_keys(SellerAnalytics::RANGES),
            'days' => $days,
            'totals' => $totals,
            'previous' => $previous,
            // Average order value = revenue / number of orders (0 when there is no order).
            'avgOrder' => $totals['orders'] > 0 ? round($totals['revenue'] / $totals['orders'], Money::SCALE) : 0,
            'chart' => $analytics->salesByDay($seller, $days),
            'byCategory' => $analytics->revenueByCategory($seller, $days),
            'bestSellers' => $analytics->topProducts($seller, $days, 10),
        ]);
    }
}
