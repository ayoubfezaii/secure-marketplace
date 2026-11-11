<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Repository\WishlistItemRepository;
use App\Security\SafeRedirect;
use App\Security\Voter\ProductVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Add / remove a product from the logged-in buyer's wishlist (the list page is in Buyer\WishlistController).
 *
 * SECURITY (rule 4): the line is looked up by (logged-in user, product); there is no wishlist-item id in the URL,
 * so no other buyer's wishlist can be targeted. Buyers only (sellers and admins don't shop).
 */
#[IsGranted('ROLE_BUYER')]
class WishlistController extends AbstractController
{
    #[Route('/wishlist/{uuid}/toggle', name: 'wishlist_toggle', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function toggle(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product,
        WishlistItemRepository $wishlist,
        EntityManagerInterface $em,
    ): Response {
        // SECURITY (rule 3): POST + per-product CSRF token.
        if (!$this->isCsrfTokenValid('wishlist-'.$product->getUuid(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $user = $this->getUser();
        \assert($user instanceof User);

        $line = $wishlist->findOneForUserAndProduct($user, $product);
        if (null !== $line) {
            $em->remove($line);
            $em->flush();
            $this->addFlash('success', sprintf('"%s" removed from your wishlist.', $product->getName()));
        } else {
            // SECURITY: a hidden product can't be saved by forging the request (same rule as the product page).
            if (!$this->isGranted(ProductVoter::VIEW, $product) || !$product->isActive()) {
                throw $this->createNotFoundException();
            }
            try {
                $em->persist(new WishlistItem($user, $product));
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Double click: already saved.
            }
            $this->addFlash('success', sprintf('"%s" saved to your wishlist.', $product->getName()));
        }

        // SECURITY (rule 12): only internal paths are followed.
        $back = $request->request->getString('_back');

        return $this->redirect(SafeRedirect::isSafePath($back) ? $back : $this->generateUrl('product_show', ['uuid' => $product->getUuid()]));
    }
}
