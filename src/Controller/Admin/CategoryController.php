<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Category;
use App\Form\CategoryFormType;
use App\Repository\CategoryRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Category CRUD (admin only). A category that still has products can't be deleted (checked here AND by the
 * database: product.category_id is ON DELETE RESTRICT).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/categories')]
class CategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'admin_categories', methods: ['GET'])]
    public function index(CategoryRepository $categories): Response
    {
        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categories->findAllOrdered(),
            'counts' => $categories->countProducts(),
        ]);
    }

    #[Route('/new', name: 'admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryFormType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();
            $this->audit->log(AuditLog::CATEGORY_CREATED, ['slug' => $category->getSlug()]);
            $this->addFlash('success', sprintf('Category "%s" created.', $category->getName()));

            return $this->redirectToRoute('admin_categories');
        }

        return $this->render('admin/categories/form.html.twig', ['form' => $form, 'category' => null], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    // SECURITY (rule 15): the slug in the URL must match the strict slug format.
    #[Route('/{slug}/edit', name: 'admin_category_edit', methods: ['GET', 'POST'], requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function edit(Request $request, string $slug, CategoryRepository $categories): Response
    {
        $category = $categories->findOneBySlug($slug) ?? throw $this->createNotFoundException();
        $form = $this->createForm(CategoryFormType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->audit->log(AuditLog::CATEGORY_UPDATED, ['slug' => $category->getSlug(), 'old_slug' => $slug]);
            $this->addFlash('success', sprintf('Category "%s" updated.', $category->getName()));

            return $this->redirectToRoute('admin_categories');
        }
        if ($form->isSubmitted()) {
            $this->em->refresh($category);
        }

        return $this->render('admin/categories/form.html.twig', ['form' => $form, 'category' => $category], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{slug}/delete', name: 'admin_category_delete', methods: ['POST'], requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function delete(Request $request, string $slug, CategoryRepository $categories): Response
    {
        $category = $categories->findOneBySlug($slug) ?? throw $this->createNotFoundException();
        // SECURITY (rule 3): POST + per-category CSRF token.
        if (!$this->isCsrfTokenValid('category-delete-'.$category->getSlug(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // Integrity: never orphan products.
        if (($categories->countProducts()[$category->getId()] ?? 0) > 0) {
            $this->addFlash('error', sprintf('"%s" still has products. Move or delete them first.', $category->getName()));

            return $this->redirectToRoute('admin_categories');
        }

        $name = $category->getName();
        $this->em->remove($category);
        $this->em->flush();
        $this->audit->log(AuditLog::CATEGORY_DELETED, ['slug' => $slug]);
        $this->addFlash('success', sprintf('Category "%s" deleted.', $name));

        return $this->redirectToRoute('admin_categories');
    }
}
