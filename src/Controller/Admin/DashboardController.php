<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): second layer after access_control (^/admin -> ROLE_ADMIN).
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(DashboardStats $stats, AuditLogRepository $logs): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats->forAdmin(),
            'events' => $logs->findRecentByActions(AuditLog::SECURITY_EVENTS, 8),
        ]);
    }
}
