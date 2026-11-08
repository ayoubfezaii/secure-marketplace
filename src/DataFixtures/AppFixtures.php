<?php

namespace App\DataFixtures;

use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Service\ImageStorage;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data: php bin/console doctrine:fixtures:load -n
 *
 * 1 admin, 3 sellers, 5 buyers, 30 products with locally generated images, orders spread over the last 30 days
 * (so the seller chart has data), reviews and a couple of cart items.
 *
 * SECURITY notes:
 *  - DEV/DEMO ONLY. Every account shares one known password; never load this in production.
 *  - Passwords are hashed with the real Argon2id hasher (one hash per user, unique salts).
 *  - Images are generated with GD and then go through ImageStorage::validateAndStore(), exactly like an upload.
 *  - Order prices are snapshots of the product price, like CheckoutService does.
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'Password2026!';

    private const SELLERS = [
        ['seller1@shop.test', 'TechHub Store'],
        ['seller2@shop.test', 'Page & Paper'],
        ['seller3@shop.test', 'Urban Outfitters Co'],
    ];
    private const BUYERS = [
        ['buyer1@shop.test', 'Alice Martin'],
        ['buyer2@shop.test', 'Bob Dupont'],
        ['buyer3@shop.test', 'Chloé Bernard'],
        ['buyer4@shop.test', 'David Leroy'],
        ['buyer5@shop.test', 'Emma Petit'],
    ];

    /** [name, category, price, stock, seller index, description] */
    private const PRODUCTS = [
        ['Wireless Noise-Cancelling Headphones', 'electronics', '149.90', 25, 0, "Over-ear Bluetooth headphones with active noise cancelling.\n30 hours of battery life."],
        ['Mechanical Keyboard (Brown switches)', 'electronics', '89.00', 12, 0, 'Tenkeyless keyboard, hot-swappable switches, white backlight.'],
        ['USB-C Charger 65W', 'electronics', '34.99', 40, 0, 'GaN fast charger for laptops and phones. Two USB-C ports.'],
        ['4K Webcam', 'electronics', '79.50', 3, 0, 'Ultra HD webcam with dual microphones and privacy shutter.'],
        ['Portable SSD 1TB', 'electronics', '119.00', 18, 0, 'Up to 1050 MB/s, USB 3.2 Gen 2, shock resistant.'],
        ['Smart LED Bulb (pack of 3)', 'home', '29.90', 50, 0, 'Dimmable, 16 million colours, works without a hub.'],
        ['Hardware Security Key', 'electronics', '49.00', 2, 0, 'FIDO2 / U2F key for phishing-resistant two-factor authentication.'],
        ['Wi-Fi 6 Router', 'electronics', '129.00', 0, 0, 'Dual-band AX3000 router with WPA3. Currently out of stock.'],
        ['Ergonomic Mouse', 'electronics', '45.00', 30, 0, 'Vertical design that reduces wrist strain.'],
        ['Desk Lamp with Wireless Charger', 'home', '39.90', 14, 0, 'Adjustable colour temperature, Qi charging pad in the base.'],
        ['The Web Application Hacker\'s Notebook', 'books', '42.00', 9, 1, 'A practical notebook of web security testing techniques.'],
        ['Cryptography Basics', 'books', '35.50', 20, 1, 'Symmetric and public-key cryptography explained with examples.'],
        ['Clean Code in PHP', 'books', '31.00', 15, 1, 'Writing readable and maintainable PHP, with modern practices.'],
        ['Symfony Recipes', 'books', '28.90', 4, 1, 'Short, practical solutions for everyday Symfony development.'],
        ['Network Security Field Guide', 'books', '39.00', 11, 1, 'Firewalls, VPNs, IDS and how attackers move through networks.'],
        ['Notebook A5 Dotted (3 pack)', 'other', '14.50', 60, 1, 'Recycled paper, lay-flat binding, 160 pages each.'],
        ['Fountain Pen', 'other', '24.00', 22, 1, 'Steel nib, refillable converter included.'],
        ['History of Computing', 'books', '26.00', 7, 1, 'From mechanical calculators to the cloud.'],
        ['Puzzle Book for Coders', 'toys', '18.90', 16, 1, 'Logic puzzles and brain teasers for programmers.'],
        ['Board Game: Capture the Flag', 'toys', '34.00', 5, 1, 'A cooperative strategy game for 2 to 5 players.'],
        ['Organic Cotton Hoodie', 'clothing', '59.00', 20, 2, 'Unisex hoodie, 100% organic cotton, made in Portugal.'],
        ['Running Shoes', 'sports', '95.00', 13, 2, 'Lightweight daily trainers with breathable mesh.'],
        ['Rain Jacket', 'clothing', '79.00', 8, 2, 'Waterproof and windproof, packs into its own pocket.'],
        ['Yoga Mat', 'sports', '27.50', 35, 2, 'Non-slip, 6 mm thick, with carrying strap.'],
        ['Merino Wool Socks (2 pairs)', 'clothing', '19.90', 45, 2, 'Warm, breathable and odour resistant.'],
        ['Water Bottle 750ml', 'sports', '22.00', 1, 2, 'Insulated stainless steel, keeps drinks cold for 24 hours.'],
        ['Canvas Backpack', 'clothing', '64.00', 10, 2, '20 litres, padded laptop sleeve, water-resistant bottom.'],
        ['Cycling Gloves', 'sports', '24.90', 17, 2, 'Gel padding and touchscreen-compatible fingertips.'],
        ['Linen Shirt', 'clothing', '49.00', 9, 2, 'Relaxed fit, perfect for summer.'],
        ['Indoor Plant Pot Set', 'home', '32.00', 21, 2, 'Three ceramic pots with bamboo saucers.'],
    ];

    /** Category => RGB background for the placeholder images. */
    private const COLORS = [
        'electronics' => [37, 99, 235], 'books' => [180, 83, 9], 'clothing' => [190, 24, 93],
        'home' => [21, 128, 61], 'sports' => [8, 145, 178], 'toys' => [124, 58, 237], 'other' => [71, 85, 105],
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ImageStorage $images,
        #[Autowire('%kernel.project_dir%/var/uploads/products')]
        private readonly string $uploadDir,
        #[Autowire('%kernel.project_dir%/vendor/endroid/qr-code/assets/open_sans.ttf')]
        private readonly string $fontPath,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(2026);
        $out = new ConsoleOutput();

        // Old uploaded images are orphans once the product table is purged.
        $fs = new Filesystem();
        if (is_dir($this->uploadDir)) {
            foreach (glob($this->uploadDir.'/*') ?: [] as $file) {
                if (ImageStorage::isValidFilename(basename($file))) {
                    $fs->remove($file);
                }
            }
        }

        // --- Users -------------------------------------------------------------------------------------------
        $admin = $this->user('admin@shop.test', 'Site Admin', User::ROLE_ADMIN);
        $manager->persist($admin);

        $sellers = [];
        foreach (self::SELLERS as [$email, $name]) {
            $manager->persist($sellers[] = $this->user($email, $name, User::ROLE_SELLER));
        }
        $buyers = [];
        foreach (self::BUYERS as [$email, $name]) {
            $manager->persist($buyers[] = $this->user($email, $name, User::ROLE_BUYER));
        }
        $sellers[0]->setPhone('+33 6 12 34 56 78');

        // --- Products ----------------------------------------------------------------------------------------
        $products = [];
        foreach (self::PRODUCTS as $i => [$name, $category, $price, $stock, $sellerIndex, $description]) {
            $product = (new Product($sellers[$sellerIndex]))
                ->setName($name)
                ->setCategory($category)
                ->setPrice($price)
                ->setStock($stock + 10) // orders below consume some stock
                ->setDescription($description)
                ->setImageFilename($this->placeholderImage($name, $category, $i));
            // One hidden product to demonstrate moderation / owner-only visibility.
            if ('Fountain Pen' === $name) {
                $product->setIsActive(false);
            }
            $this->setCreatedAt($product, new \DateTimeImmutable(sprintf('-%d days', 40 - $i)));
            $manager->persist($product);
            $products[] = ['entity' => $product, 'targetStock' => $stock];
        }
        $out->writeln(sprintf('<info>%d products created with generated images.</info>', count($products)));

        // --- Orders (spread over the last 30 days) -----------------------------------------------------------
        $orderCount = 0;
        $purchases = []; // [buyerIndex, product, status]
        $purchasable = array_values(array_filter($products, static fn ($p) => $p['entity']->isActive()));
        foreach ($buyers as $b => $buyer) {
            if (4 === $b) {
                continue; // buyer5 has no orders: empty-state demo
            }
            $nbOrders = 2 + ($b % 2);
            for ($o = 0; $o < $nbOrders; ++$o) {
                $order = new Order($buyer, $buyer->getDisplayName() ?? 'Demo Buyer', '4242');
                $this->setCreatedAt($order, new \DateTimeImmutable(sprintf('-%d days -%d hours', mt_rand(0, 29), mt_rand(0, 23))));
                $picked = (array) array_rand($purchasable, mt_rand(1, 3));
                foreach ($picked as $idx) {
                    $product = $purchasable[$idx]['entity'];
                    $qty = mt_rand(1, 2);
                    $product->decreaseStock($qty);
                    $item = new OrderItem($order, $product, $qty);
                    // Mixed fulfilment statuses for the seller dashboards.
                    $roll = mt_rand(1, 10);
                    if ($roll > 3) {
                        $item->advanceStatus(Order::STATUS_SHIPPED);
                    }
                    if ($roll > 6) {
                        $item->advanceStatus(Order::STATUS_DELIVERED);
                    }
                    $order->addItem($item);
                    $purchases[] = [$b, $product];
                }
                $order->refreshStatus();
                $manager->persist($order);
                ++$orderCount;
            }
        }

        // Final stock values as declared above (low-stock alerts, out-of-stock product).
        foreach ($products as $p) {
            $p['entity']->setStock($p['targetStock']);
        }

        // --- Reviews (only on products the buyer actually bought, once each) --------------------------------
        $comments = [
            5 => ['Excellent, exactly as described.', 'Great quality, fast shipping. Recommended!', 'Love it, would buy again.'],
            4 => ['Very good product, minor packaging issue.', 'Good value for money.'],
            3 => ['Okay, does the job.', 'Average, expected a bit more.'],
            2 => ['Not great, quality could be better.'],
        ];
        $reviewed = [];
        $reviewCount = 0;
        foreach ($purchases as [$b, $product]) {
            $key = $b.'-'.spl_object_id($product);
            if (isset($reviewed[$key]) || mt_rand(1, 10) > 6) {
                continue;
            }
            $reviewed[$key] = true;
            $rating = [5, 5, 4, 4, 3, 2][mt_rand(0, 5)];
            $review = (new Review($product, $buyers[$b]))
                ->setRating($rating)
                ->setComment($comments[$rating][mt_rand(0, count($comments[$rating]) - 1)]);
            $manager->persist($review);
            ++$reviewCount;
        }

        // --- Cart items for buyer1 ------------------------------------------------------------------------
        $manager->persist(new CartItem($buyers[0], $products[2]['entity'], 1));
        $manager->persist(new CartItem($buyers[0], $products[11]['entity'], 2));

        $manager->flush();

        $out->writeln(sprintf('<info>%d orders, %d reviews, 2 cart items created.</info>', $orderCount, $reviewCount));
        $out->writeln('');
        $out->writeln('<comment>Demo accounts (DEV ONLY), password for all: '.self::PASSWORD.'</comment>');
        $out->writeln('  ADMIN   admin@shop.test');
        foreach (self::SELLERS as [$email, $name]) {
            $out->writeln(sprintf('  SELLER  %-20s (%s)', $email, $name));
        }
        foreach (self::BUYERS as [$email, $name]) {
            $out->writeln(sprintf('  BUYER   %-20s (%s)%s', $email, $name, 'buyer1@shop.test' === $email ? ' has 2 items in cart' : ('buyer5@shop.test' === $email ? ' has no orders' : '')));
        }
    }

    private function user(string $email, string $name, string $role): User
    {
        $user = (new User())->setEmail($email)->setRoles([$role])->setIsVerified(true)->setDisplayName($name);
        // SECURITY: real Argon2id hash, one per user (unique salt), never the plain password.
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));

        return $user;
    }

    /**
     * Draws a simple placeholder with GD and stores it through the normal upload validation.
     */
    private function placeholderImage(string $name, string $category, int $i): string
    {
        $img = imagecreatetruecolor(800, 600);
        [$r, $g, $b] = self::COLORS[$category];
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        // A few translucent circles so images aren't all flat.
        for ($c = 0; $c < 6; ++$c) {
            $col = imagecolorallocatealpha($img, 255, 255, 255, 100 + mt_rand(0, 20));
            $d = mt_rand(120, 360);
            imagefilledellipse($img, mt_rand(0, 800), mt_rand(0, 600), $d, $d, $col);
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $label = wordwrap($name, 22, "\n", true);
        if (is_file($this->fontPath)) {
            imagettftext($img, 34, 0, 50, 260, $white, $this->fontPath, $label);
            imagettftext($img, 20, 0, 50, 540, $white, $this->fontPath, strtoupper(Product::CATEGORIES[$category]));
        } else {
            imagestring($img, 5, 50, 280, $name, $white);
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'fx');
        // Alternate formats so jpg, png and webp are all represented.
        match ($i % 3) {
            0 => imagejpeg($img, $tmp, 85),
            1 => imagepng($img, $tmp),
            default => imagewebp($img, $tmp, 85),
        };
        imagedestroy($img);

        try {
            return $this->images->validateAndStore($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Fixtures only: back-date entities (there is deliberately no public setter for createdAt). */
    private function setCreatedAt(object $entity, \DateTimeImmutable $date): void
    {
        $prop = new \ReflectionProperty($entity, 'createdAt');
        $prop->setValue($entity, $date);
    }
}
