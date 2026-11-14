<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tunisian dinar: every money column becomes DECIMAL(…, 3). Generated with make:migration.
 */
final class Version20261114110903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Currency TND: money columns (product price / compare-at price, order total, order item unit price) get 3 decimals (millimes). Existing amounts are kept as they are (no conversion rate applied).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE order_item CHANGE unit_price unit_price NUMERIC(10, 3) NOT NULL');
        $this->addSql('ALTER TABLE orders CHANGE total total NUMERIC(12, 3) NOT NULL');
        $this->addSql('ALTER TABLE product CHANGE price price NUMERIC(10, 3) NOT NULL, CHANGE compare_at_price compare_at_price NUMERIC(10, 3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders CHANGE total total NUMERIC(12, 2) NOT NULL');
        $this->addSql('ALTER TABLE order_item CHANGE unit_price unit_price NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE product CHANGE price price NUMERIC(10, 2) NOT NULL, CHANGE compare_at_price compare_at_price NUMERIC(10, 2) DEFAULT NULL');
    }
}
