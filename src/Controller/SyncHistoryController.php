<?php

namespace App\Controller;

use App\Entity\SyncRun;
use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/history')]
class SyncHistoryController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly SyncRunRepository $syncRunRepository,
        private readonly SyncListRepository $syncListRepository,
    ) {
    }

    #[Route('', name: 'app_history_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash('warning', 'Please configure your organization in Settings first.');

            return $this->redirectToRoute('app_settings');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        // Optional filters
        $syncListId = $request->query->get('list');
        $status = $request->query->get('status');

        $syncList = null;

        if ($syncListId !== null && $syncListId !== '') {
            $syncList = $this->syncListRepository->find($syncListId);
        }

        $validStatuses = ['pending', 'running', 'success', 'failed'];
        $statusFilter = in_array($status, $validStatuses, true) ? $status : null;

        $syncRuns = $this->syncRunRepository->findByOrganizationPaginated(
            $organization,
            $limit,
            $offset,
            $syncList,
            $statusFilter,
        );

        $totalCount = $this->syncRunRepository->countByOrganization(
            $organization,
            $syncList,
            $statusFilter,
        );

        $totalPages = max(1, (int) ceil($totalCount / $limit));
        $allLists = $this->syncListRepository->findByOrganization($organization);

        return $this->render('sync_history/index.html.twig', [
            'sync_runs' => $syncRuns,
            'all_lists' => $allLists,
            'current_list' => $syncList,
            'current_status' => $statusFilter,
            'page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount,
        ]);
    }

    #[Route('/{id}', name: 'app_history_show', methods: ['GET'])]
    public function show(SyncRun $syncRun): Response
    {
        return $this->render('sync_history/show.html.twig', [
            'sync_run' => $syncRun,
        ]);
    }
}
