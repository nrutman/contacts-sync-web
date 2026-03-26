<?php

namespace App\Controller;

use App\Client\Provider\RefreshableProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Entity\SyncList;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PlanningCenterController extends AbstractController
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly LoggerInterface $logger,
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

        $sourceCredential = $syncList->getSourceCredential();

        if ($sourceCredential === null) {
            $this->addFlash(
                'warning',
                sprintf('Sync list "%s" has no source credential configured.', $syncList->getName()),
            );

            return $this->redirectToRoute('app_sync_list_history', [
                'id' => $syncList->getId(),
            ]);
        }

        try {
            $provider = $this->providerRegistry->get($sourceCredential->getProviderName());

            if ($provider instanceof RefreshableProviderInterface) {
                $provider->refreshList(
                    $sourceCredential,
                    $syncList->getSourceListIdentifier() ?? $syncList->getName(),
                );

                $this->addFlash(
                    'success',
                    sprintf('Source list "%s" refreshed successfully.', $syncList->getName()),
                );
            } else {
                $this->addFlash(
                    'warning',
                    sprintf('Provider "%s" does not support refreshing source lists.', $provider->getDisplayName()),
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to refresh source list "{name}": {error}', [
                'name' => $syncList->getName(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->addFlash(
                'danger',
                sprintf('Failed to refresh "%s": %s', $syncList->getName(), $e->getMessage()),
            );
        }

        return $this->redirectToRoute('app_sync_list_history', [
            'id' => $syncList->getId(),
        ]);
    }
}
