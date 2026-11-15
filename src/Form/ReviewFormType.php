<?php

/**
 * FILE: src/Form/ReviewFormType.php
 * WHAT: the form of a product review (stars + comment).
 * ROLE IN THE PROJECT: explicit field list for reviews; the comment is plain text escaped at display (XSS defence).
 * USED BY: ReviewController::new (product page) and Buyer\ReviewController::edit (buyer).
 */

namespace App\Form;

use App\Entity\Review;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 9): only rating + comment. Author, product and date are set by the server.
 *
 * @extends AbstractType<Review>
 */
class ReviewFormType extends AbstractType
{
    /**
     * Declares the two fields of the form.
     *
     * @param FormBuilderInterface $builder object used to add fields
     * @param array                $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating', ChoiceType::class, [
                'label' => 'Rating',
                // SECURITY: whitelist 1..5 (and Range on the entity).
                'choices' => ['★★★★★ (5)' => 5, '★★★★ (4)' => 4, '★★★ (3)' => 3, '★★ (2)' => 2, '★ (1)' => 1],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Your review',
                'help' => 'Plain text, max 1000 characters.',
                'attr' => ['rows' => 4, 'maxlength' => 1000],
            ])
        ;
    }

    /** Settings of the form: the class it fills (Review) and the name of its CSRF token. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Review::class,
            'empty_data' => null,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'review',
        ]);
    }
}
