<?php

namespace App\Form;

use App\Entity\User;
use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SECURITY: mass assignment (rule 9). The form has exactly three fields. roles, isVerified,
 * failedAttempts and lockedUntil are not fields, so a request can't set them.
 *
 * @extends AbstractType<User>
 */
class RegistrationFormType extends AbstractType
{
    public const ACCOUNT_BUYER = 'buyer';
    public const ACCOUNT_SELLER = 'seller';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
            ])
            // SECURITY: not mapped; the controller hashes it and the plain text is never stored.
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The two passwords do not match.',
                'first_options' => [
                    'label' => 'Password',
                    'help' => 'At least 12 characters, including letters and numbers.',
                    'attr' => ['autocomplete' => 'new-password', 'minlength' => 12],
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                    'attr' => ['autocomplete' => 'new-password', 'minlength' => 12],
                ],
                // SECURITY: password policy enforced server side (rule 15). The HTML attributes above are only a convenience.
                'constraints' => PasswordPolicy::constraints(),
            ])
            // SECURITY: the choice list contains only buyer and seller. Any other submitted value
            // (e.g. "admin") fails ChoiceType validation, and the controller maps it to a role with
            // a fixed whitelist (rule 9, privilege escalation).
            ->add('accountType', ChoiceType::class, [
                'label' => 'I want to',
                'mapped' => false,
                'expanded' => true,
                'choices' => [
                    'Buy products (buyer)' => self::ACCOUNT_BUYER,
                    'Sell products (seller)' => self::ACCOUNT_SELLER,
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please choose an account type.'),
                    new Assert\Choice(choices: [self::ACCOUNT_BUYER, self::ACCOUNT_SELLER]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            // SECURITY: CSRF token on the registration form (rule 3).
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'registration',
        ]);
    }
}
