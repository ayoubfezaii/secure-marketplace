<?php

namespace App\Controller\Seller;

use App\Service\Money;
use App\Dto\IbanDto;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Exception\ImageRejectedException;
use App\Form\IbanFormType;
use App\Form\ShopFormType;
use App\Security\Voter\ShopVoter;
use App\Service\AuditLogger;
use App\Service\FieldEncryptor;
use App\Service\ImageStorage;
use App\Service\SellerAnalytics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My shop" (public profile) and "Payouts" (encrypted IBAN + simulated payout history).
 */
#[IsGranted('ROLE_SELLER')]
class ShopController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/seller/shop', name: 'seller_shop', methods: ['GET', 'POST'])]
    public function shop(Request $request, ImageStorage $images): Response
    {
        $seller = $this->seller();
        $shop = $seller->getShop() ?? throw $this->createNotFoundException('No shop for this account.');
        // SECURITY (rule 4): ShopVoter, only the owner.
        $this->denyAccessUnlessGranted(ShopVoter::EDIT, $shop);

        $oldLogo = $shop->getLogoFilename();
        $form = $this->createForm(ShopFormType::class, $shop);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('logoFile')->getData();
            $logoOk = true;
            try {
                if (null !== $file) {
                    // SECURITY (rule 7): same validation pipeline as product images.
                    $shop->setLogoFilename($images->storeUploadedFile($file));
                } elseif (true === $form->get('removeLogo')->getData()) {
                    $shop->setLogoFilename(null);
                }
            } catch (ImageRejectedException $e) {
                $this->audit->log(AuditLog::UPLOAD_REJECTED, ['source' => 'shop_logo', 'client_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'reason' => $e->getMessage()]);
                $form->get('logoFile')->addError(new FormError($e->getMessage()));
                $logoOk = false;
            }

            if ($logoOk) {
                $this->em->flush();
                if ($oldLogo !== $shop->getLogoFilename()) {
                    $images->delete($oldLogo);
                }
                $this->audit->log(AuditLog::SHOP_UPDATED, ['shop' => $shop->getSlug()]);
                $this->addFlash('success', 'Your shop has been updated.');

                return $this->redirectToRoute('seller_shop');
            }
        }
        if ($form->isSubmitted()) {
            // Don't keep invalid values (e.g. a taken slug) on the managed entity.
            $this->em->refresh($shop);
        }

        return $this->render('seller/shop.html.twig', ['form' => $form, 'shop' => $shop], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/seller/payouts', name: 'seller_payouts', methods: ['GET', 'POST'])]
    public function payouts(Request $request, FieldEncryptor $encryptor, SellerAnalytics $analytics): Response
    {
        $seller = $this->seller();
        $dto = new IbanDto();
        $form = $this->createForm(IbanFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $iban = $dto->normalizedIban();
            if ($dto->removeIban) {
                $seller->removeIban();
            } elseif (null !== $iban) {
                // SECURITY (rule 11): encrypted before it touches the entity; the clear IBAN is never persisted or logged.
                $seller->setEncryptedIban($encryptor->encrypt($iban), substr($iban, -4));
            } else {
                $this->addFlash('info', 'Nothing to change.');

                return $this->redirectToRoute('seller_payouts');
            }
            $this->em->flush();
            // SECURITY (rule 14): logged without the IBAN itself.
            $this->audit->log(AuditLog::IBAN_UPDATED, ['removed' => $dto->removeIban]);
            $this->addFlash('success', $dto->removeIban ? 'Your IBAN has been removed.' : 'Your payout account has been saved (encrypted).');

            return $this->redirectToRoute('seller_payouts');
        }

        $payouts = $analytics->payouts($seller);
        $balance = Money::ZERO;
        $paid = Money::ZERO;
        foreach ($payouts as $p) {
            if ('scheduled' === $p['status']) {
                $balance = bcadd($balance, $p['net'], Money::SCALE);
            } else {
                $paid = bcadd($paid, $p['net'], Money::SCALE);
            }
        }

        $response = $this->render('seller/payouts.html.twig', [
            'form' => $form,
            'maskedIban' => $seller->getMaskedIban(),
            'payouts' => $payouts,
            'balance' => $balance,
            'paidTotal' => $paid,
            'commission' => (float) SellerAnalytics::COMMISSION * 100,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
        // SECURITY (rule 11): bank details page never cached.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function seller(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
