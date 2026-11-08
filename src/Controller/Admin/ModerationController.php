<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\Review;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Security\Voter\ProductVoter;
use App\Service\AuditLogger;
use App\Service\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Products, orders and reviews moderation.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class ModerationController extends AbstractController
{
    #[Route('/products', name: 'admin_products', methods: ['GET'])]
    public function products(Request $request, ProductRepository $products): Response
    {
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);
        $state = in_array($request->query->getString('state'), ['active', 'hidden'], true) ? $request->query->getString('state') : null;

        return $this->render('admin/products.html.twig', [
            'pager' => Pagination::fromQueryBuilder($products->createAdminQueryBuilder($q, $state), $request, 20),
            'params' => array_filter(['q' => $q, 'state' => $state]),
            'q' => $q,
            'state' => $state,
        ]);
    }

    #[Route('/products/{uuid}/toggle', name: 'admin_product_toggle', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function toggleProduct(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product,
        EntityManagerInterface $em,
        AuditLogger $audit,
    ): Response {
        $this->denyAccessUnlessGranted(ProductVoter::TOGGLE, $product);
        if (!$this->isCsrfTokenValid('admin-toggle-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $product->setIsActive(!$product->isActive());
        $em->flush();
        $audit->log($product->isActive() ? AuditLog::PRODUCT_REACTIVATED : AuditLog::PRODUCT_DEACTIVATED, [
            'product' => (string) $product->getUuid(),
            'name' => $product->getName(),
            'seller' => $product->getSeller()->getEmail(),
            'by' => 'admin',
        ]);
        $this->addFlash('success', sprintf('"%s" %s.', $product->getName(), $product->isActive() ? 'reactivated' : 'deactivated'));

        return $this->redirectToRoute('admin_products');
    }

    #[Route('/orders', name: 'admin_orders', methods: ['GET'])]
    public function orders(Request $request, OrderRepository $orders): Response
    {
        $status = $request->query->getString('status');
        // SECURITY (rule 15): whitelist.
        $status = in_array($status, Order::STATUSES, true) ? $status : null;

        return $this->render('admin/orders.html.twig', [
            'pager' => Pagination::fromQueryBuilder($orders->createAdminQueryBuilder($status), $request, 20),
            'params' => array_filter(['status' => $status]),
            'status' => $status,
            'statuses' => Order::STATUSES,
        ]);
    }

    #[Route('/reviews', name: 'admin_reviews', methods: ['GET'])]
    public function reviews(Request $request, EntityManagerInterface $em): Response
    {
        $qb = $em->createQueryBuilder()
            ->select('r', 'p', 'a')
            ->from(Review::class, 'r')->innerJoin('r.product', 'p')->innerJoin('r.author', 'a')
            ->orderBy('r.createdAt', 'DESC');

        // Deletion goes through ReviewController::delete (ReviewVoter + CSRF + audit), with back=admin.
        return $this->render('admin/reviews.html.twig', [
            'pager' => Pagination::fromQueryBuilder($qb, $request, 20),
            'params' => [],
        ]);
    }
}
