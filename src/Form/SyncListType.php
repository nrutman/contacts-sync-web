<?php

namespace App\Form;

use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SyncListType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Display Name',
                'attr' => [
                    'placeholder' => 'e.g. Church Members Sync',
                ],
                'help' => 'A friendly name for this sync configuration.',
            ])
            ->add('sourceCredential', EntityType::class, [
                'class' => ProviderCredential::class,
                'label' => 'Source Provider',
                'placeholder' => '-- Select source --',
                'required' => false,
                'choice_label' => fn (ProviderCredential $c) => sprintf('%s (%s)', $c->getDisplayLabel(), $c->getProviderName()),
            ])
            ->add('sourceListIdentifier', TextType::class, [
                'label' => 'Source List Name',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g. Church Members',
                ],
                'help' => 'The list name or identifier in the source provider.',
            ])
            ->add('destinationCredential', EntityType::class, [
                'class' => ProviderCredential::class,
                'label' => 'Destination Provider',
                'placeholder' => '-- Select destination --',
                'required' => false,
                'choice_label' => fn (ProviderCredential $c) => sprintf('%s (%s)', $c->getDisplayLabel(), $c->getProviderName()),
            ])
            ->add('destinationListIdentifier', TextType::class, [
                'label' => 'Destination List Identifier',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g. church@example.com',
                ],
                'help' => 'The list name or email address in the destination provider (e.g. Google Group email).',
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label' => 'Enabled',
                'required' => false,
                'help' => 'When disabled, this list will not be included in scheduled syncs.',
            ])
            ->add('cronExpression', HiddenType::class, [
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SyncList::class,
        ]);
    }
}
