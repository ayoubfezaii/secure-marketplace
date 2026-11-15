<?php

/**
 * FILE: src/Controller/DashboardController.php
 * WHAT: controller of the generic /dashboard URL.
 * ROLE IN THE PROJECT: one link "My dashboard" that works for every role.
 * USED BY: any logged-in user (buyer, seller, admin).
 */

namespace App\Controller;

use App\Security\LoginSuccessHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * /dashboard sends each user to the dashboard of their role.
 */
class DashboardController extends AbstractController
{
    /**
     * Redirects to the dashboard of the user's role (buyer, seller or admin).
     * SECURITY: access control - IS_AUTHENTICATED_FULLY refuses visitors and users who did not finish the 2FA step.
     */
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute(LoginSuccessHandler::dashboardRoute($this->getUser()));
    }
}
