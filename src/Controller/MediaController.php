<?php

namespace App\Controller;

use App\Service\ImageStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves product images stored OUTSIDE public/ (rule 7).
 */
class MediaController extends AbstractController
{
    // SECURITY: strict filename regex in the route itself: only "<32 hex>.(jpg|png|webp)" can match, so
    // "../../.env", "x.php" or any path separator never reaches the code (path traversal).
    #[Route('/media/product/{filename}', name: 'media_product', methods: ['GET'], requirements: ['filename' => ImageStorage::FILENAME_PATTERN])]
    public function product(string $filename, ImageStorage $storage): BinaryFileResponse
    {
        $path = $storage->getPath($filename);
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        // SECURITY: Content-Type fixed from our own whitelist, never guessed from the content.
        $response->headers->set('Content-Type', ImageStorage::mimeForFilename($filename));
        // SECURITY: the browser must not sniff it into HTML/JS.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // SECURITY: even if opened directly, nothing in it can run (no scripts, no plugins, sandboxed).
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);
        // Random names never change content, so they can be cached for a long time.
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
}
