<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ResetPasswordRequestFormType extends AbstractType
{
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // SECURITY: CSRF token (rule 3).
            'csrf_token_id' => 'reset_password_request',
        ]);
    }
}
