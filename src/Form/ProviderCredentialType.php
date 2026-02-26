<?php

namespace App\Form;

use App\Client\Provider\ProviderRegistry;
use App\Entity\ProviderCredential;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProviderCredentialType extends AbstractType
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $providerName = $options['provider_name'];

        $builder->add('label', TextType::class, [
            'label' => 'Label',
            'required' => false,
            'attr' => [
                'placeholder' => 'e.g. Main Account',
            ],
            'help' => 'Optional display label to identify this credential.',
        ]);

        if ($providerName !== null) {
            $builder->add('providerName', HiddenType::class, [
                'data' => $providerName,
            ]);

            $this->addCredentialFields($builder, $providerName, $isEdit);
        } elseif (!$isEdit) {
            $choices = [];
            foreach ($this->providerRegistry->all() as $name => $provider) {
                $choices[$provider->getDisplayName()] = $name;
            }

            $builder->add('providerName', ChoiceType::class, [
                'label' => 'Provider',
                'choices' => $choices,
                'placeholder' => '-- Select a provider --',
            ]);
        }

        // Dynamically add credential fields based on the submitted provider name
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($isEdit) {
            $data = $event->getData();
            $form = $event->getForm();
            $pName = $data['providerName'] ?? null;

            if ($pName !== null && !$form->has('credential_app_id') && !$form->has('credential_oauth_credentials')) {
                $this->addCredentialFields($form, $pName, $isEdit);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProviderCredential::class,
            'is_edit' => false,
            'provider_name' => null,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('provider_name', ['null', 'string']);
    }

    private function addCredentialFields(FormBuilderInterface|\Symfony\Component\Form\FormInterface $builder, string $providerName, bool $isEdit): void
    {
        try {
            $provider = $this->providerRegistry->get($providerName);
        } catch (\Throwable) {
            return;
        }

        foreach ($provider->getCredentialFields() as $field) {
            $fieldName = 'credential_'.$field->name;
            $fieldType = match ($field->type) {
                'password' => PasswordType::class,
                'textarea' => TextareaType::class,
                default => TextType::class,
            };

            $fieldOptions = [
                'label' => $field->label,
                'mapped' => false,
                'required' => $field->required && !$isEdit,
                'attr' => [],
            ];

            if ($field->placeholder !== null) {
                $fieldOptions['attr']['placeholder'] = $isEdit && $field->sensitive
                    ? 'Leave blank to keep current'
                    : $field->placeholder;
            }

            if ($field->help !== null) {
                $fieldOptions['help'] = $field->help;
            }

            if ($field->type === 'password') {
                $fieldOptions['always_empty'] = false;
                $fieldOptions['empty_data'] = '';
            }

            if ($field->type === 'textarea') {
                $fieldOptions['attr']['rows'] = 6;
            }

            $builder->add($fieldName, $fieldType, $fieldOptions);
        }
    }
}
