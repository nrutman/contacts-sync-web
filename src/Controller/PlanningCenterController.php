<?php

namespace App\Controller;

use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Entity\SyncList;
use App\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PlanningCenterController extends AbstractController
{
    public function __construct(
        private readonly PlanningCenterClientFactory $planningCenterClientFactory,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    #[Route('/lists/{id}/refresh', name: 'app_sync_list_refresh', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function refresh(Request $request, SyncList $syncList): Response
    {
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('refresh-sync-list-'.$syncList->getId(), $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_sync_list_history', ['id' => $syncList->getId()]);
        }

        $organization = $syncList->getOrganization();

        try {
            $planningCenterClient = $this->planningCenterClientFactory->create($organization);
            $planningCenterClient->refreshList($syncList->getName());

            $this->addFlash('success', sprintf(
                'Planning Center list "%s" has been refreshed.',
                $syncList->getName(),
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf(
                'Failed to refresh Planning Center list "%s": %s',
                $syncList->getName(),
                $e->getMessage(),
            ));
        }

        return $this->redirectToRoute('app_sync_list_history', ['id' => $syncList->getId()]);
    }
}
