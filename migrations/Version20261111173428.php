<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111173428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Redesign R2: categories, product images, shops, wishlist, addresses, newsletter, order shipping snapshot';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE address (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, label VARCHAR(40) NOT NULL, full_name VARCHAR(100) NOT NULL, line1 VARCHAR(120) NOT NULL, line2 VARCHAR(120) DEFAULT NULL, postal_code VARCHAR(12) NOT NULL, city VARCHAR(80) NOT NULL, country VARCHAR(2) NOT NULL, phone VARCHAR(20) DEFAULT NULL, is_default TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_D4E6F81D17F50A6 (uuid), INDEX IDX_D4E6F81A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, slug VARCHAR(60) NOT NULL, icon VARCHAR(40) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, tagline VARCHAR(160) DEFAULT NULL, UNIQUE INDEX uniq_category_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE newsletter_subscriber (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_newsletter_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_image (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(64) NOT NULL, position SMALLINT NOT NULL, product_id INT NOT NULL, UNIQUE INDEX uniq_product_image_filename (filename), INDEX IDX_64617F034584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shop (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, slug VARCHAR(60) NOT NULL, description LONGTEXT DEFAULT NULL, logo_filename VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, UNIQUE INDEX uniq_shop_slug (slug), UNIQUE INDEX uniq_shop_owner (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wishlist_item (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, product_id INT NOT NULL, UNIQUE INDEX uniq_wishlist_user_product (user_id, product_id), INDEX IDX_6424F4E8A76ED395 (user_id), INDEX IDX_6424F4E84584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE address ADD CONSTRAINT FK_D4E6F81A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_image ADD CONSTRAINT FK_64617F034584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shop ADD CONSTRAINT FK_AC6A4CA27E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wishlist_item ADD CONSTRAINT FK_6424F4E8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wishlist_item ADD CONSTRAINT FK_6424F4E84584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders ADD shipping_name VARCHAR(100) DEFAULT NULL, ADD shipping_line1 VARCHAR(120) DEFAULT NULL, ADD shipping_line2 VARCHAR(120) DEFAULT NULL, ADD shipping_postal_code VARCHAR(12) DEFAULT NULL, ADD shipping_city VARCHAR(80) DEFAULT NULL, ADD shipping_country VARCHAR(2) DEFAULT NULL');
        // --- Data migration (hand-added after make:migration): existing rows are converted, nothing is lost. ---
        // 1. The old whitelisted category strings become Category rows (same slugs).
        $this->addSql("INSERT INTO category (name, slug, icon, position) VALUES
            ('Electronics', 'electronics', 'headphones', 1), ('Books', 'books', 'book', 2), ('Clothing', 'clothing', 'handbag', 3),
            ('Home & Garden', 'home', 'lamp', 4), ('Sports', 'sports', 'bicycle', 5), ('Toys', 'toys', 'puzzle', 6), ('Other', 'other', 'tag', 7)");
        $this->addSql('ALTER TABLE product ADD compare_at_price NUMERIC(10, 2) DEFAULT NULL, ADD rating_average NUMERIC(3, 2) DEFAULT \'0.00\' NOT NULL, ADD rating_count INT DEFAULT 0 NOT NULL, ADD category_id INT DEFAULT NULL');
        $this->addSql('UPDATE product p SET p.category_id = (SELECT c.id FROM category c WHERE c.slug = p.category)');
        $this->addSql("UPDATE product p SET p.category_id = (SELECT c.id FROM category c WHERE c.slug = 'other') WHERE p.category_id IS NULL");
        // 2. The single image becomes the first ProductImage.
        $this->addSql('INSERT INTO product_image (product_id, filename, position) SELECT id, image_filename, 0 FROM product WHERE image_filename IS NOT NULL');
        // 3. Denormalised rating from the existing reviews.
        $this->addSql('UPDATE product p SET p.rating_count = (SELECT COUNT(*) FROM review r WHERE r.product_id = p.id), p.rating_average = COALESCE((SELECT ROUND(AVG(r.rating), 2) FROM review r WHERE r.product_id = p.id), 0)');
        // 4. Every existing seller gets a shop (unique slug derived from the id).
        $this->addSql("INSERT INTO shop (owner_id, name, slug, description, created_at) SELECT u.id, COALESCE(u.display_name, CONCAT('Shop ', u.id)), CONCAT('shop-', u.id), NULL, NOW() FROM `user` u WHERE u.roles LIKE '%\"ROLE_SELLER\"%'");
        $this->addSql('ALTER TABLE product MODIFY category_id INT NOT NULL, DROP category, DROP image_filename');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
    }

    public function down(Schema $schema): void
    {
        // Reverse data migration (hand-written): the category slug and the first image are copied back into the
        // old product columns BEFORE the new tables are dropped. Slugs that didn't exist in the old whitelist fall
        // back to "other".
        $this->addSql('ALTER TABLE product ADD category VARCHAR(32) DEFAULT NULL, ADD image_filename VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE product p SET p.category = COALESCE((SELECT c.slug FROM category c WHERE c.id = p.category_id AND c.slug IN ('electronics', 'books', 'clothing', 'home', 'sports', 'toys', 'other')), 'other')");
        $this->addSql('UPDATE product p SET p.image_filename = (SELECT i.filename FROM product_image i WHERE i.product_id = p.id ORDER BY i.position ASC LIMIT 1)');
        $this->addSql('ALTER TABLE product MODIFY category VARCHAR(32) NOT NULL');

        $this->addSql('ALTER TABLE address DROP FOREIGN KEY FK_D4E6F81A76ED395');
        $this->addSql('ALTER TABLE product_image DROP FOREIGN KEY FK_64617F034584665A');
        $this->addSql('ALTER TABLE shop DROP FOREIGN KEY FK_AC6A4CA27E3C61F9');
        $this->addSql('ALTER TABLE wishlist_item DROP FOREIGN KEY FK_6424F4E8A76ED395');
        $this->addSql('ALTER TABLE wishlist_item DROP FOREIGN KEY FK_6424F4E84584665A');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD12469DE2');
        $this->addSql('DROP INDEX IDX_D34A04AD12469DE2 ON product');
        $this->addSql('ALTER TABLE product DROP compare_at_price, DROP rating_average, DROP rating_count, DROP category_id');
        $this->addSql('DROP TABLE address');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE newsletter_subscriber');
        $this->addSql('DROP TABLE product_image');
        $this->addSql('DROP TABLE shop');
        $this->addSql('DROP TABLE wishlist_item');
        $this->addSql('ALTER TABLE orders DROP shipping_name, DROP shipping_line1, DROP shipping_line2, DROP shipping_postal_code, DROP shipping_city, DROP shipping_country');
    }
}
