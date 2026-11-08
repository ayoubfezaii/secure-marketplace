<?php

namespace App\Controller\Buyer;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control in security.yaml.
#[IsGranted('ROLE_BUYER')]
class DashboardController extends AbstractController
{
    #[Route('/buyer', name: 'buyer_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('buyer/dashboard.html.twig');
    }
}
