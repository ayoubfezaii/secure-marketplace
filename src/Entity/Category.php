<?php

/**
 * FILE: src/Entity/Category.php
 * WHAT: the Category entity = one product category (one row of the `category` table).
 * ROLE IN THE PROJECT: groups products for the catalogue filters and the home page tiles.
 * USED BY: Admin\CategoryController (admin creates / edits / deletes), ProductFormType (seller picks one),
 *   the catalogue and the home page (everyone).
 */

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Product category, managed by admins (replaces the old hard-coded category string).
 *
 * The slug is the public identifier in URLs (/products?category=books). It is not secret (categories are public),
 * and it is strictly validated, so it can never carry markup or path characters.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'], message: 'This slug is already used by another category.')]
class Category
{
    /**
     * SECURITY (rule 15): the icon is chosen from this fixed list of Bootstrap Icons names. It is printed inside a
     * class attribute, so a free-text value could otherwise break out of the attribute.
     */
    public const ICONS = [
        'bag', 'basket', 'gem', 'watch', 'headphones', 'laptop', 'phone', 'camera', 'controller', 'book', 'journal',
        'house', 'lamp', 'cup-hot', 'flower1', 'tree', 'bicycle', 'dribbble', 'trophy', 'heart-pulse', 'brush',
        'palette', 'music-note-beamed', 'puzzle', 'balloon', 'gift', 'scissors', 'droplet', 'sun', 'stars', 'tag',
        'box-seam', 'handbag', 'backpack', 'eyeglasses', 'shop',
    ];

    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Name shown to users.
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 60)]
    private string $name = '';

    // SECURITY (rule 15): lowercase letters, digits and single dashes only.
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 60)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Use lowercase letters, numbers and dashes only (e.g. "home-decor").')]
    private string $slug = '';

    // SECURITY (rule 15): whitelist (see ICONS).
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::ICONS)]
    private string $icon = 'tag';

    // Display order in menus and tiles (small numbers first).
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    #[Assert\Range(min: 0, max: 999)]
    private int $position = 0;

    // Optional short sentence shown under the category name.
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $tagline = null;

    // ----- Getters and setters. -----

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /** The slug is always stored in lower case. */
    public function setSlug(?string $slug): static
    {
        $this->slug = mb_strtolower(trim((string) $slug));

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = (string) $icon;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = (int) $position;

        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        $tagline = null !== $tagline ? trim($tagline) : null;
        $this->tagline = '' === $tagline ? null : $tagline;

        return $this;
    }

    /** Lets forms and templates print a category directly as its name. */
    public function __toString(): string
    {
        return $this->name;
    }
}
