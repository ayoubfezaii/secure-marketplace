<?php

namespace App\Form;

use App\Dto\PaymentDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 10, price tampering): the checkout form contains ONLY payment fields. There is no price,
 * total, quantity or product field: everything the order is made of is re-read from the database by
 * CheckoutService. Extra submitted fields (e.g. checkout_form[total]=0.01) make the form invalid.
 */
class CheckoutFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cardHolder', TextType::class, [
                'label' => 'Name on card',
                'attr' => ['autocomplete' => 'cc-name', 'maxlength' => 100],
            ])
            ->add('cardNumber', TextType::class, [
                'label' => 'Card number',
                'help' => 'Simulated payment: use a test number such as 4242 4242 4242 4242.',
                'attr' => ['autocomplete' => 'cc-number', 'inputmode' => 'numeric', 'maxlength' => 23],
            ])
            ->add('expiry', TextType::class, [
                'label' => 'Expiry (MM/YY)',
                'attr' => ['autocomplete' => 'cc-exp', 'placeholder' => 'MM/YY', 'maxlength' => 5],
            ])
            // SECURITY: PasswordType is never re-rendered with its value (always_empty), so the CVV doesn't
            // come back in the HTML after a validation error.
            ->add('cvv', PasswordType::class, [
                'label' => 'CVV',
                'attr' => ['autocomplete' => 'cc-csc', 'inputmode' => 'numeric', 'maxlength' => 4],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentDto::class,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'checkout',
        ]);
    }
}
