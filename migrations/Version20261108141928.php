<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108141928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT NOT NULL, price NUMERIC(10, 2) NOT NULL, stock INT NOT NULL, category VARCHAR(32) NOT NULL, image_filename VARCHAR(64) DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, seller_id INT NOT NULL, UNIQUE INDEX UNIQ_D34A04ADD17F50A6 (uuid), INDEX idx_product_active_created (is_active, created_at), INDEX IDX_D34A04AD8DE820D9 (seller_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD8DE820D9 FOREIGN KEY (seller_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD8DE820D9');
        $this->addSql('DROP TABLE product');
    }
}
