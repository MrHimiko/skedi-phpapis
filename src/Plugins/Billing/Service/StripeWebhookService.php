<?php
// src/Plugins/Billing/Service/StripeWebhookService.php

namespace App\Plugins\Billing\Service;

use Stripe\StripeClient;
use Stripe\Webhook;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Repository\BillingPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

class StripeWebhookService
{
    private StripeClient $stripe;
    private string $logFile = '/tmp/webhook_debug.log';
    
    public function __construct(
        private string $stripeSecretKey,
        private string $webhookSecret,
        private EntityManagerInterface $entityManager,
        private OrganizationService $organizationService,
        private BillingPlanRepository $planRepository,
        private ?string $additionalSeatsPriceId = null
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
        // Get the price ID from environment if not injected
        if (!$this->additionalSeatsPriceId) {
            $this->additionalSeatsPriceId = $_ENV['STRIPE_ADDITIONAL_SEATS_PRICE_ID'] ?? '';
        }
    }

    private function log($message, $data = null): void
    {
        $entry = date('Y-m-d H:i:s') . " - " . $message;
        if ($data !== null) {
            $entry .= " - " . json_encode($data);
        }
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        $this->log("handleWebhook started");
        
        try {
            if ($signature) {
                $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
            } else {
                $data = json_decode($payload, true);
                $event = $data;
            }
            
            $this->log("Event type", ['type' => $event['type']]);
            
            switch ($event['type']) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event['data']['object']);
                    break;
                    
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                    $this->handleSubscriptionEvent($event['data']['object']);
                    break;
            }
        } catch (\Exception $e) {
            $this->log("Exception in handleWebhook", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    private function handleCheckoutCompleted($session): void
    {
        $this->log("handleCheckoutCompleted", [
            'session_id' => $session['id'],
            'mode' => $session['mode'],
            'subscription_id' => $session['subscription'] ?? null,
            'metadata' => $session['metadata'] ?? []
        ]);
        
        // Only handle subscription mode checkouts
        if ($session['mode'] !== 'subscription') {
            $this->log("Not a subscription checkout, skipping");
            return;
        }
        
        // Extract metadata
        $organizationId = $session['metadata']['organization_id'] ?? null;
        $planId = $session['metadata']['plan_id'] ?? null;
        $additionalSeats = (int)($session['metadata']['additional_seats'] ?? 0);
        $subscriptionId = $session['subscription'] ?? null;
        
        if (!$organizationId || !$planId || !$subscriptionId) {
            $this->log("Missing required data", [
                'organization_id' => $organizationId,
                'plan_id' => $planId,
                'subscription_id' => $subscriptionId
            ]);
            return;
        }
        
        try {
            // Get organization
            $organization = $this->organizationService->getOne((int)$organizationId);
            if (!$organization) {
                $this->log("Organization not found", ['id' => $organizationId]);
                return;
            }
            
            // Get plan
            $plan = $this->planRepository->find((int)$planId);
            if (!$plan) {
                $this->log("Plan not found", ['id' => $planId]);
                return;
            }
            
            $this->log("Creating/updating subscription", [
                'organization' => $organization->getName(),
                'plan' => $plan->getName(),
                'seats' => $additionalSeats
            ]);
            
            // Find or create subscription
            $subscription = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
                ->findOneBy(['organization' => $organization]);
                
            if (!$subscription) {
                $subscription = new OrganizationSubscriptionEntity();
                $subscription->setOrganization($organization);
                $this->log("Created new subscription entity");
            } else {
                $this->log("Updating existing subscription", ['id' => $subscription->getId()]);
            }
            
            // Set all the data
            $subscription->setPlan($plan);
            $subscription->setStripeSubscriptionId($subscriptionId);
            $subscription->setStripeCustomerId($session['customer']);
            $subscription->setStatus('active');
            $subscription->setAdditionalSeats($additionalSeats);
            
            // Set period dates if available
            if (isset($session['created'])) {
                $subscription->setCurrentPeriodStart(new \DateTime('@' . $session['created']));
            }
            
            // Persist
            $this->entityManager->persist($subscription);
            $this->entityManager->flush();
            
            $this->log("Subscription saved successfully", [
                'subscription_id' => $subscription->getId(),
                'plan' => $plan->getName(),
                'seats' => $additionalSeats
            ]);
            
            // Also retrieve and update the Stripe subscription to ensure it has correct metadata
            try {
                $this->stripe->subscriptions->update($subscriptionId, [
                    'metadata' => [
                        'organization_id' => $organizationId,
                        'plan_id' => $planId,
                        'additional_seats' => $additionalSeats
                    ]
                ]);
                $this->log("Updated Stripe subscription metadata");
            } catch (\Exception $e) {
                $this->log("Failed to update Stripe subscription metadata", ['error' => $e->getMessage()]);
            }
            
        } catch (\Exception $e) {
            $this->log("Error in handleCheckoutCompleted", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function handleSubscriptionEvent($subscription): void
    {
        $this->log("handleSubscriptionEvent", [
            'subscription_id' => $subscription['id'],
            'status' => $subscription['status'],
            'metadata' => $subscription['metadata'] ?? []
        ]);
        
        // Try to find the subscription by Stripe ID
        $orgSubscription = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
            ->findOneBy(['stripeSubscriptionId' => $subscription['id']]);
            
        if (!$orgSubscription) {
            $this->log("Subscription not found in database", ['stripe_id' => $subscription['id']]);
            
            // If we have metadata, try to create it
            if (isset($subscription['metadata']['organization_id'])) {
                $this->createSubscriptionFromStripeData($subscription);
            }
            return;
        }
        
        // Update subscription status
        $orgSubscription->setStatus($subscription['status']);
        
        // Update period dates
        if (isset($subscription['current_period_start'])) {
            $orgSubscription->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
        }
        if (isset($subscription['current_period_end'])) {
            $orgSubscription->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
        }
        
        // Update seat count from subscription items
        if (is_array($subscription)) {
            $this->updateSeatCountFromSubscription($orgSubscription, $subscription);
        } else {
            // If it's a Stripe object, convert to array
            $this->updateSeatCountFromSubscription($orgSubscription, $subscription->toArray());
        }
        
        $this->entityManager->flush();
        
        $this->log("Subscription updated", [
            'id' => $orgSubscription->getId(),
            'status' => $orgSubscription->getStatus(),
            'seats' => $orgSubscription->getAdditionalSeats()
        ]);
    }

    private function createSubscriptionFromStripeData($subscription): void
    {
        $organizationId = $subscription['metadata']['organization_id'] ?? null;
        $planId = $subscription['metadata']['plan_id'] ?? null;
        
        if (!$organizationId) {
            $this->log("Cannot create subscription without organization_id");
            return;
        }
        
        try {
            $organization = $this->organizationService->getOne((int)$organizationId);
            if (!$organization) {
                $this->log("Organization not found", ['id' => $organizationId]);
                return;
            }
            
            // Try to determine plan from metadata or items
            $plan = null;
            if ($planId) {
                $plan = $this->planRepository->find((int)$planId);
            }
            
            if (!$plan) {
                // Try to find from subscription items
                foreach ($subscription['items']['data'] ?? [] as $item) {
                    $priceId = $item['price']['id'] ?? null;
                    if ($priceId && $priceId !== $this->additionalSeatsPriceId) {
                        // Query by the actual database column name
                        $plans = $this->planRepository->findAll();
                        foreach ($plans as $p) {
                            if ($p->getStripePriceId() === $priceId) {
                                $plan = $p;
                                break 2;
                            }
                        }
                    }
                }
            }
            
            if (!$plan) {
                // Default to professional
                $plan = $this->planRepository->findOneBy(['slug' => 'professional']);
                if (!$plan) {
                    $this->log("No plan found, cannot create subscription");
                    return;
                }
            }
            
            $orgSubscription = new OrganizationSubscriptionEntity();
            $orgSubscription->setOrganization($organization);
            $orgSubscription->setPlan($plan);
            $orgSubscription->setStripeSubscriptionId($subscription['id']);
            $orgSubscription->setStripeCustomerId($subscription['customer']);
            $orgSubscription->setStatus($subscription['status']);
            
            if (isset($subscription['current_period_start'])) {
                $orgSubscription->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
            }
            if (isset($subscription['current_period_end'])) {
                $orgSubscription->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
            }
            
            // Update seat count
            if (is_array($subscription)) {
                $this->updateSeatCountFromSubscription($orgSubscription, $subscription);
            } else {
                // If it's a Stripe object, convert to array
                $this->updateSeatCountFromSubscription($orgSubscription, $subscription->toArray());
            }
            
            $this->entityManager->persist($orgSubscription);
            $this->entityManager->flush();
            
            $this->log("Created subscription from Stripe data", [
                'id' => $orgSubscription->getId(),
                'plan' => $plan->getName(),
                'seats' => $orgSubscription->getAdditionalSeats()
            ]);
            
        } catch (\Exception $e) {
            $this->log("Error creating subscription from Stripe data", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function updateSeatCountFromSubscription(OrganizationSubscriptionEntity $orgSubscription, array $stripeSubscription): void
    {
        if (!$this->additionalSeatsPriceId) {
            $this->log("No additional seats price ID configured");
            return;
        }
        
        $seatCount = 0;
        $seatItemId = null;
        
        foreach ($stripeSubscription['items']['data'] ?? [] as $item) {
            if ($item['price']['id'] === $this->additionalSeatsPriceId) {
                $seatCount = $item['quantity'];
                $seatItemId = $item['id'];
                $this->log("Found seats in subscription", [
                    'quantity' => $seatCount,
                    'item_id' => $seatItemId
                ]);
                break;
            }
        }
        
        $orgSubscription->setAdditionalSeats($seatCount);
        if ($seatItemId) {
            $orgSubscription->setSeatsSubscriptionItemId($seatItemId);
        }
    }
}