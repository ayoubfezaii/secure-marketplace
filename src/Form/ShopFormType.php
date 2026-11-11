<?php

namespace App\Form;

use App\Entity\Shop;
use App\Service\ImageStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SECURITY (rule 9): name, slug, description and an optional logo only. The owner is never a field.
 * The logo goes through ImageStorage (real MIME type, re-encoding, random name, outside public/, rule 7).
 *
 * @extends AbstractType<Shop>
 */
class ShopFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Shop name', 'attr' => ['maxlength' => 80]])
            ->add('slug', TextType::class, [
                'label' => 'Shop address',
                'help' => 'Lowercase letters, numbers and dashes. Your public page: /shop/<address>',
                'attr' => ['maxlength' => 60, 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*', 'spellcheck' => 'false', 'autocapitalize' => 'off'],
            ])
            ->add('description', TextareaType::class, ['label' => 'About the shop', 'required' => false, 'attr' => ['rows' => 5, 'maxlength' => 2000]])
            ->add('logoFile', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'help' => 'Square image, JPG, PNG or WebP, max 2 MB.',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [new Assert\File(maxSize: ImageStorage::MAX_BYTES, maxSizeMessage: 'The image must be smaller than 2 MB.')],
            ])
            ->add('removeLogo', CheckboxType::class, ['label' => 'Remove the current logo', 'mapped' => false, 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Shop::class,
            'empty_data' => null,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'shop',
        ]);
    }
}
