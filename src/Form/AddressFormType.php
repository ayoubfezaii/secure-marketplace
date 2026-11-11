<?php

namespace App\Form;

use App\Entity\Address;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * SECURITY (rule 9, mass assignment): explicit fields only. "user" and "uuid" are not fields: the owner is set by
 * the controller from the session. Extra submitted fields make the form invalid.
 *
 * @extends AbstractType<Address>
 */
class AddressFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Label', 'help' => 'e.g. Home, Office', 'attr' => ['maxlength' => 40]])
            ->add('fullName', TextType::class, ['label' => 'Full name', 'attr' => ['maxlength' => 100, 'autocomplete' => 'name']])
            ->add('line1', TextType::class, ['label' => 'Address', 'attr' => ['maxlength' => 120, 'autocomplete' => 'address-line1']])
            ->add('line2', TextType::class, ['label' => 'Apartment, suite (optional)', 'required' => false, 'attr' => ['maxlength' => 120, 'autocomplete' => 'address-line2']])
            ->add('postalCode', TextType::class, ['label' => 'Postal code', 'attr' => ['maxlength' => 12, 'autocomplete' => 'postal-code']])
            ->add('city', TextType::class, ['label' => 'City', 'attr' => ['maxlength' => 80, 'autocomplete' => 'address-level2']])
            // SECURITY (rule 15): whitelist of countries, also validated on the entity.
            ->add('country', ChoiceType::class, ['label' => 'Country', 'choices' => array_flip(Address::COUNTRIES), 'attr' => ['autocomplete' => 'country']])
            ->add('phone', TelType::class, ['label' => 'Phone (optional, for the courier)', 'required' => false, 'attr' => ['maxlength' => 20, 'autocomplete' => 'tel']])
            ->add('isDefault', CheckboxType::class, ['label' => 'Use as my default address', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Address::class,
            'empty_data' => null,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'address',
        ]);
    }
}
