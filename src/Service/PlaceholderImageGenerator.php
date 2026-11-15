<?php

/**
 * FILE: src/Service/PlaceholderImageGenerator.php
 * WHAT: service that draws simple demo pictures for the sample products.
 * ROLE IN THE PROJECT: demo data only (no real photos needed). Not used by the live site.
 * USED BY: App\DataFixtures\AppFixtures when the demo database is loaded.
 */

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates elegant placeholder product pictures with GD (demo data only, no stock photos):
 * a soft vertical gradient in the Maison Secure palette, a large geometric shape that depends on the category,
 * and the product initials in Playfair Display.
 *
 * The produced JPEG is then stored through ImageStorage::validateAndStore(), i.e. exactly the same validation
 * and re-encoding pipeline as a user upload (rule 7).
 */
final class PlaceholderImageGenerator
{
    // Size of the generated picture, in pixels.
    private const W = 800;
    private const H = 1000;

    /** [top colour, bottom colour, shape colour, accent colour] */
    private const PALETTES = [
        [[247, 243, 238], [226, 216, 203], [20, 20, 20], [232, 112, 26]],
        [[237, 230, 220], [214, 199, 182], [139, 126, 116], [20, 20, 20]],
        [[244, 232, 220], [232, 190, 158], [20, 20, 20], [247, 243, 238]],
        [[230, 228, 222], [196, 190, 180], [46, 125, 91], [232, 112, 26]],
        [[41, 38, 35], [20, 20, 20], [232, 112, 26], [237, 230, 220]],
        [[240, 236, 229], [221, 210, 196], [59, 110, 143], [212, 160, 23]],
    ];

