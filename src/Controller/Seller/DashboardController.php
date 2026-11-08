<?php

namespace App\Controller\Seller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control in security.yaml.
#[IsGranted('ROLE_SELLER')]
class DashboardController extends AbstractController
{
    #[Route('/seller', name: 'seller_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('seller/dashboard.html.twig');
    }
}
