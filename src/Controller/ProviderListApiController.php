<?php

namespace App\Controller;

use App\Client\Provider\ListDiscoverableInterface;
use App\Client\Provider\ProviderNotFoundException;
use App\Client\Provider\ProviderRegistry;
use App\Entity\ProviderCredential;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class ProviderListApiController extends AbstractController
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
    ) {
    }

    #[Route('/credentials/{id}/lists', name: 'api_credential_lists', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function lists(ProviderCredential $credential): JsonResponse
    {
        try {
            $provider = $this->providerRegistry->get($credential->getProviderName());
        } catch (ProviderNotFoundException) {
            return $this->json(['discoverable' => false]);
        }

        if (!$provider instanceof ListDiscoverableInterface) {
            return $this->json(['discoverable' => false]);
        }

        try {
            $lists = $provider->getAvailableLists($credential);
        } catch (\Throwable $e) {
            return $this->json([
                'discoverable' => true,
                'error' => $e->getMessage(),
            ], 500);
        }

        return $this->json([
            'discoverable' => true,
            'lists' => $lists,
        ]);
    }
}
