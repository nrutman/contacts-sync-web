<?php

namespace App\Controller;

use App\Entity\SyncList;
use App\Form\SyncListType;
use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunRepository;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/lists')]
class SyncListController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly SyncListRepository $syncListRepository,
        private readonly SyncRunRepository $syncRunRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncService $syncService,
    ) {
    }

    #[Route('', name: 'app_sync_list_index', methods: ['GET'])]
    public function index(): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash(
                'warning',
                'Please configure your organization in Settings first.',
            );

            return $this->redirectToRoute('app_settings');
        }

        $syncLists = $this->syncListRepository->findByOrganization(
            $organization,
        );

        return $this->render('sync_list/index.html.twig', [
            'sync_lists' => $syncLists,
        ]);
    }

    #[Route('/new', name: 'app_sync_list_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash(
                'warning',
                'Please configure your organization in Settings first.',
            );

            return $this->redirectToRoute('app_settings');
        }

        $syncList = new SyncList();
        $form = $this->createForm(SyncListType::class, $syncList);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $syncList->setOrganization($organization);
            $this->entityManager->persist($syncList);
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Sync list "%s" has been created.',
                    $syncList->getName(),
                ),
            );

            return $this->redirectToRoute('app_sync_list_index');
        }

        return $this->render('sync_list/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_sync_list_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, SyncList $syncList): Response
    {
        $form = $this->createForm(SyncListType::class, $syncList);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Sync list "%s" has been updated.',
                    $syncList->getName(),
                ),
            );

            return $this->redirectToRoute('app_sync_list_index');
        }

        return $this->render('sync_list/edit.html.twig', [
            'sync_list' => $syncList,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_sync_list_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, SyncList $syncList): Response
    {
        $token = $request->request->get('_token');

        if (
            $this->isCsrfTokenValid(
                'delete-sync-list-'.$syncList->getId(),
                $token,
            )
        ) {
            $name = $syncList->getName();
            $this->entityManager->remove($syncList);
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                sprintf('Sync list "%s" has been deleted.', $name),
            );
        }

        return $this->redirectToRoute('app_sync_list_index');
    }

    #[Route('/{id}/toggle', name: 'app_sync_list_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, SyncList $syncList): Response
    {
        $token = $request->request->get('_token');

        if (
            $this->isCsrfTokenValid(
                'toggle-sync-list-'.$syncList->getId(),
                $token,
            )
        ) {
            $syncList->setIsEnabled(!$syncList->isEnabled());
            $this->entityManager->flush();

            $status = $syncList->isEnabled() ? 'enabled' : 'disabled';
            $this->addFlash(
                'success',
                sprintf(
                    'Sync list "%s" has been %s.',
                    $syncList->getName(),
                    $status,
                ),
            );
        }

        return $this->redirectToRoute('app_sync_list_index');
    }

    #[Route('/{id}/history', name: 'app_sync_list_history', methods: ['GET'])]
    public function history(Request $request, SyncList $syncList): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $syncRuns = $this->syncRunRepository->findBySyncList(
            $syncList,
            $limit,
            $offset,
        );

        return $this->render('sync_list/history.html.twig', [
            'sync_list' => $syncList,
            'sync_runs' => $syncRuns,
            'page' => $page,
            'has_more' => count($syncRuns) === $limit,
        ]);
    }

    #[Route('/{id}/sync', name: 'app_sync_list_run', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function runSync(Request $request, SyncList $syncList): Response
    {
        $token = $request->request->get('_token');

        if (
            !$this->isCsrfTokenValid('sync-list-'.$syncList->getId(), $token)
        ) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_sync_list_history', [
                'id' => $syncList->getId(),
            ]);
        }

        $dryRun = $request->query->getBoolean('dry_run', false);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $result = $this->syncService->executeSync(
            $syncList,
            dryRun: $dryRun,
            triggeredBy: $user,
            trigger: 'manual',
        );

        if ($result->success) {
            $label = $dryRun ? 'Dry run' : 'Sync';
            $this->addFlash(
                'success',
                sprintf(
                    '%s completed for "%s": +%d added, -%d removed.',
                    $label,
                    $syncList->getName(),
                    $result->addedCount,
                    $result->removedCount,
                ),
            );
        } else {
            $this->addFlash(
                'danger',
                sprintf(
                    'Sync failed for "%s": %s',
                    $syncList->getName(),
                    $result->errorMessage ?? 'Unknown error',
                ),
            );
        }

        return $this->redirectToRoute('app_sync_list_history', [
            'id' => $syncList->getId(),
        ]);
    }
}
