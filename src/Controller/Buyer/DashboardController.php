<?php

namespace App\Controller\Buyer;

use App\Entity\User;
use App\Repository\OrderRepository;
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
    #[Route('/buyer', name: 'buyer_dashboard', methods: ['GET'])]
    public function index(DashboardStats $stats, OrderRepository $orders, ReviewRepository $reviews): Response
    {
        $buyer = $this->getUser();
        \assert($buyer instanceof User);

        // SECURITY: every query is scoped to the logged-in buyer.
        return $this->render('buyer/dashboard.html.twig', [
            'stats' => $stats->forBuyer($buyer),
            'recentOrders' => $orders->findForBuyer($buyer, 5),
            'toReview' => $reviews->findProductsToReview($buyer, 6),
        ]);
    }
}
