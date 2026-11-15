<?php

/**
 * FILE: src/Form/CategoryFormType.php
 * WHAT: the form of a product category (name, slug, tagline, icon, position).
 * ROLE IN THE PROJECT: explicit field list for the category pages; validation rules are on the Category entity.
 * USED BY: Admin\CategoryController (admin).
 */

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY: mass assignment - only the 5 fields below can be set through this form.
 *
 * @extends AbstractType<Category>
 */
class CategoryFormType extends AbstractType
{
    /**
     * Declares the fields of the form.
     *
     * @param FormBuilderInterface<Category|null> $builder object used to add fields
     * @param array<string, mixed> $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'attr' => ['maxlength' => 60]])
            ->add('slug', TextType::class, ['label' => 'Slug', 'help' => 'Used in URLs: lowercase letters, numbers and dashes.', 'attr' => ['maxlength' => 60, 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*']])
            ->add('tagline', TextType::class, ['label' => 'Tagline', 'required' => false, 'attr' => ['maxlength' => 160]])
            // SECURITY (rule 15): icon chosen from a fixed list of Bootstrap Icons names.
            ->add('icon', ChoiceType::class, ['label' => 'Icon', 'choices' => array_combine(Category::ICONS, Category::ICONS)])
            ->add('position', IntegerType::class, ['label' => 'Position', 'help' => 'Lower numbers come first.', 'attr' => ['min' => 0, 'max' => 999]])
        ;
    }

    /** Settings of the form: the class it fills (Category) and the name of its CSRF token. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'category',
        ]);
    }
}
