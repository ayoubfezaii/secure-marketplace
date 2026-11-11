<?php

namespace App\Entity;

use App\Repository\ProductImageRepository;
use App\Service\ImageStorage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One image of a product (max Product::MAX_IMAGES). The file itself is stored by ImageStorage outside public/
 * under a random name and served by MediaController (rule 7).
 */
#[ORM\Entity(repositoryClass: ProductImageRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_product_image_filename', columns: ['filename'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // SECURITY: only names produced by ImageStorage (32 hex + whitelisted extension) are accepted.
    #[ORM\Column(length: 64)]
    #[Assert\Regex(pattern: '/^'.ImageStorage::FILENAME_PATTERN.'$/')]
    private string $filename;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 3)]
    private int $position;

    public function __construct(Product $product, string $filename, int $position)
    {
        if (!ImageStorage::isValidFilename($filename)) {
            throw new \InvalidArgumentException('Invalid stored image name.');
        }
        $this->product = $product;
        $this->filename = $filename;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
