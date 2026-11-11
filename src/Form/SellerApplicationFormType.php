<?php

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
            ->add('agreeSellerTerms', CheckboxType::class, [
                'label' => 'I confirm the information is accurate and I accept the seller terms',
                'mapped' => false,
                'constraints' => [new Assert\IsTrue(message: 'Please accept the seller terms.')],
            ])
        ;
    }

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
