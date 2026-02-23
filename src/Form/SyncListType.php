<?php

namespace App\Form;

use App\Entity\SyncList;
use App\Validator\CronExpression;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SyncListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'List Name',
                'attr' => [
                    'placeholder' => 'e.g. church@provchurch.org',
                ],
                'help' => 'The Google Group email address to sync contacts to.',
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label' => 'Enabled',
                'required' => false,
                'help' => 'When disabled, this list will not be included in scheduled syncs.',
            ])
            ->add('cronExpression', TextType::class, [
                'label' => 'Schedule (Cron Expression)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g. 0 2 * * * (daily at 2 AM)',
                ],
                'help' => 'Optional. Use standard cron syntax: minute hour day month weekday. Leave blank for manual-only sync.',
                'constraints' => [
                    new CronExpression(),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SyncList::class,
        ]);
    }
}
