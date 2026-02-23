<?php

namespace App\Controller;

use App\Entity\SyncRun;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class SyncRunApiController extends AbstractController
{
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
