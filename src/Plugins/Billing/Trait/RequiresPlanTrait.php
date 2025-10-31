<?php
// src/Plugins/Billing/Trait/RequiresPlanTrait.php

namespace App\Plugins\Billing\Trait;

use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Billing\Service\BillingService;
use Symfony\Component\HttpFoundation\JsonResponse;

trait RequiresPlanTrait
{
    protected function requirePlan(
        OrganizationEntity $organization, 
        int $requiredPlanLevel,
        string $feature = 'This feature'
    ): ?JsonResponse {
        $billingService = $this->container->get(BillingService::class);
        $currentLevel = $billingService->getOrganizationPlanLevel($organization);
        
        if ($currentLevel < $requiredPlanLevel) {
            return $this->responseService->json(
                false, 
                "$feature is not available on your current plan. Please upgrade.",
                ['upgrade_required' => true],
                403
            );
        }
        
        return null;
    }

    protected function requireAvailableSeats(OrganizationEntity $organization): ?JsonResponse
    {
        $billingService = $this->container->get(BillingService::class);
        
        if (!$billingService->canAddMember($organization)) {
            $currentLevel = $billingService->getOrganizationPlanLevel($organization);
            
            if ($currentLevel === BillingService::PLAN_FREE) {
                return $this->responseService->json(
                    false,
                    'Free plan only allows 1 member. Please upgrade.',
                    ['upgrade_required' => true],
                    403
                );
            }
            
            return $this->responseService->json(
                false,
                'No seats available. Please purchase additional seats.',
                ['seats_required' => true],
                403
            );
        }
        
        return null;
    }
}