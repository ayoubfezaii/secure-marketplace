<?php

namespace App\Controller\Seller;

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
    #[Route('/seller', name: 'seller_dashboard', methods: ['GET'])]
    public function index(SellerAnalytics $analytics, DashboardStats $stats, ProductRepository $products): Response
    {
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

    #[Route('/seller/analytics', name: 'seller_analytics', methods: ['GET'])]
    public function analytics(\Symfony\Component\HttpFoundation\Request $request, SellerAnalytics $analytics): Response
    {
        $seller = $this->getUser();
        \assert($seller instanceof User);

        // SECURITY (rule 15): the range is one of the whitelisted presets; anything else = 30 days.
        $range = $request->query->getString('range', '30d');
        $range = array_key_exists($range, SellerAnalytics::RANGES) ? $range : '30d';
        $days = SellerAnalytics::RANGES[$range];

        $to = new \DateTimeImmutable('tomorrow');
        $from = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days - 1));
        $totals = $analytics->totals($seller, $from, $to);
        $previous = $analytics->totals($seller, $from->modify(sprintf('-%d days', $days)), $from);

        return $this->render('seller/analytics.html.twig', [
            'range' => $range,
            'ranges' => array_keys(SellerAnalytics::RANGES),
            'days' => $days,
            'totals' => $totals,
            'previous' => $previous,
            'avgOrder' => $totals['orders'] > 0 ? round($totals['revenue'] / $totals['orders'], 2) : 0,
            'chart' => $analytics->salesByDay($seller, $days),
            'byCategory' => $analytics->revenueByCategory($seller, $days),
            'bestSellers' => $analytics->topProducts($seller, $days, 10),
        ]);
    }
}
