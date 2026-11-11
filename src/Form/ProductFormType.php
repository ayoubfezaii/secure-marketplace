<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Service\ImageStorage;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
 * SECURITY (rule 9, mass assignment): explicit field list. "seller", "uuid", "createdAt", the images collection,
 * the rating and the sales figures are NOT fields: the owner is set from the session in the controller, images are
 * added by the controller after ImageStorage validated them. Any extra submitted field makes the form invalid.
 *
 * @extends AbstractType<Product>
 */
class ProductFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Product|null $product */
        $product = $options['data'] ?? null;
        $existing = null !== $product ? $product->getImages()->count() : 0;

        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['maxlength' => 120, 'minlength' => 3, 'data-preview-target' => 'name', 'data-action' => 'input->preview#update'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'help' => 'Plain text, max 5000 characters. HTML is displayed as text.',
                'attr' => ['rows' => 7, 'maxlength' => 5000],
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Price',
                'currency' => 'EUR',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => '0.01', 'max' => '100000', 'step' => '0.01', 'data-preview-target' => 'price', 'data-action' => 'input->preview#update'],
            ])
            ->add('compareAtPrice', MoneyType::class, [
                'label' => 'Original price (optional)',
                'help' => 'Shown crossed out with a discount badge. Must be higher than the price.',
                'required' => false,
                'currency' => 'EUR',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => '0.01', 'max' => '100000', 'step' => '0.01', 'data-preview-target' => 'oldPrice', 'data-action' => 'input->preview#update'],
            ])
            ->add('stock', IntegerType::class, [
                'label' => 'Stock',
                'attr' => ['min' => 0, 'max' => 1000000],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Category',
                // SECURITY: only existing Category rows can be chosen; any other submitted id is rejected.
                'class' => Category::class,
                'choice_label' => 'name',
                'placeholder' => 'Choose a category',
                'query_builder' => static fn (CategoryRepository $r) => $r->createQueryBuilder('c')->orderBy('c.position', 'ASC'),
                'attr' => ['data-preview-target' => 'category', 'data-action' => 'change->preview#update'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Visible in the catalogue',
                'required' => false,
            ])
            // SECURITY (rule 7): only size/count are checked here. The type check is done by ImageStorage on the real
            // bytes (finfo, getimagesize, GD re-encoding) so that every rejected file is audit-logged.
            ->add('imageFiles', FileType::class, [
                'label' => 'Upload images',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'help' => sprintf('JPG, PNG or WebP, max 2 MB each, up to %d images per product.', Product::MAX_IMAGES),
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    new Assert\Count(max: Product::MAX_IMAGES, maxMessage: 'You can upload at most {{ limit }} images.'),
                    new Assert\All([new Assert\File(maxSize: ImageStorage::MAX_BYTES, maxSizeMessage: 'Each image must be smaller than 2 MB.')]),
                ],
            ])
            // SECURITY (rule 8): only a length check here. ALL URL checks (scheme, host, IP ranges...) are done by
            // SafeImageFetcher, so that every refused URL is audit-logged as an SSRF attempt (rule 14).
            ->add('imageUrl', UrlType::class, [
                'label' => '… or import one image from a URL',
                'mapped' => false,
                'required' => false,
                'default_protocol' => null,
                'help' => 'https:// only, max 2 MB.',
                'attr' => ['placeholder' => 'https://example.com/picture.jpg', 'maxlength' => 2048],
                'constraints' => [new Assert\Length(max: 2048)],
            ])
        ;

        if ($existing > 0) {
            // Positions of the product's OWN images (0..3): nothing else can be submitted (choice whitelist).
            $builder->add('removeImages', ChoiceType::class, [
                'label' => 'Remove images',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(array_map(static fn (int $i) => 'Image '.($i + 1), range(0, $existing - 1)), range(0, $existing - 1)),
            ]);
        }
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
