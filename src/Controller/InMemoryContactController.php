<?php

namespace App\Controller;

use App\Entity\InMemoryContact;
use App\Form\InMemoryContactType;
use App\Repository\InMemoryContactRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/contacts')]
class InMemoryContactController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly InMemoryContactRepository $inMemoryContactRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_contact_index', methods: ['GET'])]
    public function index(): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash('warning', 'Please configure your organization in Settings first.');

            return $this->redirectToRoute('app_settings');
        }

        $contacts = $this->inMemoryContactRepository->findBy(
            ['organization' => $organization],
            ['email' => 'ASC'],
        );

        return $this->render('in_memory_contact/index.html.twig', [
            'contacts' => $contacts,
        ]);
    }

    #[Route('/new', name: 'app_contact_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash('warning', 'Please configure your organization in Settings first.');

            return $this->redirectToRoute('app_settings');
        }

        $contact = new InMemoryContact();
        $form = $this->createForm(InMemoryContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $contact->setOrganization($organization);
            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Contact "%s" has been created.', $contact->getName()));

            return $this->redirectToRoute('app_contact_index');
        }

        return $this->render('in_memory_contact/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_contact_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, InMemoryContact $contact): Response
    {
        $form = $this->createForm(InMemoryContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Contact "%s" has been updated.', $contact->getName()));

            return $this->redirectToRoute('app_contact_index');
        }

        return $this->render('in_memory_contact/edit.html.twig', [
            'contact' => $contact,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_contact_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, InMemoryContact $contact): Response
    {
        $token = $request->request->get('_token');

        if ($this->isCsrfTokenValid('delete-contact-'.$contact->getId(), $token)) {
            $name = $contact->getName();
            $this->entityManager->remove($contact);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Contact "%s" has been deleted.', $name));
        }

        return $this->redirectToRoute('app_contact_index');
    }
}
