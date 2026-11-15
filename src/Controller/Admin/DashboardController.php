<?php

/**
 * FILE: src/Controller/Admin/DashboardController.php
 * WHAT: controller of the admin dashboard (/admin).
 * ROLE IN THE PROJECT: overview of the whole site: users, orders, revenue, last events, last security events.
 * USED BY: admins only (ROLE_ADMIN).
 */

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Service\AdminStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control (^/admin -> ROLE_ADMIN).
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    /**
     * Admin dashboard page. GET only. Admin only.
     *
     * @param AdminStats         $stats service that computes the site figures
     * @param AuditLogRepository $logs  to read the latest audit events
     *
     * @return Response the rendered template admin/dashboard.html.twig
     */
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(AdminStats $stats, AuditLogRepository $logs): Response
    {
        // Global numbers (users per role, orders, revenue...).
        $overview = $stats->overview();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $overview,
            // Charts get their data through escaped data-* attributes (no inline script, rule 13).
            'daily' => $stats->ordersByDay(30),
            'roles' => ['labels' => ['Buyers', 'Sellers', 'Admins'], 'data' => [$overview['buyers'], $overview['sellers'], $overview['admins']]],
            'events' => $logs->findRecent(10),
            'securityEvents' => $logs->findRecentByActions(AuditLog::SECURITY_EVENTS, 6),
        ]);
    }
}
