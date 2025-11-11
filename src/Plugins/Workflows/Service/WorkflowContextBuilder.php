<?php

namespace App\Plugins\Workflows\Service;

use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;

class WorkflowContextBuilder
{
    /**
     * Build context from a booking entity
     * This creates all the variables available in workflows like {{booking.customer_email}}
     */
    public function buildFromBooking(EventBookingEntity $booking): array
    {
        $event = $booking->getEvent();
        $organization = $event->getOrganization();
        $formData = $booking->getFormDataAsArray() ?: [];

        // Extract customer info from form_data
        $customerName = '';
        $customerEmail = '';
        $customerPhone = '';

        if (isset($formData['primary_contact'])) {
            $customerName = $formData['primary_contact']['name'] ?? '';
            $customerEmail = $formData['primary_contact']['email'] ?? '';
            $customerPhone = $formData['primary_contact']['phone'] ?? '';
        }

        // Build context array
        $context = [
            'booking' => [
                'id' => $booking->getId(),
                'status' => $booking->getStatus(),
                'start_time' => $booking->getStartTime()->format('Y-m-d H:i:s'),
                'end_time' => $booking->getEndTime()->format('Y-m-d H:i:s'),
                'date' => $booking->getStartTime()->format('Y-m-d'),
                'time' => $booking->getStartTime()->format('H:i'),
                'cancelled' => $booking->isCancelled(),
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'form_data' => $formData
            ],
            'event' => [
                'id' => $event->getId(),
                'name' => $event->getName(),
                'slug' => $event->getSlug(),
                'description' => $event->getDescription(),
                'duration' => $event->getDuration(),
                'location' => $this->formatLocation($event->getLocation()),
            ],
            'organization' => [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'slug' => $organization->getSlug()
            ]
        ];

        // Add host information if available
        $createdBy = $event->getCreatedBy();
        if ($createdBy) {
            $context['host'] = [
                'id' => $createdBy->getId(),
                'name' => $createdBy->getName(),
                'email' => $createdBy->getEmail()
            ];
        }

        return $context;
    }

    /**
     * Format location for context
     */
    private function formatLocation($location): string
    {
        if (empty($location)) {
            return '';
        }

        if (is_string($location)) {
            return $location;
        }

        if (is_array($location)) {
            // Handle different location formats
            if (isset($location['type'])) {
                $type = $location['type'];
                
                switch ($type) {
                    case 'google_meet':
                        return 'Google Meet';
                    case 'zoom':
                        return 'Zoom';
                    case 'teams':
                        return 'Microsoft Teams';
                    case 'in_person':
                        return $location['address'] ?? 'In Person';
                    case 'phone':
                        return $location['phone'] ?? 'Phone Call';
                    default:
                        return ucfirst($type);
                }
            }

            // If it's an array of locations, get the first one
            if (isset($location[0]) && isset($location[0]['type'])) {
                return $this->formatLocation($location[0]);
            }

            // Last resort - JSON encode it
            return json_encode($location);
        }

        return '';
    }

    /**
     * Build context for testing with fake data
     */
    public function buildFakeContext(): array
    {
        return [
            'booking' => [
                'id' => 123,
                'status' => 'confirmed',
                'start_time' => '2025-11-15 14:00:00',
                'end_time' => '2025-11-15 15:00:00',
                'date' => '2025-11-15',
                'time' => '14:00',
                'cancelled' => false,
                'customer_name' => 'John Doe',
                'customer_email' => 'john@example.com',
                'customer_phone' => '+1234567890',
                'form_data' => []
            ],
            'event' => [
                'id' => 45,
                'name' => 'Discovery Call',
                'slug' => 'discovery-call',
                'description' => 'Initial consultation meeting',
                'duration' => ['30'],
                'location' => 'Google Meet'
            ],
            'organization' => [
                'id' => 1,
                'name' => 'Acme Corp',
                'slug' => 'acme-corp'
            ],
            'host' => [
                'id' => 1,
                'name' => 'Jane Smith',
                'email' => 'jane@acme.com'
            ]
        ];
    }
}