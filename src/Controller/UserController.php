<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\UserInvitationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserInvitationService $invitationService,
    ) {
    }

    #[Route('', name: 'app_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->userRepository->findAllOrdered();

        return $this->render('user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            try {
                $this->invitationService->sendInvitation($user);
                $this->addFlash('success', sprintf('Invitation sent to %s.', $user->getEmail()));
            } catch (\Throwable $e) {
                $this->addFlash('warning', sprintf(
                    'User created but the invitation email could not be sent: %s',
                    $e->getMessage(),
                ));
            }

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('User "%s" has been updated.', $user->getFullName()));

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['DELETE'])]
    public function delete(Request $request, User $user): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser->getId()->equals($user->getId())) {
            $this->addFlash('danger', 'You cannot delete your own account.');

            return $this->redirectToRoute('app_user_index');
        }

        $token = $request->request->get('_token');

        if ($this->isCsrfTokenValid('delete-user-'.$user->getId(), $token)) {
            $name = $user->getFullName();
            $this->entityManager->remove($user);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('User "%s" has been deleted.', $name));
        }

        return $this->redirectToRoute('app_user_index');
    }

    #[Route('/{id}/resend-invitation', name: 'app_user_resend_invitation', methods: ['POST'])]
    public function resendInvitation(Request $request, User $user): Response
    {
        if ($user->isVerified()) {
            $this->addFlash('warning', sprintf('User "%s" is already verified.', $user->getFullName()));

            return $this->redirectToRoute('app_user_index');
        }

        $token = $request->request->get('_token');

        if ($this->isCsrfTokenValid('resend-invitation-'.$user->getId(), $token)) {
            try {
                $this->invitationService->sendInvitation($user);
                $this->addFlash('success', sprintf('Invitation resent to %s.', $user->getEmail()));
            } catch (\Throwable $e) {
                $this->addFlash('danger', sprintf(
                    'Failed to resend invitation: %s',
                    $e->getMessage(),
                ));
            }
        }

        return $this->redirectToRoute('app_user_index');
    }
}
