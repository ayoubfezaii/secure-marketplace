<?php

namespace App\Form;

use App\Entity\Product;
use App\Service\ImageStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SECURITY (rule 9, mass assignment): explicit field list. "seller", "uuid", "createdAt" and "imageFilename"
 * are NOT fields: the owner is set from the session in the controller, the image name by ImageStorage.
 * Any extra submitted field makes the form invalid ("This form should not contain extra fields").
 */
class ProductFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['maxlength' => 120, 'minlength' => 3],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'help' => 'Plain text, max 5000 characters. HTML is displayed as text.',
                'attr' => ['rows' => 6, 'maxlength' => 5000],
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Price',
                'currency' => 'EUR',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => '0.01', 'max' => '100000', 'step' => '0.01'],
            ])
            ->add('stock', IntegerType::class, [
                'label' => 'Stock',
                'attr' => ['min' => 0, 'max' => 1000000],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                // SECURITY: fixed whitelist; any other submitted value is rejected.
                'choices' => array_flip(Product::CATEGORIES),
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Visible in the catalogue',
                'required' => false,
            ])
            // SECURITY (rule 7): only the size is checked here. The type check is done by ImageStorage on the real
            // bytes (finfo, getimagesize, GD re-encoding) so that every rejected file is audit-logged.
            ->add('imageFile', FileType::class, [
                'label' => 'Upload an image',
                'mapped' => false,
                'required' => false,
                'help' => 'JPG, PNG or WebP, max 2 MB.',
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    new Assert\File(maxSize: ImageStorage::MAX_BYTES, maxSizeMessage: 'The image must be smaller than 2 MB.'),
                ],
            ])
            // SECURITY (rule 8): only a length check here. ALL URL checks (scheme, host, IP ranges...) are done by
            // SafeImageFetcher, so that every refused URL is audit-logged as an SSRF attempt (rule 14).
            ->add('imageUrl', UrlType::class, [
                'label' => '... or import an image from a URL',
                'mapped' => false,
                'required' => false,
                'default_protocol' => null,
                'help' => 'https:// only, max 2 MB.',
                'attr' => ['placeholder' => 'https://example.com/picture.jpg', 'maxlength' => 2048],
                'constraints' => [
                    new Assert\Length(max: 2048),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
            // Products are constructed with their seller by the controller.
            'empty_data' => null,
            // SECURITY: CSRF (rule 3).
            'csrf_token_id' => 'product',
        ]);
    }
}
