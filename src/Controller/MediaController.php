<?php

/**
 * FILE: src/Controller/MediaController.php
 * WHAT: controller that sends product image files to the browser.
 * ROLE IN THE PROJECT: uploaded images are stored OUTSIDE the public/ folder, so the web server cannot serve them
 *   directly; every image goes through this PHP code. This is a key part of the secure file upload.
 * USED BY: everyone (the <img> tags of product pages point to /media/product/...).
 */

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
    /**
     * Sends one image file.
     * SECURITY: file upload - even if an attacker managed to store a dangerous file, it could not run: files are
     * outside public/, only image names match the route, and the response headers forbid any script execution.
     *
     * @param string       $filename name from the URL (already checked by the route regex)
     * @param ImageStorage $storage  service that knows where the files are
     *
     * @return BinaryFileResponse the file content with safe headers
     */
    // SECURITY: strict filename regex in the route itself: only "<32 hex>.(jpg|png|webp)" can match, so
    // "../../.env", "x.php" or any path separator never reaches the code (path traversal).
    #[Route('/media/product/{filename}', name: 'media_product', methods: ['GET'], requirements: ['filename' => ImageStorage::FILENAME_PATTERN])]
    public function product(string $filename, ImageStorage $storage): BinaryFileResponse
    {
        // Full path of the file, or null when it does not exist (then 404).
        $path = $storage->getPath($filename);
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        // Response that streams the file from the disk.
        $response = new BinaryFileResponse($path);
        // SECURITY: Content-Type fixed from our own whitelist, never guessed from the content.
        $response->headers->set('Content-Type', ImageStorage::mimeForFilename($filename));
        // SECURITY: the browser must not sniff it into HTML/JS.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // SECURITY: even if opened directly, nothing in it can run (no scripts, no plugins, sandboxed).
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        // "inline" = display in the browser instead of downloading.
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);
        // Random names never change content, so they can be cached for a long time.
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
}
