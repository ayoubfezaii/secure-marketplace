<?php

/**
 * FILE: src/Entity/WishlistItem.php
 * WHAT: the WishlistItem entity = one product saved in a buyer's wishlist.
 * ROLE IN THE PROJECT: "save for later" feature (the heart on product cards).
 * USED BY: WishlistController (add / remove, buyer) and Buyer\WishlistController (wishlist page, buyer).
 */

namespace App\Entity;

use App\Repository\WishlistItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product saved by a buyer. Like the cart, it has no id in any URL: lines are always looked up by
 * (logged-in user, product uuid), so a buyer can never touch another buyer's wishlist (rule 4, IDOR).
 */
#[ORM\Entity(repositoryClass: WishlistItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_wishlist_user_product', columns: ['user_id', 'product_id'])]
class WishlistItem
{
    // Internal auto-increment primary key. Never used in URLs.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Owner of the wishlist line (the logged-in buyer).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // The saved product. CASCADE: the line disappears if the product is deleted.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // When the product was saved.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param User    $user    the logged-in buyer
     * @param Product $product the product to save
     */
    public function __construct(User $user, Product $product)
    {
        $this->user = $user;
        $this->product = $product;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
