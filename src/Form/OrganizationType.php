<?php

namespace App\Form;

use App\Entity\Organization;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrganizationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Organization Name',
                'attr' => [
                    'placeholder' => 'e.g. My Church',
                ],
            ])
            ->add('planningCenterAppId', TextType::class, [
                'label' => 'Planning Center App ID',
                'attr' => [
                    'autocomplete' => 'off',
                    'placeholder' => 'Your Planning Center application ID',
                ],
            ])
            ->add('planningCenterAppSecret', PasswordType::class, [
                'label' => 'Planning Center App Secret',
                'always_empty' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'placeholder' => $options['is_edit'] ? '••••••••  (leave blank to keep current)' : 'Your Planning Center application secret',
                ],
                'required' => !$options['is_edit'],
                'empty_data' => '',
            ])
            ->add('googleDomain', TextType::class, [
                'label' => 'Google Workspace Domain',
                'attr' => [
                    'placeholder' => 'e.g. example.com',
                ],
                'help' => 'The Google Workspace domain used for Google Groups.',
            ])
            ->add('googleOAuthCredentials', TextareaType::class, [
                'label' => 'Google OAuth Credentials JSON',
                'attr' => [
                    'rows' => 6,
                    'placeholder' => $options['is_edit'] ? 'Leave blank to keep current credentials' : 'Paste the full OAuth client JSON from Google Cloud Console',
                    'class' => 'font-mono text-sm',
                ],
                'help' => 'Paste the JSON credentials downloaded from Google Cloud Console. Use a "Web application" type credential with the correct redirect URI.',
                'required' => !$options['is_edit'],
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Organization::class,
            'is_edit' => false,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
