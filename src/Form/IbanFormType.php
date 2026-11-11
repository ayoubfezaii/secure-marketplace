<?php

namespace App\Form;

use App\Dto\IbanDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<IbanDto>
 */
class IbanFormType extends AbstractType
{
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IbanDto::class,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'iban',
        ]);
    }
}
