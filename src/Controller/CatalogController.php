<?php

namespace App\Controller;

use App\Entity\Product;
use App\Security\Voter\ProductVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

class CatalogController extends AbstractController
{
    #[Route('/products/{uuid}', name: 'product_show', methods: ['GET'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function show(#[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product): Response
    {
        // SECURITY (rule 4): hidden products are visible only to their owner and admins.
        // 404 rather than 403, so their existence isn't revealed.
        if (!$this->isGranted(ProductVoter::VIEW, $product)) {
            throw $this->createNotFoundException();
        }

        return $this->render('catalog/show.html.twig', ['product' => $product]);
    }
}
