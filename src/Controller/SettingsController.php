<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Form\OrganizationType;
use App\Repository\OrganizationRepository;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly ProviderCredentialRepository $credentialRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();
        $isEdit = $organization !== null;

        if ($organization === null) {
            $organization = new Organization();
        }

        $form = $this->createForm(OrganizationType::class, $organization, [
            'is_edit' => $isEdit,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$isEdit) {
                $this->entityManager->persist($organization);
            }

            $this->entityManager->flush();

            $this->addFlash(
                'success',
                'Organization settings have been saved.',
            );

            return $this->redirectToRoute('app_settings');
        }

        $credentials = $isEdit
            ? $this->credentialRepository->findByOrganization($organization)
            : [];

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'organization' => $isEdit ? $organization : null,
            'credentials' => $credentials,
            'is_edit' => $isEdit,
        ]);
    }
}
