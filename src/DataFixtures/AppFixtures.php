<?php

namespace App\DataFixtures;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Category;
use App\Entity\NewsletterSubscriber;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\ReviewReply;
use App\Entity\SellerApplication;
use App\Entity\Shop;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enum\PaymentMethod;
use App\Service\ImageStorage;
use App\Service\PlaceholderImageGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data for the redesigned marketplace: php bin/console doctrine:fixtures:load -n
 *
 * 1 admin, 4 sellers (each with a shop), 8 buyers, 8 categories, 40 products with 1-4 generated images, addresses,
 * wishlists, 60 orders in every status over the last 60 days (so the charts look real), reviews + seller replies,
 * 3 pending + 1 rejected seller applications, newsletter subscribers and an audit-log history (including failed
 * logins, a lockout, blocked SSRF and upload attempts) for the security center.
 *
 * SECURITY notes:
 *  - DEV/DEMO ONLY. Every account shares one known password; never load this in production.
 *  - Passwords are hashed with the real Argon2id hasher (one hash per user, unique salts).
 *  - Images are generated with GD and then go through ImageStorage::validateAndStore(), exactly like an upload.
 *  - Order prices are snapshots of the product price, like CheckoutService does; only "last 4" card digits.
 *  - Attacker IPs in the audit log use the documentation ranges (RFC 5737), never real addresses.
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'Password2026!';
    /** Well-known demo TOTP secret for seller4 (DEV ONLY) so the 2FA login can be tested with any authenticator. */
    public const DEMO_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    /** slug => [name, icon, tagline, placeholder shape] */
    private const CATEGORIES = [
        'clothing' => ['Clothing', 'scissors', 'Natural fibres, timeless cuts', 'diamond'],
        'bags' => ['Bags & Accessories', 'handbag', 'Carry beautifully, every day', 'bag'],
        'jewellery' => ['Jewellery', 'gem', 'Small pieces, big meaning', 'ring'],
        'home' => ['Home & Living', 'lamp', 'Calm, warm interiors', 'arch'],
        'ceramics' => ['Ceramics & Tableware', 'cup-hot', 'Thrown and glazed by hand', 'vase'],
        'beauty' => ['Beauty & Wellness', 'droplet', 'Slow rituals, clean formulas', 'wave'],
        'books' => ['Books & Stationery', 'book', 'Paper goods to keep', 'book'],
        'tech' => ['Tech & Audio', 'headphones', 'Quiet, well-made gadgets', 'device'],
    ];

    /** [email, display name, shop name, shop description] */
    private const SELLERS = [
        ['seller1@shop.test', 'Camille Rousseau', 'Atelier Lumière', "Linen, wool and leather pieces cut in small batches in our Lyon workshop.\nWe favour natural fibres and repairable designs."],
        ['seller2@shop.test', 'Hugo Marchand', 'Maison Argile', 'Stoneware and porcelain thrown by hand, glazed with our own recipes, fired in a gas kiln near Bordeaux.'],
        ['seller3@shop.test', 'Inès Fontaine', 'Papeterie Nord', 'Notebooks, books and slow-living rituals: recycled papers, cotton covers and plant-based care.'],
        ['seller4@shop.test', 'Malik Benali', 'Sonic Craft', 'Minimal audio gear and fine jewellery made from recycled silver. Repairs offered for life.'],
    ];

    /** [email, display name] */
    private const BUYERS = [
        ['buyer1@shop.test', 'Alice Martin'], ['buyer2@shop.test', 'Bob Dupont'], ['buyer3@shop.test', 'Chloé Bernard'],
        ['buyer4@shop.test', 'David Leroy'], ['buyer5@shop.test', 'Emma Petit'], ['buyer6@shop.test', 'Farid Haddad'],
        ['buyer7@shop.test', 'Giulia Rossi'], ['buyer8@shop.test', 'Hannah Weber'],
    ];

    /** [name, category, price in DT (3 decimals), compare-at price|null, final stock, seller index, description] — 40 products.
     *  Tunisian price levels: a mug 28 DT, a shirt 89 DT, a leather bag 180 DT, headphones 320 DT, a gold signet ring 1 800 DT. */
    private const PRODUCTS = [
        ['Linen Wrap Dress', 'clothing', '189.000', '239.000', 12, 0, "Washed French linen, adjustable wrap waist and deep pockets.\nGets softer with every wash."],
        ['Merino Crew Sweater', 'clothing', '145.000', null, 18, 0, 'Fine-gauge merino from a mulesing-free farm. Breathable, warm, never itchy.'],
        ['Wide-Leg Wool Trousers', 'clothing', '165.000', null, 3, 0, 'High-waisted trousers in a soft wool twill, fully lined, with side buttons.'],
        ['Organic Cotton Shirt', 'clothing', '89.000', '119.000', 24, 0, 'Relaxed fit shirt in organic poplin with mother-of-pearl buttons.'],
        ['Quilted Barn Jacket', 'clothing', '320.000', null, 0, 0, 'Recycled-fill quilted jacket with corduroy collar. Currently sold out.'],
        ['Leather Tote Bag', 'bags', '180.000', null, 9, 0, 'Vegetable-tanned leather tote that ages into a rich patina. Fits a 14" laptop.'],
        ['Canvas Weekender', 'bags', '210.000', '260.000', 7, 0, 'Waxed canvas holdall with leather handles and a separate shoe pocket.'],
        ['Woven Raffia Basket Bag', 'bags', '65.000', null, 15, 0, 'Hand-woven raffia with leather straps. Perfect for markets and beaches.'],
        ['Silk Twill Scarf', 'bags', '95.000', null, 20, 0, 'Pure silk twill, hand-rolled edges, printed with an original botanical pattern.'],
        ['Leather Card Holder', 'bags', '45.000', null, 40, 0, 'Slim card holder with four slots, saddle-stitched by hand.'],
        ['Speckled Stoneware Mug', 'ceramics', '28.000', null, 36, 1, 'Wheel-thrown mug in speckled clay with a satin oatmeal glaze. 350 ml.'],
        ['Hand-thrown Serving Bowl', 'ceramics', '72.000', '89.000', 8, 1, 'Generous salad bowl with a glossy celadon interior and raw clay foot.'],
        ['Porcelain Pour-Over Set', 'ceramics', '135.000', null, 2, 1, 'Dripper, carafe and two cups in translucent porcelain.'],
        ['Stoneware Dinner Plates (x4)', 'ceramics', '120.000', null, 11, 1, 'Set of four 27 cm plates with an ash glaze. Dishwasher safe.'],
        ['Ribbed Bud Vase', 'ceramics', '38.000', null, 25, 1, 'Small ribbed vase for single stems, matt black glaze.'],
        ['Linen Bedding Set', 'home', '390.000', '480.000', 6, 1, 'Duvet cover and two pillowcases in stonewashed linen. Double size.'],
        ['Hand-poured Soy Candle', 'home', '35.000', null, 30, 1, 'Fig and cedar soy candle in a reusable ceramic vessel. 50 hours burn time.'],
        ['Wool Throw Blanket', 'home', '220.000', null, 4, 1, 'Herringbone throw woven from undyed wool. 130 x 180 cm.'],
        ['Oak Serving Board', 'home', '55.000', null, 14, 1, 'Solid oak board finished with food-safe oil. Each grain is unique.'],
        ['Rattan Pendant Lamp', 'home', '175.000', null, 5, 1, 'Hand-woven rattan shade, 45 cm diameter, E27 fitting.'],
        ['Dotted A5 Notebook', 'books', '18.500', null, 60, 2, '160 pages of 120 g recycled paper, lay-flat binding, cotton cover.'],
        ['The Art of Slow Living', 'books', '42.000', null, 22, 2, 'Essays on craft, attention and living with fewer, better things.'],
        ['Botanical Illustration Guide', 'books', '59.000', '75.000', 9, 2, 'A practical course in drawing plants, with 120 illustrations.'],
        ['Brass Fountain Pen', 'books', '110.000', null, 3, 2, 'Solid brass pen that develops a patina. Fine steel nib, converter included.'],
        ['Letterpress Card Set', 'books', '22.000', null, 45, 2, 'Eight cards and envelopes, printed on a 1960s letterpress.'],
        ['Rose & Oat Face Oil', 'beauty', '48.000', null, 19, 2, 'Cold-pressed rosehip and oat oil for sensitive skin. 30 ml glass bottle.'],
        ['Clay Body Polish', 'beauty', '32.500', '39.900', 16, 2, 'Kaolin and sugar body polish with sweet orange essential oil.'],
        ['Linen Bath Towel Set', 'beauty', '98.000', null, 8, 2, 'Two bath towels in quick-drying waffle linen.'],
        ['Cedar Bath Salts', 'beauty', '24.900', null, 27, 2, 'Magnesium-rich salts with cedar and lavender. 500 g.'],
        ['Konjac Facial Sponge', 'beauty', '12.500', null, 50, 2, 'Plant-based sponge for gentle daily cleansing.'],
        ['Walnut Bluetooth Speaker', 'tech', '280.000', '349.000', 7, 3, 'Compact speaker in a solid walnut cabinet. 20 hours of playback.'],
        ['Wireless Over-Ear Headphones', 'tech', '320.000', null, 10, 3, 'Active noise cancelling, memory-foam cushions and replaceable battery.'],
        ['Brass Desk Lamp', 'tech', '160.000', null, 4, 3, 'Dimmable LED lamp with a weighted brass base and USB-C charging.'],
        ['Leather Wireless Charger', 'tech', '79.000', null, 21, 3, 'Qi charging pad wrapped in vegetable-tanned leather.'],
        ['Analog Wall Clock', 'tech', '85.000', null, 12, 3, 'Silent sweep movement, ash-wood face, 30 cm.'],
        ['Recycled Silver Ring', 'jewellery', '150.000', null, 13, 3, 'Hammered band in 100% recycled sterling silver. Made to order in your size.'],
        ['Gold Vermeil Hoops', 'jewellery', '240.000', '300.000', 9, 3, 'Small hoops in 18k gold vermeil over recycled silver.'],
        ['Pearl Drop Necklace', 'jewellery', '290.000', null, 6, 3, 'A single freshwater pearl on a fine silver chain, 45 cm.'],
        ['Signet Pinky Ring', 'jewellery', '1800.000', null, 2, 3, 'Oval signet ring, can be engraved with up to three initials.'],
        ['Hand-stamped Cuff Bracelet', 'jewellery', '130.000', null, 11, 3, 'Open cuff in recycled silver, hand-stamped with a wave pattern.'],
    ];

    private const REVIEW_TEXTS = [
        5 => ['Absolutely beautiful, even better than the photos.', 'The quality is outstanding. Fast delivery and lovely packaging.', 'Exactly as described, I already ordered a second one as a gift.', 'You can feel the craftsmanship. Worth every dinar.'],
        4 => ['Very nice piece, the colour is slightly warmer than expected.', 'Great quality, delivery took a couple of extra days.', 'Lovely, would buy again from this shop.'],
        3 => ['Good, but a little smaller than I imagined.', 'Nice product, the packaging could be more eco-friendly.'],
        2 => ['Not quite what I expected, the finish has small marks.'],
    ];

    private const REPLIES = [
        'Thank you so much for your kind words! It means a lot to our small workshop.',
        'Thank you for your review. We are glad it arrived safely and hope you enjoy it for years.',
        'Thanks for the feedback! We have passed your note on packaging to the team.',
        'We are sorry it was not perfect. Please write to us, we would love to make it right.',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PlaceholderImageGenerator $generator,
        #[Autowire('%app.upload_dir%')]
        private readonly string $uploadDir,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        mt_srand(2026);
        $out = new ConsoleOutput();
        $this->purgeImages();
        $now = new \DateTimeImmutable();

        // ---------------------------------------------------------------- Users & shops
        $admin = $this->user('admin@shop.test', 'Site Admin', User::ROLE_ADMIN, '-120 days');
        $manager->persist($admin);

        $sellers = [];
        foreach (self::SELLERS as $i => [$email, $name, $shopName, $shopDescription]) {
            $seller = $this->user($email, $name, User::ROLE_SELLER, sprintf('-%d days', 110 - $i * 5));
            $manager->persist($seller);
            $shop = (new Shop($seller, $shopName, Shop::slugify($shopName)))->setDescription($shopDescription);
            $manager->persist($shop);
            $sellers[] = $seller;
        }
        $sellers[0]->setPhone('+33 6 12 34 56 78');
        // Seller4 has 2FA enabled with a well-known demo secret (DEV ONLY), to demonstrate the 2FA login.
        $sellers[3]->setTotpSecret(self::DEMO_TOTP_SECRET);
        $sellers[3]->enableTotp();

        $buyers = [];
        foreach (self::BUYERS as $i => [$email, $name]) {
            $buyer = $this->user($email, $name, User::ROLE_BUYER, sprintf('-%d days', 90 - $i * 9));
            $manager->persist($buyer);
            $buyers[] = $buyer;
        }
        // buyer8: blocked by an administrator (security center demo).
        $buyers[7]->block();

        // ---------------------------------------------------------------- Addresses
        $cities = [['Paris', '75011', 'FR'], ['Lyon', '69002', 'FR'], ['Bruxelles', '1000', 'BE'], ['Bordeaux', '33000', 'FR'], ['Genève', '1201', 'CH'], ['Lille', '59000', 'FR'], ['Nantes', '44000', 'FR'], ['Marseille', '13001', 'FR']];
        $streets = ['rue des Lilas', 'avenue Jean Jaurès', 'rue du Marché', 'boulevard Voltaire', 'rue de la Paix', 'quai des Brumes', 'place du Château', 'rue Victor Hugo'];
        $addresses = [];
        foreach ($buyers as $i => $buyer) {
            [$city, $zip, $country] = $cities[$i];
            $home = (new Address($buyer))->setLabel('Home')->setFullName($buyer->getDisplayName())->setLine1(sprintf('%d %s', 3 + $i * 7, $streets[$i]))
                ->setPostalCode($zip)->setCity($city)->setCountry($country)->setPhone('+33 6 00 00 00 '.str_pad((string) (10 + $i), 2, '0', \STR_PAD_LEFT))->setIsDefault(true);
            $manager->persist($home);
            $addresses[$i] = $home;
            if (0 === $i % 3) {
                $manager->persist((new Address($buyer))->setLabel('Office')->setFullName($buyer->getDisplayName())->setLine1('12 rue des Artisans')->setLine2('3rd floor')
                    ->setPostalCode('75011')->setCity('Paris')->setCountry('FR'));
            }
        }

        // ---------------------------------------------------------------- Categories
        $categories = [];
        $position = 1;
        foreach (self::CATEGORIES as $slug => [$name, $icon, $tagline]) {
            $categories[$slug] = (new Category())->setName($name)->setSlug($slug)->setIcon($icon)->setTagline($tagline)->setPosition($position++);
            $manager->persist($categories[$slug]);
        }

        // ---------------------------------------------------------------- Products (images through ImageStorage)
        $out->writeln('<comment>Generating product images (GD + upload validation pipeline)...</comment>');
        $products = [];
        $finalStock = [];
        foreach (self::PRODUCTS as $i => [$name, $cat, $price, $compareAt, $stock, $sellerIndex, $description]) {
            $product = (new Product($sellers[$sellerIndex]))
                ->setName($name)->setDescription($description)->setCategory($categories[$cat])
                ->setPrice($price)->setCompareAtPrice($compareAt)->setStock($stock + 50);
            $imageCount = 1 + ($i * 7) % 4; // 1..4 images
            for ($v = 0; $v < $imageCount; ++$v) {
                $product->addImage($this->generator->generate($name, self::CATEGORIES[$cat][3], $i, $v));
            }
            // Spread listings over the last 75 days; a few are brand new (NEW badge).
            $product->backdate($now->modify(sprintf('-%d days -%d hours', 0 === $i % 9 ? mt_rand(1, 10) : mt_rand(15, 75), mt_rand(0, 23))));
            $manager->persist($product);
            $products[] = $product;
            $finalStock[$i] = $stock;
        }
        // One hidden product (owner-only visibility / moderation demo).
        $products[24]->setIsActive(false);
        $out->writeln(sprintf('<info>%d products created.</info>', count($products)));

        // ---------------------------------------------------------------- Orders: 60 over the last 60 days
        $active = array_values(array_filter($products, static fn (Product $p) => $p->isActive()));
        $purchases = []; // [buyer index, product, order date]
        $orders = [];
        $forced = [
            // A few orders with a guaranteed status for the demos.
            0 => Order::STATUS_PAID, 1 => Order::STATUS_PAID, 2 => Order::STATUS_SHIPPED,
            40 => Order::STATUS_CANCELLED, 41 => Order::STATUS_CANCELLED, 42 => Order::STATUS_CANCELLED, 43 => Order::STATUS_CANCELLED,
            44 => Order::STATUS_REFUNDED, 45 => Order::STATUS_REFUNDED, 46 => Order::STATUS_REFUNDED,
        ];
        for ($o = 0; $o < 60; ++$o) {
            // buyer1 owns the first orders (paid -> cancellable demo); buyer8 (blocked) still has a history.
            $b = $o < 3 ? 0 : mt_rand(0, 7);
            $buyer = $buyers[$b];
            $age = $o < 3 ? $o : mt_rand(0, 59);
            $date = $now->modify(sprintf('-%d days -%d hours -%d minutes', $age, mt_rand(0, 12), mt_rand(0, 59)));

            $order = new Order($buyer, PaymentMethod::Card, (string) $buyer->getDisplayName(), (string) mt_rand(1000, 9999));
            $order->setShippingAddress($addresses[$b]);
            $order->backdate($date);

            $picked = [];
            foreach ((array) array_rand($active, mt_rand(1, 3)) as $idx) {
                $product = $active[$idx];
                if (in_array($product, $picked, true)) {
                    continue;
                }
                $picked[] = $product;
                $item = new OrderItem($order, $product, mt_rand(1, 2));
                $order->addItem($item);
            }

            $status = $forced[$o] ?? match (true) {
                $age < 3 => [Order::STATUS_PAID, Order::STATUS_PAID, Order::STATUS_SHIPPED][mt_rand(0, 2)],
                $age < 10 => [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED][mt_rand(0, 3)],
                default => [Order::STATUS_DELIVERED, Order::STATUS_DELIVERED, Order::STATUS_DELIVERED, Order::STATUS_SHIPPED][mt_rand(0, 3)],
            };
            $this->applyStatus($order, $status);
            $manager->persist($order);
            $orders[] = $order;

            if (in_array($order->getStatus(), [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED], true)) {
                foreach ($order->getItems() as $item) {
                    $purchases[] = [$b, $item->getProduct(), $date];
                }
            }
        }
        // ---------------------------------------------------------------- Cash on delivery: one order per state
        // Same rules as the real checkout: a single seller per order, total under the cash limit, and never more
        // than 2 pending cash orders per buyer.
        // [buyer index, seller index, age in days, state]
        $cashDemo = [
            [0, 0, 0, 'placed'],     // buyer1: can still cancel it; seller1 must ship it
            [1, 0, 2, 'shipped'],    // seller1 can mark it "Refused at delivery"
            [2, 0, 5, 'delivered'],  // seller1 can confirm "Cash collected" (or a refusal)
            [3, 1, 4, 'delivered'],  // same demo for seller2
            [4, 1, 3, 'shipped'],    // seller2: on its way, can be refused
            [3, 0, 12, 'collected'], // counted in seller1's revenue
            [4, 1, 20, 'collected'],
            [5, 0, 8, 'refused'],    // refused at delivery: cancelled, stock restored
            [1, 2, 6, 'cancelled'],  // cancelled by the buyer before shipping
        ];
        $cashOrders = 0;
        foreach ($cashDemo as $n => [$b, $sellerIndex, $age, $state]) {
            // First affordable active product of that seller (keeps every demo order under the cash limit).
            $candidates = array_values(array_filter($active, static fn (Product $p) => $p->getSeller() === $sellers[$sellerIndex] && (float) $p->getPrice() <= 400));
            if ([] === $candidates) {
                continue;
            }
            $product = $candidates[$n % count($candidates)];
            $date = $now->modify(sprintf('-%d days -%d hours -%d minutes', $age, 2 + $n, 7 * $n));

            $order = new Order($buyers[$b], PaymentMethod::CashOnDelivery);
            $order->setShippingAddress($addresses[$b]);
            $order->backdate($date);
            $order->addItem(new OrderItem($order, $product, 1));

            // The states are reached through the real state machine (same transitions as the application).
            if (in_array($state, ['shipped', 'refused'], true)) {
                $this->advanceAll($order, Order::STATUS_SHIPPED, false);
            } elseif (in_array($state, ['delivered', 'collected'], true)) {
                $this->advanceAll($order, Order::STATUS_DELIVERED, false);
            }
            if ('collected' === $state) {
                $order->markCashCollected();
            } elseif ('refused' === $state) {
                $order->markRefusedAtDelivery();
            } elseif ('cancelled' === $state) {
                $order->markCancelled();
            }
            $manager->persist($order);
            $orders[] = $order;
            ++$cashOrders;
        }
        $out->writeln(sprintf('<info>%d cash-on-delivery orders created (placed, shipped, delivered, collected, refused, cancelled).</info>', $cashOrders));

        // Stock values as declared (low-stock alerts, sold-out product).
        foreach ($products as $i => $product) {
            $product->setStock($finalStock[$i]);
        }
        $out->writeln(sprintf('<info>%d orders created.</info>', count($orders)));

        // ---------------------------------------------------------------- Reviews + seller replies
        $reviewed = [];
        $ratings = [];
        $reviewCount = 0;
        $replyCount = 0;
        foreach ($purchases as [$b, $product, $date]) {
            $key = $b.'-'.spl_object_id($product);
            if (isset($reviewed[$key]) || 7 === $b || mt_rand(1, 10) > 7) {
                continue;
            }
            $reviewed[$key] = true;
            $rating = [5, 5, 5, 4, 4, 4, 3, 2][mt_rand(0, 7)];
            $review = (new Review($product, $buyers[$b]))->setRating($rating)->setComment(self::REVIEW_TEXTS[$rating][mt_rand(0, count(self::REVIEW_TEXTS[$rating]) - 1)]);
            $review->backdate($date->modify(sprintf('+%d days', mt_rand(3, 8))) > $now ? $now : $date->modify(sprintf('+%d days', mt_rand(3, 8))));
            $manager->persist($review);
            $ratings[spl_object_id($product)][] = $rating;
            ++$reviewCount;

            if (0 === mt_rand(0, 1)) {
                $reply = (new ReviewReply($review, $product->getSeller()))->setBody(self::REPLIES[$rating >= 4 ? mt_rand(0, 1) : mt_rand(2, 3)]);
                $review->setReply($reply);
                $manager->persist($reply);
                ++$replyCount;
            }
        }
        // Denormalised rating (ProductRatingUpdater does the same from the DB in the app).
        foreach ($products as $product) {
            $r = $ratings[spl_object_id($product)] ?? [];
            $product->setRatingSummary([] === $r ? 0 : array_sum($r) / count($r), count($r));
        }

        // ---------------------------------------------------------------- Wishlists & carts
        foreach ([0, 1, 2, 3, 5] as $b) {
            foreach ((array) array_rand($active, 3 + $b % 3) as $idx) {
                $manager->persist(new WishlistItem($buyers[$b], $active[$idx]));
            }
        }
        $manager->persist(new CartItem($buyers[0], $products[10], 2));
        $manager->persist(new CartItem($buyers[0], $products[20], 1));

        // ---------------------------------------------------------------- Seller applications
        $pending = [
            [5, 'Les Bougies de Farid', 'Hand-poured candles and diffusers made with rapeseed wax and natural essential oils, from my workshop in Marseille.'],
            [6, 'Ceramica Giulia', 'Small-batch tableware inspired by Sicilian majolica, hand-painted plates, bowls and tiles.'],
            [2, 'Atelier Chloé', 'Upcycled denim bags and pouches, each one unique, sewn from second-hand jeans collected locally.'],
        ];
        foreach ($pending as $k => [$b, $shopName, $description]) {
            $application = (new SellerApplication($buyers[$b]))->setShopName($shopName)->setDescription($description);
            $application->backdate($now->modify(sprintf('-%d days', 1 + $k * 2)));
            $manager->persist($application);
        }
        $rejected = (new SellerApplication($buyers[4]))->setShopName('Quick Deals 24/7')->setDescription('I resell phone cases and accessories bought in bulk online, very cheap prices, fast shipping.');
        $rejected->backdate($now->modify('-20 days'));
        $rejected->reject($admin, 'Maison Secure only lists handmade or designer pieces. Resold mass-produced accessories are not eligible. You are welcome to apply again with your own creations.');
        $manager->persist($rejected);

        // ---------------------------------------------------------------- Newsletter
        foreach (['newsletter.fan@example.com', 'hello@example.org', 'buyer1@shop.test'] as $email) {
            $manager->persist(new NewsletterSubscriber($email));
        }

        $manager->flush();

        // ---------------------------------------------------------------- Audit history (security center demo)
        $this->auditHistory($manager, $admin, $sellers, $buyers, $orders, $now);
        $manager->flush();

        $out->writeln(sprintf('<info>%d reviews, %d seller replies, 4 seller applications, audit history created.</info>', $reviewCount, $replyCount));
        $this->printAccounts($out);
    }

    private function applyStatus(Order $order, string $status): void
    {
        match ($status) {
            Order::STATUS_CANCELLED => $order->markCancelled(),
            Order::STATUS_REFUNDED => $this->advanceAll($order, Order::STATUS_DELIVERED, true),
            default => $this->advanceAll($order, $status, false),
        };
    }

    private function advanceAll(Order $order, string $target, bool $refundAfter): void
    {
        $steps = match ($target) {
            Order::STATUS_SHIPPED => [Order::STATUS_SHIPPED],
            Order::STATUS_DELIVERED => [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED],
            default => [],
        };
        foreach ($order->getItems() as $item) {
            foreach ($steps as $step) {
                $item->advanceStatus($step);
            }
        }
        if ($refundAfter) {
            $order->markRefunded();
        }
    }

    /**
     * @param list<User>  $sellers
     * @param list<User>  $buyers
     * @param list<Order> $orders
     */
    private function auditHistory(ObjectManager $manager, User $admin, array $sellers, array $buyers, array $orders, \DateTimeImmutable $now): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $log = function (string $action, array $details, ?User $user, string $ip, \DateTimeImmutable $at, string $agent = '') use ($manager, $ua): void {
            $entry = new AuditLog($action, $details, $user, $ip, '' !== $agent ? $agent : $ua);
            (new \ReflectionProperty(AuditLog::class, 'createdAt'))->setValue($entry, $at);
            $manager->persist($entry);
        };
        $everyone = array_merge([$admin], $sellers, $buyers);

        // Registrations and normal logins.
        foreach ($everyone as $k => $user) {
            $log(AuditLog::REGISTER, ['email' => $user->getEmail()], $user, '192.0.2.'.(10 + $k), $user->getCreatedAt());
            for ($d = 0; $d < 4; ++$d) {
                $at = $now->modify(sprintf('-%d days -%d hours', mt_rand(0, 6), mt_rand(0, 23)));
                $log(AuditLog::LOGIN_SUCCESS, ['email' => $user->getEmail(), '2fa_pending' => $user->isTotpAuthenticationEnabled()], $user, '192.0.2.'.(10 + $k), $at);
            }
            $last = new \DateTimeImmutable(sprintf('-%d hours', mt_rand(1, 120)));
            (new \ReflectionProperty(User::class, 'lastLoginAt'))->setValue($user, $last);
        }

        // Orders placed.
        foreach ($orders as $order) {
            $log($order->isCashOnDelivery() ? AuditLog::CASH_ORDER_PLACED : AuditLog::ORDER_PLACED, ['order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'items' => $order->getItems()->count(), 'payment' => $order->getPaymentMethod()->value], $order->getBuyer(), '192.0.2.50', $order->getCreatedAt());
            // Cash on delivery outcome, confirmed by the seller of the order.
            if ($order->isCashOnDelivery() && 'collected' === $order->getPaymentStatus()->value) {
                $log(AuditLog::CASH_COLLECTED, ['order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'by' => 'seller'], $order->getItems()->first()->getSeller(), '192.0.2.11', $order->getCreatedAt()->modify('+3 days'));
            } elseif ($order->isCashOnDelivery() && Order::STATUS_CANCELLED === $order->getStatus() && $order->getCreatedAt() < $now->modify('-7 days')) {
                $log(AuditLog::CASH_REFUSED, ['order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'by' => 'seller'], $order->getItems()->first()->getSeller(), '192.0.2.11', $order->getCreatedAt()->modify('+3 days'));
            }
        }
        // Cash on delivery attempts blocked by the anti-abuse rules (security panel demo).
        $log(AuditLog::CASH_ORDER_BLOCKED, ['reason' => 'max_total', 'total' => '1800.000'], $buyers[5], '192.0.2.25', $now->modify('-1 day -2 hours'));
        $log(AuditLog::CASH_ORDER_BLOCKED, ['reason' => 'max_pending', 'total' => '89.000'], $buyers[3], '192.0.2.23', $now->modify('-3 hours'));
        $log(AuditLog::CASH_ORDER_BLOCKED, ['reason' => 'rate_limit'], $buyers[3], '192.0.2.23', $now->modify('-2 hours -55 minutes'));

        // Brute force from two "attacker" IPs (documentation ranges) against the admin and unknown emails.
        foreach (['203.0.113.7' => 26, '198.51.100.23' => 14, '203.0.113.99' => 6] as $ip => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $at = $now->modify(sprintf('-%d hours -%d minutes', mt_rand(0, 150), mt_rand(0, 59)));
                $target = 0 === $i % 3 ? 'admin@shop.test' : ['root@shop.test', 'test@shop.test', 'administrator@shop.test'][$i % 3];
                $log(AuditLog::LOGIN_FAILURE, ['email' => $target], 'admin@shop.test' === $target ? $admin : null, $ip, $at, 'python-requests/2.32');
            }
        }
        $log(AuditLog::RATE_LIMITED, ['limiter' => 'login'], null, '203.0.113.7', $now->modify('-3 hours'), 'python-requests/2.32');
        $log(AuditLog::LOGIN_BLOCKED, ['email' => 'admin@shop.test', 'reason' => 'TooManyLoginAttemptsAuthenticationException'], $admin, '203.0.113.7', $now->modify('-3 hours'), 'python-requests/2.32');

        // A buyer locked out after 5 failures, then blocked by the admin.
        for ($i = 0; $i < 5; ++$i) {
            $log(AuditLog::LOGIN_FAILURE, ['email' => $buyers[7]->getEmail()], $buyers[7], '198.51.100.77', $now->modify(sprintf('-2 days -%d minutes', 10 - $i)));
        }
        $log(AuditLog::ACCOUNT_LOCKED, ['email' => $buyers[7]->getEmail(), 'locked_until' => $now->modify('-2 days +15 minutes')->format(\DATE_ATOM)], $buyers[7], '198.51.100.77', $now->modify('-2 days'));
        $log(AuditLog::USER_LOCKED, ['target' => $buyers[7]->getEmail()], $admin, '192.0.2.10', $now->modify('-2 days +1 hour'));

        // Attacks blocked by the application.
        $log(AuditLog::SSRF_BLOCKED, ['url' => 'https://169.254.169.254/latest/meta-data/iam/security-credentials/', 'reason' => 'private_ip:169.254.169.254'], $sellers[3], '192.0.2.14', $now->modify('-1 day -4 hours'));
        $log(AuditLog::SSRF_BLOCKED, ['url' => 'http://127.0.0.1:8000/admin', 'reason' => 'scheme_not_https'], $sellers[3], '192.0.2.14', $now->modify('-1 day -3 hours'));
        $log(AuditLog::SSRF_BLOCKED, ['url' => 'https://2130706433/', 'reason' => 'invalid_hostname'], $sellers[1], '192.0.2.12', $now->modify('-5 hours'));
        $log(AuditLog::UPLOAD_REJECTED, ['source' => 'upload', 'client_names' => ['shell.php.jpg'], 'reason' => 'File type "text/x-php" is not allowed. Use JPG, PNG or WebP.'], $sellers[0], '192.0.2.11', $now->modify('-2 days -6 hours'));
        $log(AuditLog::UPLOAD_REJECTED, ['source' => 'upload', 'client_names' => ['bomb.png'], 'reason' => 'Image dimensions are too large.'], $sellers[2], '192.0.2.13', $now->modify('-6 hours'));
        $log(AuditLog::ACCESS_DENIED, ['path' => '/admin/users', 'method' => 'GET'], $buyers[1], '192.0.2.21', $now->modify('-1 day -2 hours'));
        $log(AuditLog::ACCESS_DENIED, ['path' => '/seller/products/'.$orders[0]->getUuid().'/edit', 'method' => 'GET'], $sellers[1], '192.0.2.12', $now->modify('-9 hours'));
        $log(AuditLog::TWO_FA_FAILURE, [], $sellers[3], '198.51.100.23', $now->modify('-7 hours'));
        $log(AuditLog::PASSWORD_RESET_INVALID, ['reason' => 'invalid_or_expired'], null, '203.0.113.99', $now->modify('-30 hours'));
        $log(AuditLog::APPLICATION_REJECTED, ['applicant' => $buyers[4]->getEmail()], $admin, '192.0.2.10', $now->modify('-19 days'));
    }

    private function user(string $email, string $name, string $role, string $created): User
    {
        $user = (new User())->setEmail($email)->setRoles([$role])->setIsVerified(true)->setDisplayName($name);
        // SECURITY: real Argon2id hash, one per user (unique salt), never the plain password.
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->backdate(new \DateTimeImmutable($created));

        return $user;
    }

    private function purgeImages(): void
    {
        // Old uploaded images are orphans once the product table is purged.
        $fs = new Filesystem();
        foreach (glob($this->uploadDir.'/*') ?: [] as $file) {
            if (ImageStorage::isValidFilename(basename($file))) {
                $fs->remove($file);
            }
        }
    }

    private function printAccounts(ConsoleOutput $out): void
    {
        $out->writeln('');
        $out->writeln('<comment>Demo accounts (DEV ONLY), password for all: '.self::PASSWORD.'</comment>');
        $out->writeln('  ROLE     EMAIL                NAME / SHOP                 NOTES');
        $out->writeln('  ADMIN    admin@shop.test      Site Admin');
        foreach (self::SELLERS as $i => [$email, $name, $shop]) {
            $out->writeln(sprintf('  SELLER   %-20s %-27s %s', $email, $shop, 3 === $i ? '2FA ON, TOTP secret '.self::DEMO_TOTP_SECRET : ''));
        }
        $notes = [
            0 => 'cart with 2 items, 2 orders "paid" (cancel demo), 1 cash order "placed", wishlist',
            3 => 'cash orders: 1 delivered (to collect), 1 collected',
            2 => 'pending seller application',
            4 => 'rejected seller application (reason shown)',
            5 => 'pending seller application',
            6 => 'pending seller application',
            7 => 'BLOCKED by admin (security center demo)',
        ];
        foreach (self::BUYERS as $i => [$email, $name]) {
            $out->writeln(sprintf('  BUYER    %-20s %-27s %s', $email, $name, $notes[$i] ?? ''));
        }
    }
}
