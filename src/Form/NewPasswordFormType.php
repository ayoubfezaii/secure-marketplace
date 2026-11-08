<?php

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * New password + confirmation, used by the password-reset flow.
 */
class NewPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'The two passwords do not match.',
            'first_options' => [
                'label' => 'New password',
                'help' => 'At least 12 characters, including letters and numbers.',
                'attr' => ['autocomplete' => 'new-password', 'minlength' => PasswordPolicy::MIN_LENGTH],
            ],
            'second_options' => [
                'label' => 'Repeat new password',
                'attr' => ['autocomplete' => 'new-password', 'minlength' => PasswordPolicy::MIN_LENGTH],
            ],
            // SECURITY: same server-side policy as registration (rule 15).
            'constraints' => PasswordPolicy::constraints(),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // SECURITY: CSRF token (rule 3).
            'csrf_token_id' => 'reset_password',
        ]);
    }
}