    /**
     * @param string       $fontPath path of the TTF font used for the initials
     * @param ImageStorage $storage  stores the generated picture like a real upload
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/fonts/PlayfairDisplay.ttf')]
        private readonly string $fontPath,
        private readonly ImageStorage $storage,
    ) {
    }

    /**
     * Draws one picture and stores it.
     *
     * @param string $title   product name (its initials are written on the picture)
     * @param string $shape   which shape to draw (ring, arch, book, bag, wave, star, device, vase...)
     * @param int    $seed    number that chooses the colour palette
     * @param int    $variant 0, 1, 2... to get different pictures for the same product
     *
     * @return string the stored (random) filename
     */
    public function generate(string $title, string $shape, int $seed, int $variant = 0): string
    {
        // Empty canvas and the 4 colours of the chosen palette.
        $img = imagecreatetruecolor(self::W, self::H);
        [$top, $bottom, $ink, $accent] = self::PALETTES[($seed + $variant) % count(self::PALETTES)];

        // Soft vertical gradient.
        for ($y = 0; $y < self::H; ++$y) {
            $t = $y / (self::H - 1);
            $c = imagecolorallocate($img, ...$this->mix($top, $bottom, $t));
            imageline($img, 0, $y, self::W, $y, $c);
        }
        imageantialias($img, true);

        // Colours for the shape, its accent and a transparent shadow; centre of the shape.
        $inkColor = imagecolorallocate($img, ...$ink);
        $accentColor = imagecolorallocate($img, ...$accent);
        $soft = imagecolorallocatealpha($img, $ink[0], $ink[1], $ink[2], 100);
        $cx = (int) (self::W / 2 + ($variant % 2 ? 60 : -40));
        $cy = (int) (self::H * 0.44 + ($variant > 1 ? 40 : 0));

        // Ground shadow.
        imagefilledellipse($img, $cx, $cy + 250, 420, 46, $soft);

        $this->drawShape($img, $shape, $cx, $cy, $inkColor, $accentColor, $variant);

        // Accent dot + thin frame line.
        imagefilledellipse($img, 680 - $variant * 30, 150, 46, 46, $accentColor);
        imagerectangle($img, 40, 40, self::W - 41, self::H - 41, imagecolorallocatealpha($img, $ink[0], $ink[1], $ink[2], 95));

        // Product initials (serif) + small caption.
        $initials = $this->initials($title);
        $textColor = imagecolorallocate($img, ...$ink === [232, 112, 26] ? [237, 230, 220] : $ink);
        if (is_file($this->fontPath)) {
            imagettftext($img, 64, 0, 80, self::H - 110, $textColor, $this->fontPath, $initials);
            imagettftext($img, 18, 0, 84, self::H - 70, $textColor, $this->fontPath, 'MAISON SECURE');
        } else {
            imagestring($img, 5, 80, self::H - 120, $initials, $textColor);
        }

        // Save as JPEG in a temporary file.
        $tmp = tempnam(sys_get_temp_dir(), 'ph');
        if (false === $tmp) {
            throw new \RuntimeException('No temporary directory.');
        }
        imagejpeg($img, $tmp, 86);
        imagedestroy($img);

        try {
            // Same validation + re-encoding as an upload (rule 7).
            return $this->storage->validateAndStore($tmp);
        // Always delete the temporary file.
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Draws the big geometric shape of the picture (one "case" per shape name).
     */
    private function drawShape(\GdImage $img, string $shape, int $cx, int $cy, int $ink, int $accent, int $variant): void
    {
        imagesetthickness($img, 14);
        switch ($shape) {
            case 'ring': // jewellery / accessories
                imagefilledellipse($img, $cx, $cy, 360, 360, $ink);
                imagefilledellipse($img, $cx, $cy, 250, 250, $this->bg($img, $cx, $cy - 240));
                imagefilledpolygon($img, [$cx - 50, $cy - 200, $cx + 50, $cy - 200, $cx, $cy - 120], $accent);
                break;
            case 'arch': // home & living
                imagefilledrectangle($img, $cx - 170, $cy - 40, $cx + 170, $cy + 230, $ink);
                imagefilledellipse($img, $cx, $cy - 40, 340, 340, $ink);
                imagefilledrectangle($img, $cx - 110, $cy, $cx + 110, $cy + 230, $accent);
                imagefilledellipse($img, $cx, $cy, 220, 220, $accent);
                break;
            case 'book':
                imagefilledrectangle($img, $cx - 150, $cy - 210, $cx + 150, $cy + 200, $ink);
                imagefilledrectangle($img, $cx - 150, $cy - 210, $cx - 120, $cy + 200, $accent);
                imagefilledrectangle($img, $cx - 80, $cy - 130, $cx + 100, $cy - 110, $accent);
                imagefilledrectangle($img, $cx - 80, $cy - 80, $cx + 60, $cy - 66, $accent);
                break;
            case 'bag': // clothing / fashion
                imagearc($img, $cx, $cy - 150, 160, 170, 180, 360, $ink);
                imagefilledpolygon($img, [$cx - 190, $cy - 150, $cx + 190, $cy - 150, $cx + 160, $cy + 220, $cx - 160, $cy + 220], $ink);
                imagefilledellipse($img, $cx, $cy + 30, 90, 90, $accent);
                break;
            case 'wave': // sports / outdoors
                for ($i = 0; $i < 4; ++$i) {
                    imagearc($img, $cx, $cy + 60 + $i * 50, 380 - $i * 40, 260 - $i * 30, 200, 340, 0 === $i % 2 ? $ink : $accent);
                }
                imagefilledellipse($img, $cx, $cy - 120, 130, 130, $accent);
                break;
            case 'star': // toys / games
                $points = [];
                for ($i = 0; $i < 10; ++$i) {
                    $r = 0 === $i % 2 ? 230 : 100;
                    $a = deg2rad(-90 + $i * 36);
                    $points[] = (int) ($cx + $r * cos($a));
                    $points[] = (int) ($cy + $r * sin($a));
                }
                imagefilledpolygon($img, $points, $ink);
                imagefilledellipse($img, $cx, $cy + 10, 80, 80, $accent);
                break;
            case 'device': // electronics
                imagefilledrectangle($img, $cx - 140, $cy - 230, $cx + 140, $cy + 230, $ink);
                imagefilledrectangle($img, $cx - 115, $cy - 195, $cx + 115, $cy + 160, $accent);
                imagefilledellipse($img, $cx, $cy + 196, 30, 30, $accent);
                break;
            case 'vase': // beauty / decor
                imagefilledellipse($img, $cx, $cy + 60, 300, 340, $ink);
                imagefilledrectangle($img, $cx - 55, $cy - 200, $cx + 55, $cy - 60, $ink);
                imagefilledrectangle($img, $cx - 80, $cy - 215, $cx + 80, $cy - 190, $ink);
                imagefilledellipse($img, $cx + 60, $cy + 20, 70, 110, $accent);
                break;
            default: // diamond
                imagefilledpolygon($img, [$cx, $cy - 240, $cx + 200, $cy, $cx, $cy + 240, $cx - 200, $cy], $ink);
                imagefilledpolygon($img, [$cx, $cy - 120, $cx + 100, $cy, $cx, $cy + 120, $cx - 100, $cy], $accent);
        }
        imagesetthickness($img, 1);
    }

    /** Colour of the pixel at (x, y), with the coordinates kept inside the picture. */
    private function bg(\GdImage $img, int $x, int $y): int
    {
        return imagecolorat($img, max(0, min(self::W - 1, $x)), max(0, min(self::H - 1, $y)));
    }

    /**
     * Mixes two colours: $t = 0 gives $a, $t = 1 gives $b (used for the gradient).
     *
     * @param array{0: int, 1: int, 2: int} $a
     * @param array{0: int, 1: int, 2: int} $b
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function mix(array $a, array $b, float $t): array
    {
        return [(int) round($a[0] + ($b[0] - $a[0]) * $t), (int) round($a[1] + ($b[1] - $a[1]) * $t), (int) round($a[2] + ($b[2] - $a[2]) * $t)];
    }

    /** First letters of the first two words of the title (words of 3+ characters), or "MS". */
    private function initials(string $title): string
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $title) ?: [], static fn ($w) => mb_strlen($w) > 2));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return '' !== $initials ? $initials : 'MS';
    }
}
