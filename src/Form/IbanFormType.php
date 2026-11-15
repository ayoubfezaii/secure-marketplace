<?php

/**
 * FILE: src/Form/IbanFormType.php
 * WHAT: the form to save or remove the seller's bank account number (IBAN).
 * ROLE IN THE PROJECT: collects SENSITIVE DATA; it fills an IbanDto and the value is encrypted by the controller.
 * USED BY: Seller\ShopController::payouts (seller).
 */

namespace App\Form;

use App\Dto\IbanDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY: mass assignment - bound to IbanDto (2 fields), not to the User entity.
 *
 * @extends AbstractType<IbanDto>
 */
class IbanFormType extends AbstractType
{
    /**
     * Declares the two fields: the IBAN text and a "remove" checkbox.
     *
     * @param FormBuilderInterface<IbanDto|null> $builder object used to add fields
     * @param array<string, mixed> $options options of the form (not used here)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('iban', TextType::class, [
                'label' => 'New IBAN',
                'required' => false,
                'help' => 'Stored encrypted (libsodium). Only the last 4 characters are ever displayed.',
                // SECURITY: no autocomplete / spellcheck: the browser shouldn't remember or send it to a spell service.
                'attr' => ['maxlength' => 42, 'autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'FR76 3000 6000 0112 3456 7890 189'],
            ])
            ->add('removeIban', CheckboxType::class, ['label' => 'Remove my IBAN', 'required' => false])
        ;
    }

    /** Settings of the form: the DTO it fills and the name of its CSRF token. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IbanDto::class,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'iban',
        ]);
    }
}
