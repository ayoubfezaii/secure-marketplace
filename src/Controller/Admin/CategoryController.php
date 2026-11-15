<?php

/**
 * FILE: src/Controller/Admin/CategoryController.php
 * WHAT: controller of the admin pages for categories: list, create, edit, delete.
 * ROLE IN THE PROJECT: a simple CRUD reserved to admins.
 * USED BY: admins only (ROLE_ADMIN). URLs start with /admin/categories.
 */

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
    /**
     * @param EntityManagerInterface $em    Doctrine, to save / delete
     * @param AuditLogger            $audit writes into the audit log
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** List of categories with the number of products in each. Admin only. */
    #[Route('', name: 'admin_categories', methods: ['GET'])]
    public function index(CategoryRepository $categories): Response
    {
        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categories->findAllOrdered(),
            'counts' => $categories->countProducts(),
        ]);
    }

    /** Form to create a category (GET shows it, POST saves it). Admin only. */
    #[Route('/new', name: 'admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = new Category();
        // Symfony form: fills the category, checks the CSRF token and validates the constraints (slug format, icon list...).
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

    /**
     * Form to edit a category. Admin only.
     *
     * @param string $slug slug of the category taken from the URL
     */
    // SECURITY (rule 15): the slug in the URL must match the strict slug format.
    #[Route('/{slug}/edit', name: 'admin_category_edit', methods: ['GET', 'POST'], requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function edit(Request $request, string $slug, CategoryRepository $categories): Response
    {
        // Find the category (bound parameter) or answer 404.
        $category = $categories->findOneBySlug($slug) ?? throw $this->createNotFoundException();
        $form = $this->createForm(CategoryFormType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->audit->log(AuditLog::CATEGORY_UPDATED, ['slug' => $category->getSlug(), 'old_slug' => $slug]);
            $this->addFlash('success', sprintf('Category "%s" updated.', $category->getName()));

            return $this->redirectToRoute('admin_categories');
        }
        // Invalid form: reload the category from the database so the wrong values are not kept.
        if ($form->isSubmitted()) {
            $this->em->refresh($category);
        }

        return $this->render('admin/categories/form.html.twig', ['form' => $form, 'category' => $category], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Deletes a category. POST only. Admin only. Refused while the category still has products.
     */
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

        // Delete, log, confirm.
        $name = $category->getName();
        $this->em->remove($category);
        $this->em->flush();
        $this->audit->log(AuditLog::CATEGORY_DELETED, ['slug' => $slug]);
        $this->addFlash('success', sprintf('Category "%s" deleted.', $name));

        return $this->redirectToRoute('admin_categories');
    }
}
