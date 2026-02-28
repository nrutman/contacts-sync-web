<?php

namespace App\Controller;

use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Sync\SyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class SyncRunApiController extends AbstractController
{
    public function __construct(
        private readonly SyncService $syncService,
    ) {
    }

    #[Route('/sync-lists/{id}/sync', name: 'api_sync_list_sync', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sync(Request $request, SyncList $syncList): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-Token');

        if (!$this->isCsrfTokenValid('sync-run-all', $csrfToken)) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $result = $this->syncService->executeSync(
            syncList: $syncList,
            triggeredBy: $user,
            trigger: 'manual',
        );

        return $this->json([
            'success' => $result->success,
            'listName' => $syncList->getName(),
            'addedCount' => $result->addedCount,
            'removedCount' => $result->removedCount,
            'errorMessage' => $result->errorMessage,
        ]);
    }

    #[Route('/sync-runs/{id}/status', name: 'api_sync_run_status', methods: ['GET'])]
    public function status(SyncRun $syncRun): JsonResponse
    {
        $data = [
            'id' => (string) $syncRun->getId(),
            'status' => $syncRun->getStatus(),
            'sourceCount' => $syncRun->getSourceCount(),
            'destinationCount' => $syncRun->getDestinationCount(),
            'addedCount' => $syncRun->getAddedCount(),
            'removedCount' => $syncRun->getRemovedCount(),
            'durationSeconds' => $syncRun->getDurationSeconds(),
            'startedAt' => $syncRun->getStartedAt()?->format('c'),
            'completedAt' => $syncRun->getCompletedAt()?->format('c'),
            'createdAt' => $syncRun->getCreatedAt()->format('M j, Y g:ia'),
            'triggeredBy' => $syncRun->getTriggeredBy(),
            'triggeredByUser' => $syncRun->getTriggeredByUser()?->getFullName(),
            'errorMessage' => $syncRun->getErrorMessage(),
        ];

        return $this->json($data);
    }
}
