<?php

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunRepository;
use App\Sync\SyncService;
use Cron\CronExpression as CronExpressionLib;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly SyncListRepository $syncListRepository,
        private readonly SyncRunRepository $syncRunRepository,
        private readonly SyncService $syncService,
    ) {
    }

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            return $this->render('dashboard/index.html.twig', [
                'organization' => null,
                'totalLists' => 0,
                'enabledLists' => 0,
                'lastSyncRun' => null,
                'nextScheduledSync' => null,
                'recentSyncRuns' => [],
            ]);
        }

        $totalLists = $this->syncListRepository->countByOrganization(
            $organization,
        );
        $enabledLists = $this->syncListRepository->countEnabledByOrganization(
            $organization,
        );
        $lastSyncRun = $this->syncRunRepository->findLastCompletedByOrganization(
            $organization,
        );
        $recentSyncRuns = $this->syncRunRepository->findRecentByOrganization(
            $organization,
            10,
        );
        $nextScheduledSync = $this->computeNextScheduledSync($organization);

        return $this->render('dashboard/index.html.twig', [
            'organization' => $organization,
            'totalLists' => $totalLists,
            'enabledLists' => $enabledLists,
            'lastSyncRun' => $lastSyncRun,
            'nextScheduledSync' => $nextScheduledSync,
            'recentSyncRuns' => $recentSyncRuns,
        ]);
    }

    #[Route('/sync/run-all', name: 'app_sync_run_all', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function runAll(Request $request): Response
    {
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('sync-run-all', $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard');
        }

        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash(
                'warning',
                'Please configure your organization in Settings first.',
            );

            return $this->redirectToRoute('app_settings');
        }

        $enabledLists = $this->syncListRepository->findEnabledByOrganization(
            $organization,
        );

        if (count($enabledLists) === 0) {
            $this->addFlash('info', 'No enabled sync lists found.');

            return $this->redirectToRoute('app_dashboard');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $successCount = 0;
        $failCount = 0;

        foreach ($enabledLists as $syncList) {
            $result = $this->syncService->executeSync(
                $syncList,
                triggeredBy: $user,
                trigger: 'manual',
            );

            if ($result->success) {
                ++$successCount;
            } else {
                ++$failCount;
            }
        }

        if ($failCount === 0) {
            $this->addFlash(
                'success',
                sprintf(
                    'All %d sync lists completed successfully.',
                    $successCount,
                ),
            );
        } else {
            $this->addFlash(
                'warning',
                sprintf(
                    '%d of %d sync lists completed. %d failed.',
                    $successCount,
                    $successCount + $failCount,
                    $failCount,
                ),
            );
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Computes the next scheduled sync time across all enabled lists with cron expressions.
     *
     * @return array{time: \DateTimeImmutable, listName: string}|null
     */
    private function computeNextScheduledSync(
        \App\Entity\Organization $organization,
    ): ?array {
        $enabledLists = $this->syncListRepository->findEnabledByOrganization(
            $organization,
        );
        $earliest = null;

        foreach ($enabledLists as $list) {
            $cronExpr = $list->getCronExpression();

            if ($cronExpr === null || $cronExpr === '') {
                continue;
            }

            if (!CronExpressionLib::isValidExpression($cronExpr)) {
                continue;
            }

            $cron = new CronExpressionLib($cronExpr);
            $nextRun = \DateTimeImmutable::createFromMutable(
                $cron->getNextRunDate(),
            );

            if ($earliest === null || $nextRun < $earliest['time']) {
                $earliest = [
                    'time' => $nextRun,
                    'listName' => $list->getName(),
                ];
            }
        }

        return $earliest;
    }
}
