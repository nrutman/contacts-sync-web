<?php

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/sync-lists/bulk')]
#[IsGranted('ROLE_ADMIN')]
class SyncListBulkApiController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly SyncListRepository $syncListRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/activate', name: 'api_sync_list_bulk_activate', methods: ['POST'])]
    public function activate(Request $request): JsonResponse
    {
        $error = $this->validateCsrf($request);
        if ($error !== null) {
            return $error;
        }

        $ids = $this->getIds($request);
        if ($ids === null) {
            return $this->json(['error' => 'Missing or invalid "ids" array.'], Response::HTTP_BAD_REQUEST);
        }

        $lists = $this->findLists($ids);

        foreach ($lists as $list) {
            $list->setIsEnabled(true);
        }

        $this->entityManager->flush();

        return $this->json(['success' => true, 'updated' => count($lists)]);
    }

    #[Route('/deactivate', name: 'api_sync_list_bulk_deactivate', methods: ['POST'])]
    public function deactivate(Request $request): JsonResponse
    {
        $error = $this->validateCsrf($request);
        if ($error !== null) {
            return $error;
        }

        $ids = $this->getIds($request);
        if ($ids === null) {
            return $this->json(['error' => 'Missing or invalid "ids" array.'], Response::HTTP_BAD_REQUEST);
        }

        $lists = $this->findLists($ids);

        foreach ($lists as $list) {
            $list->setIsEnabled(false);
        }

        $this->entityManager->flush();

        return $this->json(['success' => true, 'updated' => count($lists)]);
    }

    #[Route('/schedule', name: 'api_sync_list_bulk_schedule', methods: ['POST'])]
    public function schedule(Request $request): JsonResponse
    {
        $error = $this->validateCsrf($request);
        if ($error !== null) {
            return $error;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $ids = $data['ids'] ?? null;

        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'Missing or invalid "ids" array.'], Response::HTTP_BAD_REQUEST);
        }

        $cronExpression = $data['cronExpression'] ?? null;

        // Allow empty string or null to clear the schedule
        if ($cronExpression !== null && $cronExpression !== '' && !CronExpression::isValidExpression($cronExpression)) {
            return $this->json(['error' => 'Invalid cron expression.'], Response::HTTP_BAD_REQUEST);
        }

        $lists = $this->findLists($ids);

        $normalizedCron = ($cronExpression === '' || $cronExpression === null) ? null : $cronExpression;

        foreach ($lists as $list) {
            $list->setCronExpression($normalizedCron);
        }

        $this->entityManager->flush();

        return $this->json(['success' => true, 'updated' => count($lists)]);
    }

    private function validateCsrf(Request $request): ?JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-Token');

        if (!$this->isCsrfTokenValid('bulk-actions', $csrfToken)) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @return string[]|null
     */
    private function getIds(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = $data['ids'] ?? null;

        if (!is_array($ids) || $ids === []) {
            return null;
        }

        return $ids;
    }

    /**
     * @param string[] $ids
     *
     * @return \App\Entity\SyncList[]
     */
    private function findLists(array $ids): array
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            return [];
        }

        return $this->syncListRepository->findByOrganizationAndIds($organization, $ids);
    }
}
