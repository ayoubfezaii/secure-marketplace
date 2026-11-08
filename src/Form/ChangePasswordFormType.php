<?php

namespace App\Form;

use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordFormType extends AbstractType
{
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
                    new UserPassword(message: 'Your current password is incorrect.'),
                ],
            ])
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'change_password',
        ]);
    }
}
