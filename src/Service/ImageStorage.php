<?php

namespace App\Service;

use App\Exception\ImageRejectedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Secure storage of product images (rule 7).
 *
 * Every image, uploaded or imported from a URL, goes through validateAndStore():
 *  1. size <= 2 MB
 *  2. real MIME type from the file CONTENT (finfo magic bytes), never the client's Content-Type or extension
 *  3. whitelist: image/jpeg, image/png, image/webp
 *  4. getimagesize() must parse it, with the same type, and sane dimensions (decompression-bomb guard)
 *  5. RE-ENCODED with GD: only pixels survive. Hidden PHP/HTML/JS payloads (polyglots), EXIF metadata
 *     (GPS location...) and malformed chunks are dropped.
 *  6. stored under a random name (128-bit) in var/uploads/products, OUTSIDE public/: the web server can
 *     never execute it, and it is only served by MediaController with a fixed Content-Type.
 */
final class ImageStorage
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_PIXELS = 20_000_000;  // ~ 5000 x 4000
    private const MAX_SIDE = 8000;

    /** MIME type => [IMAGETYPE_* constant, stored extension]. */
    private const ALLOWED = [
        'image/jpeg' => [\IMAGETYPE_JPEG, 'jpg'],
        'image/png' => [\IMAGETYPE_PNG, 'png'],
        'image/webp' => [\IMAGETYPE_WEBP, 'webp'],
    ];

    /** Strict pattern for stored names; MediaController uses the same one. */
    public const FILENAME_PATTERN = '[a-f0-9]{32}\.(?:jpg|png|webp)';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads/products')]
        private readonly string $uploadDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @throws ImageRejectedException
     */
    public function storeUploadedFile(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new ImageRejectedException('The upload failed or the file is too large (max 2 MB).');
        }

        return $this->validateAndStore($file->getPathname());
    }

    /**
     * @return string the random stored filename
     *
     * @throws ImageRejectedException when the file is refused (message safe to show to the user)
     */
    public function validateAndStore(string $tmpPath): string
    {
        // 1. Size (also limited by the form and by php.ini upload_max_filesize).
        $size = @filesize($tmpPath);
        if (false === $size || 0 === $size || $size > self::MAX_BYTES) {
            throw new ImageRejectedException('The image must be smaller than 2 MB.');
        }

        // 2-3. SECURITY: real MIME from the bytes (magic numbers). A "shell.php" renamed "cat.jpg" is text/x-php here.
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            throw new ImageRejectedException(sprintf('File type "%s" is not allowed. Use JPG, PNG or WebP.', is_string($mime) ? $mime : 'unknown'));
        }
        [$expectedType, $extension] = self::ALLOWED[$mime];

        // 4. SECURITY: the image header must parse and match the detected type.
        $info = @getimagesize($tmpPath);
        if (false === $info || $info[2] !== $expectedType) {
            throw new ImageRejectedException('The file is not a valid image.');
        }
        [$width, $height] = $info;
        // SECURITY: decompression bomb. A tiny file can declare 50000x50000 px and exhaust memory when decoded.
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new ImageRejectedException('Image dimensions are too large.');
        }

        // 5. SECURITY: decode + re-encode. Anything that isn't pixel data is thrown away.
        $image = match ($expectedType) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
            \IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
            \IMAGETYPE_WEBP => @imagecreatefromwebp($tmpPath),
        };
        if (false === $image) {
            throw new ImageRejectedException('The image could not be decoded.');
        }

        // 6. SECURITY: random name, extension derived from the detected type, never from the client's filename
        // (no path traversal "../../", no ".php", no double extension "x.php.jpg").
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $this->filesystem->mkdir($this->uploadDir, 0750);
        $target = $this->uploadDir.\DIRECTORY_SEPARATOR.$filename;

        $ok = match ($expectedType) {
            \IMAGETYPE_JPEG => imagejpeg($image, $target, 85),
            \IMAGETYPE_PNG => $this->savePng($image, $target),
            \IMAGETYPE_WEBP => imagewebp($image, $target, 85),
        };
        imagedestroy($image);

        if (!$ok) {
            $this->filesystem->remove($target);
            throw new ImageRejectedException('The image could not be saved.');
        }

        return $filename;
    }

    public function delete(?string $filename): void
    {
        // SECURITY: only names matching our own pattern are ever deleted (no path traversal through the DB).
        if (null !== $filename && self::isValidFilename($filename)) {
            $this->filesystem->remove($this->uploadDir.\DIRECTORY_SEPARATOR.$filename);
        }
    }

    public function getPath(string $filename): ?string
    {
        if (!self::isValidFilename($filename)) {
            return null;
        }
        $path = $this->uploadDir.\DIRECTORY_SEPARATOR.$filename;

        return is_file($path) ? $path : null;
    }

    public static function isValidFilename(string $filename): bool
    {
        return 1 === preg_match('/^'.self::FILENAME_PATTERN.'$/D', $filename);
    }

    public static function mimeForFilename(string $filename): string
    {
        return match (pathinfo($filename, \PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function savePng(\GdImage $image, string $target): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $target, 6);
    }
}
