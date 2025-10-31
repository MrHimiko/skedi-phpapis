<?php
// src/Plugins/Billing/Service/BillingService.php

namespace App\Plugins\Billing\Service;

use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Entity\BillingPlanEntity;
use App\Plugins\Billing\Repository\BillingPlanRepository;
use App\Plugins\Billing\Repository\OrganizationSubscriptionRepository;
use App\Service\CrudManager;
use App\Plugins\Invitations\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

class BillingService
{
    const PLAN_FREE = 1;
    const PLAN_PROFESSIONAL = 2;
    const PLAN_BUSINESS = 3;
    const PLAN_ENTERPRISE = 4;

    private array $planLevels = [
        'free' => self::PLAN_FREE,
        'professional' => self::PLAN_PROFESSIONAL,
        'business' => self::PLAN_BUSINESS,
        'enterprise' => self::PLAN_ENTERPRISE
    ];

    public function __construct(
        private CrudManager $crudManager,
        private BillingPlanRepository $planRepository,
        private OrganizationSubscriptionRepository $subscriptionRepository,
        private InvitationRepository $invitationRepository,
        private EntityManagerInterface $entityManager
    ) {}

    public function getOrganizationPlanLevel(OrganizationEntity $organization): int
    {
        $subscription = $this->subscriptionRepository->findOneBy([
            'organization' => $organization
        ]);
        
        if (!$subscription || !$subscription->isActive()) {
            return self::PLAN_FREE;
        }
        
        $planSlug = $subscription->getPlan()->getSlug();
        return $this->planLevels[$planSlug] ?? self::PLAN_FREE;
    }

    public function getOrganizationSubscription(OrganizationEntity $organization): ?OrganizationSubscriptionEntity
    {
        return $this->subscriptionRepository->findOneBy(['organization' => $organization]);
    }

    /**
     * Check if organization can add a new member
     */
    public function canAddMember(OrganizationEntity $organization): bool
    {
        $seatInfo = $this->getOrganizationSeatInfo($organization);
        return $seatInfo['used'] < $seatInfo['total'];
    }

    /**
     * Get detailed seat information for an organization
     */
    public function getOrganizationSeatInfo(OrganizationEntity $organization): array
    {
        $subscription = $this->getOrganizationSubscription($organization);
        $currentMembers = $this->getCurrentMemberCount($organization);
        $pendingInvitations = $this->getPendingInvitationCount($organization);
        
        if (!$subscription || !$subscription->isActive()) {
            // Free plan: 1 seat total
            return [
                'total' => 1,
                'used' => $currentMembers + $pendingInvitations,
                'available' => max(0, 1 - ($currentMembers + $pendingInvitations)),
                'members' => $currentMembers,
                'pending' => $pendingInvitations,
                'additional_seats' => 0
            ];
        }
        
        // Paid plan: base seats + additional seats
        $baseSeat = 1; // All plans include 1 base seat
        $additionalSeats = $subscription->getAdditionalSeats();
        $totalSeats = $baseSeat + $additionalSeats;
        $usedSeats = $currentMembers + $pendingInvitations;
        
        return [
            'total' => $totalSeats,
            'used' => $usedSeats,
            'available' => max(0, $totalSeats - $usedSeats),
            'members' => $currentMembers,
            'pending' => $pendingInvitations,
            'additional_seats' => $additionalSeats
        ];
    }

    /**
     * Get count of active members in organization
     */
    private function getCurrentMemberCount(OrganizationEntity $organization): int
    {
        return $this->entityManager->createQueryBuilder()
            ->select('COUNT(uo.id)')
            ->from('App\Plugins\Organizations\Entity\UserOrganizationEntity', 'uo')
            ->where('uo.organization = :organization')
            ->setParameter('organization', $organization)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get count of pending invitations for organization
     */
    private function getPendingInvitationCount(OrganizationEntity $organization): int
    {
        return $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from('App\Plugins\Invitations\Entity\InvitationEntity', 'i')
            ->where('i.organization = :organization')
            ->andWhere('i.status = :status')
            ->andWhere('i.deleted = false')
            ->setParameter('organization', $organization)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Check if organization is compliant with seat limits
     * Returns array with compliance status and details
     */
    public function checkOrganizationCompliance(OrganizationEntity $organization): array
    {
        $seatInfo = $this->getOrganizationSeatInfo($organization);
        
        $isCompliant = $seatInfo['used'] <= $seatInfo['total'];
        $overageCount = max(0, $seatInfo['used'] - $seatInfo['total']);
        
        return [
            'is_compliant' => $isCompliant,
            'seat_info' => $seatInfo,
            'overage_count' => $overageCount,
            'required_additional_seats' => $overageCount
        ];
    }

    /**
     * Get organizations that are non-compliant (more members than seats)
     */
    public function getNonCompliantOrganizations(): array
    {
        $organizations = $this->entityManager->getRepository(OrganizationEntity::class)
            ->findBy(['deleted' => false]);
            
        $nonCompliant = [];
        
        foreach ($organizations as $organization) {
            $compliance = $this->checkOrganizationCompliance($organization);
            if (!$compliance['is_compliant']) {
                $nonCompliant[] = [
                    'organization' => $organization,
                    'compliance' => $compliance
                ];
            }
        }
        
        return $nonCompliant;
    }

    public function getPlanBySlug(string $slug): ?BillingPlanEntity
    {
        return $this->planRepository->findOneBy(['slug' => $slug]);
    }

    public function getPlanByStripePriceId(string $stripePriceId): ?BillingPlanEntity
    {
        return $this->planRepository->findOneBy(['stripe_price_id' => $stripePriceId]);
    }

    public function getAvailablePlans(): array
    {
        return $this->planRepository->findBy(
            ['slug' => ['professional', 'business']], 
            ['priceMonthly' => 'ASC']
        );
    }

    /**
     * Calculate how many additional seats are needed
     */
    public function calculateRequiredSeats(OrganizationEntity $organization, int $newInvitations = 1): int
    {
        $seatInfo = $this->getOrganizationSeatInfo($organization);
        $totalNeeded = $seatInfo['used'] + $newInvitations;
        $currentTotal = $seatInfo['total'];
        
        return max(0, $totalNeeded - $currentTotal);
    }
}