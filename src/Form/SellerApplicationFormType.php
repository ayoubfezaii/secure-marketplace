<?php

/**
 * FILE: src/Form/SellerApplicationFormType.php
 * WHAT: the "become a seller" form (shop name, description, accept the seller terms).
 * ROLE IN THE PROJECT: the buyer can describe their project but cannot set the status of the application.
 * USED BY: Buyer\SellerApplicationController (buyer).
 */

namespace App\Form;

use App\Entity\SellerApplication;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SECURITY (rule 9): only the shop name and description. Applicant, status, reviewer, dates and reason are set by
 * the server; submitting them is an "extra field" error.
 *
 * @extends AbstractType<SellerApplication>
 */
class SellerApplicationFormType extends AbstractType
{
    /**
     * Declares the fields of the form.
     *
     * @param FormBuilderInterface<SellerApplication|null> $builder object used to add fields
     * @param array<string, mixed> $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shopName', TextType::class, [
                'label' => 'Shop name',
                'attr' => ['maxlength' => 80, 'placeholder' => 'e.g. Atelier Lumière'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'What will you sell?',
                'help' => 'Tell us about your products, how they are made and where you ship from (30 to 2000 characters).',
                'attr' => ['rows' => 6, 'maxlength' => 2000],
            ])
            // Checkbox that must be ticked. Not stored (mapped => false).
            ->add('agreeSellerTerms', CheckboxType::class, [
                'label' => 'I confirm the information is accurate and I accept the seller terms',
                'mapped' => false,
                'constraints' => [new Assert\IsTrue(message: 'Please accept the seller terms.')],
            ])
        ;
    }

    /** Settings of the form: the class it fills (SellerApplication) and the name of its CSRF token. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SellerApplication::class,
            'empty_data' => null,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'seller_application',
        ]);
    }
}
