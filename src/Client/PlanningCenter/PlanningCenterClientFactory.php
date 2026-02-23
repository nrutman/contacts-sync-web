<?php

namespace App\Client\PlanningCenter;

use App\Client\WebClientFactoryInterface;
use App\Entity\Organization;

class PlanningCenterClientFactory
{
    public function __construct(
        private readonly WebClientFactoryInterface $webClientFactory,
    ) {
    }

    /**
     * Creates a PlanningCenterClient configured for a specific organization.
     * Credentials are already decrypted by the Doctrine listener.
     */
    public function create(Organization $organization): PlanningCenterClient
    {
        return new PlanningCenterClient(
            $organization->getPlanningCenterAppId(),
            $organization->getPlanningCenterAppSecret(),
            $this->webClientFactory,
        );
    }
}
