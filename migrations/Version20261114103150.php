<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cash on delivery: payment method + payment status on orders.
 * Generated with make:migration; the data statements below were added so existing orders stay consistent.
 */
final class Version20261114103150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orders: payment_method (card | cash_on_delivery) and payment_status (paid | pending | collected | refunded); card columns nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD payment_method VARCHAR(20) NOT NULL, ADD payment_status VARCHAR(20) NOT NULL, CHANGE card_holder card_holder VARCHAR(100) DEFAULT NULL, CHANGE card_last4 card_last4 VARCHAR(4) DEFAULT NULL');
        // Every order placed before this feature was a (simulated) card payment, charged at checkout.
        $this->addSql("UPDATE orders SET payment_method = 'card', payment_status = 'paid'");
        // Orders already cancelled or refunded gave the money back: "refunded", like the application now records it.
        $this->addSql("UPDATE orders SET payment_status = 'refunded' WHERE status IN ('cancelled', 'refunded')");
    }

    public function down(Schema $schema): void
    {
        // Cash orders have no card data and use the "placed" status: give them neutral values before the columns
        // become NOT NULL again and the status disappears from the application.
        $this->addSql("UPDATE orders SET card_holder = '' WHERE card_holder IS NULL");
        $this->addSql("UPDATE orders SET card_last4 = '0000' WHERE card_last4 IS NULL");
        $this->addSql("UPDATE orders SET status = 'paid' WHERE status = 'placed'");
        $this->addSql("UPDATE order_item SET status = 'paid' WHERE status = 'placed'");
        $this->addSql('ALTER TABLE orders DROP payment_method, DROP payment_status, CHANGE card_holder card_holder VARCHAR(100) NOT NULL, CHANGE card_last4 card_last4 VARCHAR(4) NOT NULL');
    }
}
