<?php

namespace App\Controller;

use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunRepository;
use Cron\CronExpression as CronExpressionLib;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly SyncListRepository $syncListRepository,
        private readonly SyncRunRepository $syncRunRepository,
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

        $totalLists = $this->syncListRepository->countByOrganization($organization);
        $enabledLists = $this->syncListRepository->countEnabledByOrganization($organization);
        $lastSyncRun = $this->syncRunRepository->findLastCompletedByOrganization($organization);
        $recentSyncRuns = $this->syncRunRepository->findRecentByOrganization($organization, 10);
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

    /**
     * Computes the next scheduled sync time across all enabled lists with cron expressions.
     *
     * @return array{time: \DateTimeImmutable, listName: string}|null
     */
    private function computeNextScheduledSync(\App\Entity\Organization $organization): ?array
    {
        $enabledLists = $this->syncListRepository->findEnabledByOrganization($organization);
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
            $nextRun = \DateTimeImmutable::createFromMutable($cron->getNextRunDate());

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
