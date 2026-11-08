<?php

namespace App\Controller\Seller;

use App\Entity\AuditLog;
use App\Entity\Product;
use App\Entity\User;
use App\Exception\ImageRejectedException;
use App\Exception\SsrfBlockedException;
use App\Form\ProductFormType;
use App\Repository\ProductRepository;
use App\Security\Voter\ProductVoter;
use App\Service\AuditLogger;
use App\Service\ImageStorage;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageStorage $images,
        private readonly SafeImageFetcher $fetcher,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'seller_products', methods: ['GET'])]
    public function index(ProductRepository $products): Response
    {
        // SECURITY: the query is scoped to the logged-in seller; there's no "seller" parameter to tamper with.
        return $this->render('seller/products/index.html.twig', [
            'products' => $products->findBySeller($this->getSeller()),
        ]);
    }

    #[Route('/new', name: 'seller_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // SECURITY (rule 9): the owner comes from the session, never from the form.
        $product = new Product($this->getSeller());
        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->handleImage($form, $product)) {
            $this->em->persist($product);
            $this->em->flush();
            $this->audit->log(AuditLog::PRODUCT_CREATED, ['product' => (string) $product->getUuid(), 'name' => $product->getName()]);
            $this->addFlash('success', 'Product created.');

            return $this->redirectToRoute('seller_products');
        }

        return $this->render('seller/products/form.html.twig', ['form' => $form, 'product' => null]);
    }

    #[Route('/{uuid}/edit', name: 'seller_product_edit', methods: ['GET', 'POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function edit(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY (rule 4, IDOR): another seller's UUID in the URL -> 403.
        $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);

        $oldImage = $product->getImageFilename();
        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->handleImage($form, $product)) {
            $this->em->flush();
            if ($oldImage !== $product->getImageFilename()) {
                $this->images->delete($oldImage);
            }
            $this->audit->log(AuditLog::PRODUCT_UPDATED, ['product' => (string) $product->getUuid()]);
            $this->addFlash('success', 'Product updated.');

            return $this->redirectToRoute('seller_products');
        }

        if ($form->isSubmitted()) {
            // Don't keep invalid values on the managed entity.
            $this->em->refresh($product);
        }

        return $this->render('seller/products/form.html.twig', ['form' => $form, 'product' => $product]);
    }

    #[Route('/{uuid}/delete', name: 'seller_product_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function delete(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        $this->denyAccessUnlessGranted(ProductVoter::DELETE, $product);
        // SECURITY (rule 3): POST + per-object CSRF token; a GET or a forged form can't delete anything.
        if (!$this->isCsrfTokenValid('delete-product-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $uuid = (string) $product->getUuid();
        $image = $product->getImageFilename();
        $this->em->remove($product);
        $this->em->flush();
        $this->images->delete($image);

        $this->audit->log(AuditLog::PRODUCT_DELETED, ['product' => $uuid, 'name' => $product->getName()]);
        $this->addFlash('success', 'Product deleted.');

        return $this->redirectToRoute('seller_products');
    }

    #[Route('/{uuid}/toggle', name: 'seller_product_toggle', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function toggle(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        $this->denyAccessUnlessGranted(ProductVoter::TOGGLE, $product);
        if (!$this->isCsrfTokenValid('toggle-product-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $product->setIsActive(!$product->isActive());
        $this->em->flush();
        $this->audit->log($product->isActive() ? AuditLog::PRODUCT_REACTIVATED : AuditLog::PRODUCT_DEACTIVATED, ['product' => (string) $product->getUuid(), 'by' => 'owner']);
        $this->addFlash('success', $product->isActive() ? 'Product is visible again.' : 'Product hidden from the catalogue.');

        return $this->redirect($this->safeBackUrl($request));
    }

    /**
     * Applies an uploaded file OR an imported URL. Returns false (with a form error) if the image is refused.
     *
     * @param FormInterface<Product> $form
     */
    private function handleImage(FormInterface $form, Product $product): bool
    {
        /** @var UploadedFile|null $file */
        $file = $form->get('imageFile')->getData();
        $url = trim((string) $form->get('imageUrl')->getData());

        if (null !== $file && '' !== $url) {
            $form->get('imageUrl')->addError(new FormError('Choose either a file or a URL, not both.'));

            return false;
        }

        try {
            if (null !== $file) {
                $product->setImageFilename($this->images->storeUploadedFile($file));
            } elseif ('' !== $url) {
                $product->setImageFilename($this->fetcher->fetchAndStore($url));
            }
        } catch (SsrfBlockedException $e) {
            // SECURITY (rules 8, 14): blocked SSRF attempts are logged with the internal reason;
            // the user only sees a generic message.
            $this->audit->log(AuditLog::SSRF_BLOCKED, ['url' => mb_substr($url, 0, 500), 'reason' => $e->getReason()]);
            $form->get('imageUrl')->addError(new FormError($e->getMessage()));

            return false;
        } catch (ImageRejectedException $e) {
            $this->audit->log(AuditLog::UPLOAD_REJECTED, [
                'source' => null !== $file ? 'upload' : 'url',
                'client_name' => null !== $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null,
                'url' => '' !== $url ? mb_substr($url, 0, 500) : null,
                'reason' => $e->getMessage(),
            ]);
            $form->get(null !== $file ? 'imageFile' : 'imageUrl')->addError(new FormError($e->getMessage()));

            return false;
        }

        return true;
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
