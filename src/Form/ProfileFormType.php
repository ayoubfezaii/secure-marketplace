<?php

namespace App\Form;

use App\Dto\ProfileDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 9): explicit fields, bound to ProfileDto (not to User). The IBAN fields exist only for sellers
 * (option is_seller, decided by the controller from the user's roles, never by the request).
 */
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'required' => false,
                'help' => 'Shown publicly on your reviews and products instead of your email.',
                'attr' => ['maxlength' => 60],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone',
                'required' => false,
                'attr' => ['maxlength' => 20, 'autocomplete' => 'tel'],
            ]);

        if ($options['is_seller']) {
            $builder
                ->add('iban', TextType::class, [
                    'label' => 'New IBAN (for payouts)',
                    'required' => false,
                    'help' => 'Stored encrypted. Leave empty to keep the current one.',
                    'attr' => ['maxlength' => 42, 'autocomplete' => 'off', 'spellcheck' => 'false'],
                ])
                ->add('removeIban', CheckboxType::class, [
                    'label' => 'Remove my IBAN',
                    'required' => false,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileDto::class,
            'is_seller' => false,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'profile',
        ]);
        $resolver->setAllowedTypes('is_seller', 'bool');
    }
}
