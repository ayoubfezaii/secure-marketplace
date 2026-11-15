<?php

/**
 * FILE: src/Form/ChangePasswordFormType.php
 * WHAT: the "change my password" form (current password + new password typed twice).
 * ROLE IN THE PROJECT: forces the user to prove they know the current password before changing it.
 * USED BY: ProfileController::changePassword and the security settings page (any logged-in user).
 */

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fields are "mapped => false": they are not copied to an entity. The controller reads the new password,
 * hashes it and stores the hash.
 *
 * @extends AbstractType<mixed>
 */
class ChangePasswordFormType extends AbstractType
{
    /**
     * Declares the fields of the form.
     *
     * @param FormBuilderInterface<mixed> $builder object used to add fields
     * @param array<string, mixed> $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // SECURITY (rule 5): re-authentication. A hijacked session alone can't change the password.
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(),
                    // UserPassword: Symfony constraint that checks the typed value against the hash of the logged-in user.
                    new UserPassword(message: 'Your current password is incorrect.'),
                ],
            ])
            // RepeatedType = two inputs that must contain the same value (avoids typing mistakes).
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The two passwords do not match.',
                'first_options' => ['label' => 'New password', 'help' => 'At least 12 characters, including letters and numbers.', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat new password', 'attr' => ['autocomplete' => 'new-password']],
                // SECURITY: same policy as everywhere (rule 15).
                'constraints' => PasswordPolicy::constraints(),
            ])
        ;
    }

    /**
     * Settings of the form.
     * SECURITY: CSRF - Symfony adds a hidden token named "change_password" and refuses the form without it.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'change_password',
        ]);
    }
}
