<?php

/**
 * FILE: src/Form/ResetPasswordRequestFormType.php
 * WHAT: the "forgot my password" form (one field: email).
 * ROLE IN THE PROJECT: first step of the password reset.
 * USED BY: ResetPasswordController::request (visitor).
 */

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Not bound to an entity: the controller only reads the email.
 *
 * @extends AbstractType<mixed>
 */
class ResetPasswordRequestFormType extends AbstractType
{
    /**
     * Declares one field: the email.
     *
     * @param FormBuilderInterface<mixed> $builder object used to add fields
     * @param array<string, mixed> $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email',
            'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
            // SECURITY: server-side format/length validation (rule 15).
            'constraints' => [
                new Assert\NotBlank(message: 'Please enter your email.'),
                new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT),
                new Assert\Length(max: 180),
            ],
        ]);
    }

    /** Settings of the form: the name of its CSRF token. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // SECURITY: CSRF token (rule 3).
            'csrf_token_id' => 'reset_password_request',
        ]);
    }
}
