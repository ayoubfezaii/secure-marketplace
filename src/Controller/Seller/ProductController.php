<?php

namespace App\Controller\Seller;

use App\Entity\AuditLog;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\User;
use App\Exception\ImageRejectedException;
use App\Exception\SsrfBlockedException;
use App\Form\ProductFormType;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Security\Voter\ProductVoter;
use App\Service\AuditLogger;
use App\Service\ImageStorage;
use App\Service\ListQuery;
use App\Service\Pagination;
use App\Service\SafeImageFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SECURITY (rule 4): sellers only (second layer after access_control). Each object is checked by ProductVoter.
#[IsGranted('ROLE_SELLER')]
#[Route('/seller/products')]
class ProductController extends AbstractController
{
    /** Sort whitelist: key => DQL field (rule 1). */
    private const SORTS = ['name' => 'p.name', 'price' => 'p.price', 'stock' => 'p.stock', 'created' => 'p.createdAt', 'rating' => 'p.ratingAverage'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageStorage $images,
        private readonly SafeImageFetcher $fetcher,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'seller_products', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products, CategoryRepository $categories): Response
    {
        $list = ListQuery::fromRequest($request, self::SORTS, 'created');
        // SECURITY (rule 15): filters are whitelisted.
        $status = in_array($request->query->getString('status'), ['active', 'inactive'], true) ? $request->query->getString('status') : null;
        $stock = 'low' === $request->query->getString('stock') ? 'low' : null;

        // SECURITY: the query is scoped to the logged-in seller; there's no "seller" parameter to tamper with.
        $qb = $products->createQueryBuilder('p')
            ->innerJoin('p.category', 'c')->addSelect('c')
            ->andWhere('p.seller = :seller')->setParameter('seller', $this->getSeller());
        if ('' !== $list->q) {
            $qb->andWhere('p.name LIKE :q')->setParameter('q', ListQuery::like($list->q));
        }
        if (null !== $status) {
            $qb->andWhere('p.isActive = :active')->setParameter('active', 'active' === $status);
        }
        if ('low' === $stock) {
            $qb->andWhere('p.stock < 5');
        }
        $list->applySort($qb)->addOrderBy('p.id', 'DESC');

        $pager = Pagination::fromQueryBuilder($qb, $request, 12);
        $rows = array_values(iterator_to_array($pager->items));
        $products->preloadImages($rows);

        return $this->render('seller/products/index.html.twig', [
            'pager' => $pager,
            'products' => $rows,
            'sales' => $products->salesCounts($rows),
            'list' => $list,
            'status' => $status,
            'stock' => $stock,
            'params' => array_filter(['status' => $status, 'stock' => $stock]) + $list->params(),
            'filters' => array_filter(['status' => $status, 'stock' => $stock, 'q' => $list->q]),
        ]);
    }

