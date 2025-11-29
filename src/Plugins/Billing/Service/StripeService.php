<?php
// src/Plugins/Billing/Service/StripeService.php

namespace App\Plugins\Billing\Service;

use Stripe\StripeClient;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Entity\BillingPlanEntity;
use Doctrine\ORM\EntityManagerInterface;

class StripeService
{
    private StripeClient $stripe;
    private string $additionalSeatsPriceId; // Price ID for seats (e.g., price_seats_9usd)

    public function __construct(
        string $stripeSecretKey,
        private EntityManagerInterface $entityManager,
        ?string $additionalSeatsPriceId = null
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
        // Get the price ID from environment if not injected
        $this->additionalSeatsPriceId = $additionalSeatsPriceId ?: ($_ENV['STRIPE_ADDITIONAL_SEATS_PRICE_ID'] ?? '');
    }

    /**
     * Create checkout session for new subscription with optional seats
     */
    public function createCheckoutSession(
        OrganizationEntity $organization,
        BillingPlanEntity $plan,
        int $additionalSeats = 0
    ): string {
        $lineItems = [
            [
                'price' => $plan->getStripePriceId(),
                'quantity' => 1,
            ]
        ];
        
        // Add seats as a subscription item if requested
        if ($additionalSeats > 0) {
            if (!$this->additionalSeatsPriceId) {
                throw new \Exception('Additional seats price ID not configured');
            }
            
            $lineItems[] = [
                'price' => $this->additionalSeatsPriceId,
                'quantity' => $additionalSeats,
            ];
        }
        
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => $lineItems,
            'success_url' => $_ENV['APP_URL'] . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $_ENV['APP_URL'] . '/billing/cancel',
            'metadata' => [
                'organization_id' => $organization->getId(),
                'plan_id' => $plan->getId(),
                'additional_seats' => $additionalSeats
            ],
            'subscription_data' => [
                'metadata' => [
                    'organization_id' => $organization->getId(),
                    'plan_id' => $plan->getId(),
                    'additional_seats' => $additionalSeats
                ]
            ]
        ]);
        
        return $session->url;
    }

    /**
     * Create checkout session specifically for adding seats to existing subscription
     */
    public function createSeatsCheckoutSession(
        OrganizationSubscriptionEntity $subscription,
        int $seatsToAdd
    ): string {
        if (!$this->additionalSeatsPriceId) {
            throw new \Exception('Additional seats price ID not configured');
        }
        
        if (!$subscription->getStripeCustomerId()) {
            throw new \Exception('No Stripe customer found for this subscription');
        }

        if (!$subscription->getStripeSubscriptionId()) {
            throw new \Exception('No active subscription found');
        }

        // Create a one-time payment for the prorated seats amount
        // Then update the subscription after payment
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $subscription->getStripeCustomerId(),
            'line_items' => [[
                'price' => $this->additionalSeatsPriceId,
                'quantity' => $seatsToAdd,
            ]],
            'success_url' => $_ENV['APP_URL'] . '/billing/seats/success?session_id={CHECKOUT_SESSION_ID}&seats=' . $seatsToAdd,
            'cancel_url' => $_ENV['APP_URL'] . '/billing/cancel',
            'metadata' => [
                'organization_id' => $subscription->getOrganization()->getId(),
                'seats_to_add' => $seatsToAdd,
                'action' => 'add_seats',
                'subscription_id' => $subscription->getStripeSubscriptionId()
            ]
        ]);
        
        return $session->url;
    }

    /**
     * Add seats to existing subscription (updates quantity on seats subscription item)
     */
    public function addSeats(OrganizationSubscriptionEntity $subscription, int $seatsToAdd): void
    {
        if (!$subscription->getStripeSubscriptionId()) {
            throw new \Exception('No active Stripe subscription found');
        }

        $stripeSubscription = $this->stripe->subscriptions->retrieve(
            $subscription->getStripeSubscriptionId()
        );
        
        // Find the seat subscription item
        $seatItem = null;
        foreach ($stripeSubscription->items->data as $item) {
            if ($item->price->id === $this->additionalSeatsPriceId) {
                $seatItem = $item;
                break;
            }
        }
        
        if ($seatItem) {
            // Update existing seat item quantity
            $this->stripe->subscriptionItems->update($seatItem->id, [
                'quantity' => $seatItem->quantity + $seatsToAdd,
                'proration_behavior' => 'create_prorations' // Charge immediately for the remainder of the period
            ]);
            
            // Store the subscription item ID for future reference
            $subscription->setSeatsSubscriptionItemId($seatItem->id);
        } else {
            // Add new subscription item for seats
            $newItem = $this->stripe->subscriptionItems->create([
                'subscription' => $subscription->getStripeSubscriptionId(),
                'price' => $this->additionalSeatsPriceId,
                'quantity' => $seatsToAdd,
                'proration_behavior' => 'create_prorations'
            ]);
            
            // Store the subscription item ID
            $subscription->setSeatsSubscriptionItemId($newItem->id);
        }
        
        // Update local database
        $subscription->setAdditionalSeats($subscription->getAdditionalSeats() + $seatsToAdd);
        $this->entityManager->flush();
    }

    /**
     * Remove seats from subscription
     */
    public function removeSeats(OrganizationSubscriptionEntity $subscription, int $seatsToRemove): void
    {
        if (!$subscription->getStripeSubscriptionId() || !$subscription->getSeatsSubscriptionItemId()) {
            throw new \Exception('No active seats subscription found');
        }

        $currentSeats = $subscription->getAdditionalSeats();
        $newSeatCount = max(0, $currentSeats - $seatsToRemove);

        if ($newSeatCount === 0) {
            // Remove the subscription item entirely
            $this->stripe->subscriptionItems->del($subscription->getSeatsSubscriptionItemId());
            $subscription->setSeatsSubscriptionItemId(null);
        } else {
            // Update quantity
            $this->stripe->subscriptionItems->update($subscription->getSeatsSubscriptionItemId(), [
                'quantity' => $newSeatCount,
                'proration_behavior' => 'create_prorations'
            ]);
        }

        // Update local database
        $subscription->setAdditionalSeats($newSeatCount);
        $this->entityManager->flush();
    }

    /**
     * Update seat count to specific number (used for compliance)
     */
    public function updateSeatCount(OrganizationSubscriptionEntity $subscription, int $targetSeatCount): void
    {
        $currentSeats = $subscription->getAdditionalSeats();
        
        if ($targetSeatCount > $currentSeats) {
            $this->addSeats($subscription, $targetSeatCount - $currentSeats);
        } elseif ($targetSeatCount < $currentSeats) {
            $this->removeSeats($subscription, $currentSeats - $targetSeatCount);
        }
    }

    public function createCustomerPortalSession(string $customerId): string
    {
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $_ENV['APP_URL'] . '/billing',
        ]);
        
        return $session->url;
    }

    /**
     * Process seats after successful checkout
     */
    public function processSeatsCheckout(string $sessionId): void
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId);
        
        if ($session->metadata->action === 'add_seats') {
            $organizationId = $session->metadata->organization_id;
            $seatsToAdd = (int) $session->metadata->seats_to_add;
            $subscriptionId = $session->metadata->subscription_id ?? null;
            
            // Find subscription
            $subscription = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
                ->findOneBy(['organization' => $organizationId]);
                
            if ($subscription && $subscriptionId) {
                // Update the subscription with the new seats
                $this->addSeats($subscription, $seatsToAdd);
            }
        }
    }


    /**
     * Add seats to an existing subscription
     * Stripe will automatically handle proration
     */
    public function updateSubscriptionSeats(
        OrganizationSubscriptionEntity $subscription,
        int $newTotalSeats
    ): void {
        if (!$subscription->getStripeSubscriptionId()) {
            throw new \Exception('No active subscription found');
        }
        
        if (!$this->additionalSeatsPriceId) {
            throw new \Exception('Additional seats price ID not configured');
        }
        
        try {
            // Get the current subscription from Stripe
            $stripeSubscription = $this->stripe->subscriptions->retrieve(
                $subscription->getStripeSubscriptionId()
            );
            
            // Find the seats subscription item
            $seatsItemId = null;
            $currentSeatsItem = null;
            
            foreach ($stripeSubscription->items->data as $item) {
                if ($item->price->id === $this->additionalSeatsPriceId) {
                    $seatsItemId = $item->id;
                    $currentSeatsItem = $item;
                    break;
                }
            }
            
            if ($seatsItemId) {
                // Update existing seats item quantity
                $this->stripe->subscriptionItems->update(
                    $seatsItemId,
                    ['quantity' => $newTotalSeats]
                );
            } else {
                // Add seats item to subscription if it doesn't exist
                $this->stripe->subscriptionItems->create([
                    'subscription' => $subscription->getStripeSubscriptionId(),
                    'price' => $this->additionalSeatsPriceId,
                    'quantity' => $newTotalSeats
                ]);
            }
            
            // Update local database
            $subscription->setAdditionalSeats($newTotalSeats);
            $this->entityManager->flush();
            
            // Remove logger calls - just let exceptions bubble up
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \Exception('Failed to update subscription seats: ' . $e->getMessage());
        }
    }



    public function getCustomerInvoices(?string $stripeCustomerId, int $limit = 100): array
    {
        if (!$stripeCustomerId) {
            return [];
        }

        try {
            // Fetch invoices from Stripe API
            $invoices = $this->stripe->invoices->all([
                'customer' => $stripeCustomerId,
                'limit' => $limit
            ]);

            // Format invoices for frontend
            $formattedInvoices = [];
            foreach ($invoices->data as $invoice) {
                $formattedInvoices[] = [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'amount' => $invoice->amount_paid / 100, // Convert cents to dollars
                    'currency' => strtoupper($invoice->currency),
                    'status' => $invoice->status,
                    'created' => date('Y-m-d H:i:s', $invoice->created),
                    'due_date' => $invoice->due_date ? date('Y-m-d', $invoice->due_date) : null,
                    'paid_at' => $invoice->status_transitions->paid_at ? date('Y-m-d H:i:s', $invoice->status_transitions->paid_at) : null,
                    'invoice_pdf' => $invoice->invoice_pdf,
                    'hosted_invoice_url' => $invoice->hosted_invoice_url,
                    'period_start' => date('Y-m-d', $invoice->period_start),
                    'period_end' => date('Y-m-d', $invoice->period_end),
                    'description' => $invoice->description ?? '',
                    'subtotal' => $invoice->subtotal / 100,
                    'tax' => $invoice->tax ? $invoice->tax / 100 : 0,
                    'total' => $invoice->total / 100
                ];
            }

            return $formattedInvoices;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \Exception('Failed to fetch invoices: ' . $e->getMessage());
        }
    }


    

}