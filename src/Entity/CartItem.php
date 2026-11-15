<?php

/**
 * FILE: src/Entity/CartItem.php
 * WHAT: the CartItem entity = one product in a buyer's cart (one row of the `cart_item` table).
 * ROLE IN THE PROJECT: the cart is stored in the database (not in the session), one line per (user, product).
 * USED BY: CartController (buyer cart page) and CheckoutService (turns the cart into an order).
 */

namespace App\Entity;

use App\Service\Money;
use App\Repository\CartItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A line in a buyer's cart. It deliberately has NO price column: the price is always read from Product at
 * display and checkout time, so there is no stored price that a client could tamper with (rule 10).
 */
#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_cart_user_product', columns: ['user_id', 'product_id'])]
class CartItem
{
    // Highest quantity allowed for one product in the cart.
    public const MAX_QUANTITY = 99;

    // Internal auto-increment primary key. Never used in URLs.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Owner of the cart line (the logged-in buyer).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // The product put in the cart. CASCADE: if the product is deleted, the cart line disappears.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // SECURITY: range 1..99 (rule 15); negative or zero quantities would create negative totals.
    #[ORM\Column]
    #[Assert\Range(min: 1, max: self::MAX_QUANTITY)]
    private int $quantity;

    // When the product was added to the cart.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a cart line.
     *
     * @param User    $user     the logged-in buyer
     * @param Product $product  the product to add
     * @param int     $quantity wanted quantity (forced into 1..99 by setQuantity())
     */
    public function __construct(User $user, Product $product, int $quantity)
    {
        $this->user = $user;
        $this->product = $product;
        $this->setQuantity($quantity);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /** Changes the quantity. The value is forced between 1 and MAX_QUANTITY. */
    public function setQuantity(int $quantity): void
    {
        // SECURITY: clamped in the entity too, whatever the caller does.
        $this->quantity = max(1, min(self::MAX_QUANTITY, $quantity));
    }

    /** Line total from the CURRENT product price in the database. */
    public function getLineTotal(): string
    {
        return bcmul($this->product->getPrice(), (string) $this->quantity, Money::SCALE);
    }
}
