<?php

namespace App\Controller;

use App\Entity\SyncList;
use App\Message\RefreshListMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PlanningCenterController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        '/lists/{id}/refresh',
        name: 'app_sync_list_refresh',
        methods: ['POST'],
    ),]
    #[IsGranted('ROLE_USER')]
    public function refresh(Request $request, SyncList $syncList): Response
    {
        $token = $request->request->get('_token');

        if (
            !$this->isCsrfTokenValid(
                'refresh-sync-list-'.$syncList->getId(),
                $token,
            )
        ) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_sync_list_history', [
                'id' => $syncList->getId(),
            ]);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $this->messageBus->dispatch(
            new RefreshListMessage(
                syncListId: (string) $syncList->getId(),
                triggeredByUserId: (string) $user->getId(),
            ),
        );

        $this->addFlash(
            'info',
            sprintf(
                'Refresh has been queued for source list "%s". It will begin processing shortly.',
                $syncList->getName(),
            ),
        );

        return $this->redirectToRoute('app_sync_list_history', [
            'id' => $syncList->getId(),
        ]);
    }
}
