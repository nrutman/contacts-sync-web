<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NotificationPreferenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notifyOnSuccess', CheckboxType::class, [
                'label' => 'Email me when a sync completes with changes',
                'help' => 'You will receive an email whenever a sync run finishes and contacts were added or removed.',
                'required' => false,
            ])
            ->add('notifyOnFailure', CheckboxType::class, [
                'label' => 'Email me when a sync fails',
                'help' => 'You will receive an email whenever a sync run encounters an error and fails to complete.',
                'required' => false,
            ])
            ->add('notifyOnNoChanges', CheckboxType::class, [
                'label' => 'Email me when a sync completes with no changes (informational)',
                'help' => 'You will receive an email even when a sync run completes successfully but no contacts were added or removed.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
