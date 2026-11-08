<?php

namespace App\Form;

use App\Entity\Review;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 9): only rating + comment. Author, product and date are set by the server.
 */
class ReviewFormType extends AbstractType
{
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
