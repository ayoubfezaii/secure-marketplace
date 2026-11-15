<?php

/**
 * FILE: src/Form/ProfileFormType.php
 * WHAT: the "my profile" form (display name, phone).
 * ROLE IN THE PROJECT: example of the defence against MASS ASSIGNMENT with a DTO.
 * USED BY: ProfileController::profile (any logged-in user).
 */

namespace App\Form;

use App\Dto\ProfileDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 9): explicit fields, bound to ProfileDto (not to User).
 *
 * @extends AbstractType<ProfileDto>
 */
class ProfileFormType extends AbstractType
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
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'help' => 'Shown publicly on your reviews instead of your email. Your initials are used as your avatar.',
                'attr' => ['maxlength' => 60, 'autocomplete' => 'name'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone',
                'required' => false,
                'help' => 'Optional. Never shown to sellers or other users.',
                'attr' => ['maxlength' => 20, 'autocomplete' => 'tel'],
            ]);
    }

    /** Settings of the form: the DTO it fills (ProfileDto) and the name of its CSRF token. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileDto::class,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'profile',
        ]);
    }
}
