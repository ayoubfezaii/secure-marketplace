<?php

namespace App\Form;

use App\Dto\PaymentDto;
use App\Enum\PaymentMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 10, price tampering): the checkout form contains ONLY payment fields. There is no price,
 * total, quantity or product field: everything the order is made of is re-read from the database by
 * CheckoutService. Extra submitted fields (e.g. checkout_form[total]=0.01) make the form invalid.
 *
 * The payment method is an EnumType (whitelist): a forged value is an invalid choice. The card fields are optional
 * in HTML (they are hidden for cash on delivery) but validated on the server whenever the method is "card".
 *
 * @extends AbstractType<PaymentDto>
 */
class CheckoutFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // SECURITY (rules 9, 15): only the two enum cases are accepted, whatever the browser sends.
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Payment method',
                'expanded' => true,
                'choice_label' => static fn (PaymentMethod $method): string => $method->label(),
            ])
            ->add('cardHolder', TextType::class, [
                'label' => 'Name on card',
                'required' => false,
                'attr' => ['autocomplete' => 'cc-name', 'maxlength' => 100],
            ])
            ->add('cardNumber', TextType::class, [
                'label' => 'Card number',
                'required' => false,
                'help' => 'Simulated payment: use a test number such as 4242 4242 4242 4242.',
                'attr' => ['autocomplete' => 'cc-number', 'inputmode' => 'numeric', 'maxlength' => 23],
            ])
            ->add('expiry', TextType::class, [
                'label' => 'Expiry (MM/YY)',
                'required' => false,
                'attr' => ['autocomplete' => 'cc-exp', 'placeholder' => 'MM/YY', 'maxlength' => 5],
            ])
            // SECURITY: PasswordType is never re-rendered with its value (always_empty), so the CVV doesn't
            // come back in the HTML after a validation error.
            ->add('cvv', PasswordType::class, [
                'label' => 'CVV',
                'required' => false,
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
            // SECURITY: card fields are validated ONLY for a card payment, and ALWAYS for it. The "card" group is
            // dropped only when the submitted method is a valid CashOnDelivery enum case: a missing or forged
            // method keeps the card validation on (fail closed).
            'validation_groups' => static function (FormInterface $form): array {
                $data = $form->getData();

                return $data instanceof PaymentDto && $data->isCashOnDelivery() ? ['Default'] : ['Default', 'card'];
            },
        ]);
    }
}
