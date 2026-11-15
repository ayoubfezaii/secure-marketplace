<?php

/**
 * FILE: src/Form/RegistrationFormType.php
 * WHAT: the "create an account" form (name, email, password twice, accept the terms).
 * ROLE IN THE PROJECT: best example of the defence against MASS ASSIGNMENT: the role is not a field.
 * USED BY: RegistrationController::register (visitor).
 */

namespace App\Form;

use App\Entity\User;
use App\Security\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SECURITY: mass assignment (rule 9). The form has exactly these fields: name, email, password, terms.
 * roles, isVerified, failedAttempts, lockedUntil, shop... are not fields, so a request can't set them
 * ("This form should not contain extra fields"). Every registration creates a BUYER; becoming a seller requires an
 * application reviewed by an administrator (phase R5), and ROLE_ADMIN only exists through the console command.
 *
 * @extends AbstractType<User>
 */
class RegistrationFormType extends AbstractType
{
    /**
     * Declares the fields of the form.
     *
     * @param FormBuilderInterface $builder object used to add fields
     * @param array                $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Your name',
                'help' => 'Shown on your reviews instead of your email.',
                // SECURITY (rule 15): the entity also validates length and allowed characters.
                'constraints' => [new Assert\NotBlank(message: 'Please enter your name.')],
                'attr' => ['autocomplete' => 'name', 'maxlength' => 60, 'placeholder' => 'Alex Martin'],
            ])
            // The email format, length and uniqueness are validated by the constraints on User::$email.
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'placeholder' => 'you@example.com'],
            ])
            // SECURITY: not mapped; the controller hashes it and the plain text is never stored.
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The two passwords do not match.',
                'first_options' => [
                    'label' => 'Password',
                    'attr' => ['autocomplete' => 'new-password', 'minlength' => PasswordPolicy::MIN_LENGTH],
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                    'attr' => ['autocomplete' => 'new-password', 'minlength' => PasswordPolicy::MIN_LENGTH],
                ],
                // SECURITY: password policy enforced server side (rule 15). The HTML attributes above are only a convenience.
                'constraints' => PasswordPolicy::constraints(),
            ])
            // Checkbox that must be ticked. Not stored (mapped => false).
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'I agree to the terms of use and the privacy policy',
                'mapped' => false,
                'constraints' => [new Assert\IsTrue(message: 'Please accept the terms to create your account.')],
            ])
        ;
    }

    /** Settings of the form: the class it fills (User) and its CSRF protection. */
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