    #[Route('/new', name: 'seller_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // SECURITY (rule 9): the owner comes from the session, never from the form.
        $product = new Product($this->getSeller());
        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->handleImages($form, $product)) {
            $this->em->persist($product);
            $this->em->flush();
            $this->audit->log(AuditLog::PRODUCT_CREATED, ['product' => (string) $product->getUuid(), 'name' => $product->getName()]);
            $this->addFlash('success', sprintf('"%s" is now %s.', $product->getName(), $product->isActive() ? 'live in the catalogue' : 'saved (hidden)'));

            return $this->redirectToRoute('seller_products');
        }

        return $this->render('seller/products/form.html.twig', ['form' => $form, 'product' => null], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{uuid}/edit', name: 'seller_product_edit', methods: ['GET', 'POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function edit(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY (rule 4, IDOR): another seller's UUID in the URL -> 403.
        $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);

        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->handleImages($form, $product)) {
            $this->em->flush();
            foreach ($this->removedFiles as $file) {
                $this->images->delete($file);
            }
            $this->audit->log(AuditLog::PRODUCT_UPDATED, ['product' => (string) $product->getUuid()]);
            $this->addFlash('success', 'Product updated.');

            return $this->redirectToRoute('seller_products');
        }

        if ($form->isSubmitted()) {
            // Don't keep invalid values on the managed entity.
            $this->em->refresh($product);
        }

        return $this->render('seller/products/form.html.twig', ['form' => $form, 'product' => $product], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{uuid}/delete', name: 'seller_product_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function delete(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY (rule 3): POST + per-object CSRF token; a GET or a forged form can't delete anything.
        if (!$this->isCsrfTokenValid('delete-product-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);

        $uuid = (string) $product->getUuid();
        $name = $product->getName();
        $files = array_map(static fn (ProductImage $i) => $i->getFilename(), $product->getImages()->toArray());
        $this->em->remove($product);
        $this->em->flush();
        foreach ($files as $file) {
            $this->images->delete($file);
        }

        $this->audit->log(AuditLog::PRODUCT_DELETED, ['product' => $uuid, 'name' => $name, 'by' => 'owner']);
        $this->addFlash('success', sprintf('"%s" deleted. Past orders keep their own copy of the name and price.', $name));

        return $this->redirectToRoute('seller_products');
    }

    #[Route('/{uuid}/toggle', name: 'seller_product_toggle', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function toggle(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        if (!$this->isCsrfTokenValid('toggle-product-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // SECURITY: EDIT = owner only (admins use the moderation screen).
        $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);

        $product->setIsActive(!$product->isActive());
        $this->em->flush();
        $this->audit->log($product->isActive() ? AuditLog::PRODUCT_REACTIVATED : AuditLog::PRODUCT_DEACTIVATED, ['product' => (string) $product->getUuid(), 'by' => 'owner']);
        $this->addFlash('success', $product->isActive() ? sprintf('"%s" is visible again.', $product->getName()) : sprintf('"%s" is hidden from the catalogue.', $product->getName()));

        return $this->redirect($this->safeBackUrl($request));
    }

    /** @var list<string> files removed during handleImages(), deleted from disk after the flush */
    private array $removedFiles = [];

    /**
     * Removes the ticked images, then adds uploaded files and/or one imported URL (max 4 in total).
     * Returns false (with a form error) if an image is refused.
     *
     * @param FormInterface<Product> $form
     */
    private function handleImages(FormInterface $form, Product $product): bool
    {
        // 1. Removals (positions of the product's own images only: ChoiceType whitelist).
        if ($form->has('removeImages')) {
            $positions = (array) $form->get('removeImages')->getData();
            foreach (array_reverse($product->getImages()->toArray()) as $index => $image) {
                if (in_array($image->getPosition(), $positions, true)) {
                    $this->removedFiles[] = $image->getFilename();
                    $product->removeImage($image);
                }
            }
        }

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) $form->get('imageFiles')->getData()));
        $url = trim((string) $form->get('imageUrl')->getData());
        $incoming = count($files) + ('' !== $url ? 1 : 0);

        // SECURITY (rule 15): hard limit of 4 images per product, checked on the server.
        if ($product->getImages()->count() + $incoming > Product::MAX_IMAGES) {
            $form->get('imageFiles')->addError(new FormError(sprintf('A product can have at most %d images (it has %d).', Product::MAX_IMAGES, $product->getImages()->count())));

            return false;
        }

        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->images->storeUploadedFile($file);
            }
            if ('' !== $url) {
                $stored[] = $this->fetcher->fetchAndStore($url);
            }
        } catch (SsrfBlockedException $e) {
            // SECURITY (rules 8, 14): blocked SSRF attempts are logged with the internal reason;
            // the user only sees a generic message.
            $this->audit->log(AuditLog::SSRF_BLOCKED, ['url' => mb_substr($url, 0, 500), 'reason' => $e->getReason()]);
            $form->get('imageUrl')->addError(new FormError($e->getMessage()));
            $this->cleanup($stored);

            return false;
        } catch (ImageRejectedException $e) {
            $this->audit->log(AuditLog::UPLOAD_REJECTED, [
                'source' => '' !== $url && count($stored) === count($files) ? 'url' : 'upload',
                'client_names' => array_map(static fn (UploadedFile $f) => mb_substr($f->getClientOriginalName(), 0, 100), $files),
                'url' => '' !== $url ? mb_substr($url, 0, 500) : null,
                'reason' => $e->getMessage(),
            ]);
            $form->get(count($stored) < count($files) ? 'imageFiles' : 'imageUrl')->addError(new FormError($e->getMessage()));
            $this->cleanup($stored);

            return false;
        }

        foreach ($stored as $filename) {
            $product->addImage($filename);
        }

        return true;
    }

    /** @param list<string> $files */
    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            $this->images->delete($file);
        }
    }

    private function safeBackUrl(Request $request): string
    {
        // SECURITY (rule 12): only our own routes, never the raw Referer.
        return 'dashboard' === $request->request->getString('back') ? $this->generateUrl('seller_dashboard') : $this->generateUrl('seller_products');
    }

    private function getSeller(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
