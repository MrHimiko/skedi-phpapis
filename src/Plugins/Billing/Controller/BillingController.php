<?php
// src/Plugins/Billing/Controller/BillingController.php

namespace App\Plugins\Billing\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Billing\Service\BillingService;
use App\Plugins\Billing\Service\StripeService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use Psr\Log\LoggerInterface;


#[Route('/api/billing')]
class BillingController extends AbstractController
{
    public function __construct(
        private ResponseService $responseService,
        private BillingService $billingService,
        private StripeService $stripeService,
        private OrganizationService $organizationService,
        private UserOrganizationService $userOrganizationService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    #[Route('/plans', name: 'billing_plans#', methods: ['GET'])]
    public function getPlans(): JsonResponse
    {
        try {
            $plans = $this->billingService->getAvailablePlans();
            
            $plansArray = array_map(function($plan) {
                return $plan->toArray();
            }, $plans);
            
            return $this->responseService->json(true, 'Plans retrieved', $plansArray);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/subscription', name: 'billing_get_subscription#', methods: ['GET'])]
    public function getSubscription(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            if (!$this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            $planLevel = $this->billingService->getOrganizationPlanLevel($organization);
            
            return $this->responseService->json(true, 'success', [
                'subscription' => $subscription?->toArray(),
                'plan_level' => $planLevel,
                'can_add_members' => $this->billingService->canAddMember($organization)
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/checkout', name: 'billing_checkout#', methods: ['POST'])]
    public function createCheckoutSession(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            $planSlug = $data['plan_slug'] ?? '';
            $additionalSeats = (int) ($data['additional_seats'] ?? 0);
            
            $plan = $this->billingService->getPlanBySlug($planSlug);
            if (!$plan || !$plan->getStripePriceId()) {
                return $this->responseService->json(false, 'Invalid plan selected', null, 400);
            }
            
            $checkoutUrl = $this->stripeService->createCheckoutSession(
                $organization,
                $plan,
                $additionalSeats
            );
            
            return $this->responseService->json(true, 'Checkout session created', [
                'checkout_url' => $checkoutUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/stripe/session-verify', name: 'billing_verify_session#', methods: ['POST'])]
    public function verifyStripeSession(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $sessionId = $data['session_id'] ?? '';
            $organizationId = $data['organization_id'] ?? 0;
            
            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            if (!$this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            return $this->responseService->json(true, 'Payment verified', [
                'plan_name' => 'Professional'
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/portal', name: 'billing_portal#', methods: ['POST'])]
    public function createPortalSession(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            
            if (!$subscription || !$subscription->getStripeCustomerId()) {
                return $this->responseService->json(false, 'No billing account found', null, 400);
            }
            
            $portalUrl = $this->stripeService->createCustomerPortalSession(
                $subscription->getStripeCustomerId()
            );
            
            return $this->responseService->json(true, 'Portal session created', [
                'url' => $portalUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


 
    #[Route('/organizations/{organization_id}/seats', name: 'billing_purchase_seats#', methods: ['POST'])]
    public function purchaseSeats(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            // Check admin access
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            // Validate seats input
            $seatsToAdd = (int) ($data['seats'] ?? 0);
            if ($seatsToAdd <= 0 || $seatsToAdd > 100) {
                return $this->responseService->json(false, 'Invalid number of seats. Must be between 1 and 100.', null, 400);
            }
            
            // Get current subscription
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            
            if (!$subscription || !$subscription->isActive()) {
                return $this->responseService->json(
                    false, 
                    'Please select a subscription plan first before adding seats.', 
                    ['requires_plan' => true],
                    400
                );
            }
            
            // Calculate new total seats
            $currentSeats = $subscription->getAdditionalSeats();
            $newTotalSeats = $currentSeats + $seatsToAdd;
            
            // Update subscription seats directly (Stripe handles proration automatically)
            try {
                $this->stripeService->updateSubscriptionSeats($subscription, $newTotalSeats);
                
                return $this->responseService->json(true, 'Seats added successfully', [
                    'seats_added' => $seatsToAdd,
                    'new_total_seats' => $newTotalSeats,
                    'previous_seats' => $currentSeats,
                    'message' => "Successfully added {$seatsToAdd} seats. You'll be charged a prorated amount for the current billing period."
                ]);
                
            } catch (\Exception $e) {
                // Log the error
                $this->logger->error('Failed to update subscription seats', [
                    'organization_id' => $organization_id,
                    'error' => $e->getMessage()
                ]);
                
                return $this->responseService->json(
                    false, 
                    'Failed to add seats. Please try again or contact support.', 
                    ['error' => $e->getMessage()],
                    500
                );
            }
            
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }



    #[Route('/organizations/{organization_id}/invoices', name: 'billing_get_invoices#', methods: ['GET'])]
    public function getInvoices(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // 1. Get organization
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            // 2. Check user has access to this organization (any member can see invoices)
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            // 3. Get organization's subscription
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            
            // 4. If no subscription or no stripe customer, return empty list
            if (!$subscription || !$subscription->getStripeCustomerId()) {
                return $this->responseService->json(true, 'No invoices found', [
                    'invoices' => [],
                    'count' => 0
                ]);
            }
            
            // 5. Fetch invoices from Stripe
            $invoices = $this->stripeService->getCustomerInvoices($subscription->getStripeCustomerId());
            
            return $this->responseService->json(true, 'Invoices retrieved', [
                'invoices' => $invoices,
                'count' => count($invoices),
                'organization_name' => $organization->getName(),
                'stripe_customer_id' => $subscription->getStripeCustomerId()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error fetching invoices: ' . $e->getMessage());
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }



    #[Route('/invoices', name: 'billing_get_all_invoices#', methods: ['GET'])]
    public function getAllInvoices(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Get all organizations where user is admin
            $userOrganizations = $this->userOrganizationService->getOrganizationsByUser($user);
            
            $allInvoices = [];
            $organizationIds = []; // Track unique organization IDs
            
            foreach ($userOrganizations as $userOrg) {
                // Only include if user is admin
                if ($userOrg->role !== 'admin') {
                    continue;
                }
                
                $organization = $userOrg->entity;
                $orgId = $organization->getId();
                
                // Get subscription for this organization
                $subscription = $this->billingService->getOrganizationSubscription($organization);
                
                // Skip if no subscription or no stripe customer
                if (!$subscription || !$subscription->getStripeCustomerId()) {
                    continue;
                }
                
                // Fetch invoices from Stripe
                try {
                    $invoices = $this->stripeService->getCustomerInvoices($subscription->getStripeCustomerId());
                    
                    // Add organization info to each invoice
                    foreach ($invoices as &$invoice) {
                        $invoice['organization'] = [
                            'id' => $orgId,
                            'name' => $organization->getName(),
                            'slug' => $organization->getSlug()
                        ];
                    }
                    
                    $allInvoices = array_merge($allInvoices, $invoices);
                    $organizationIds[$orgId] = true; // Track this org
                    
                } catch (\Exception $e) {
                    // Log error but continue with other organizations
                    $this->logger->warning('Failed to fetch invoices for organization ' . $orgId . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            // Sort by date (newest first)
            usort($allInvoices, function($a, $b) {
                return strtotime($b['created']) - strtotime($a['created']);
            });
            
            return $this->responseService->json(true, 'Invoices retrieved', [
                'invoices' => $allInvoices,
                'count' => count($allInvoices),
                'organizations_count' => count($organizationIds)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error fetching all invoices: ' . $e->getMessage());
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

}