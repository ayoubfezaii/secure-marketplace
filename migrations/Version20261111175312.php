<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111175312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Redesign R5: seller applications, review replies, review edit date, last login';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE review_reply (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, review_id INT NOT NULL, author_id INT NOT NULL, UNIQUE INDEX UNIQ_EE26EAFBD17F50A6 (uuid), UNIQUE INDEX uniq_reply_review (review_id), INDEX IDX_EE26EAFBF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seller_application (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, shop_name VARCHAR(80) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(16) NOT NULL, reason VARCHAR(500) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, applicant_id INT NOT NULL, reviewed_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_9F30E76D17F50A6 (uuid), INDEX idx_application_status (status), INDEX IDX_9F30E7697139001 (applicant_id), INDEX IDX_9F30E76FC6B21F1 (reviewed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE review_reply ADD CONSTRAINT FK_EE26EAFB3E2E969B FOREIGN KEY (review_id) REFERENCES review (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE review_reply ADD CONSTRAINT FK_EE26EAFBF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seller_application ADD CONSTRAINT FK_9F30E7697139001 FOREIGN KEY (applicant_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seller_application ADD CONSTRAINT FK_9F30E76FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE review ADD updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD last_login_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE review_reply DROP FOREIGN KEY FK_EE26EAFB3E2E969B');
        $this->addSql('ALTER TABLE review_reply DROP FOREIGN KEY FK_EE26EAFBF675F31B');
        $this->addSql('ALTER TABLE seller_application DROP FOREIGN KEY FK_9F30E7697139001');
        $this->addSql('ALTER TABLE seller_application DROP FOREIGN KEY FK_9F30E76FC6B21F1');
        $this->addSql('DROP TABLE review_reply');
        $this->addSql('DROP TABLE seller_application');
        $this->addSql('ALTER TABLE review DROP updated_at');
        $this->addSql('ALTER TABLE `user` DROP last_login_at');
    }
}
