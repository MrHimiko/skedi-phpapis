<?php
// File: src/Plugins/Events/Controller/PublicBookingController.php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventBookingService;
use App\Service\CrudManager;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventGuestEntity;
use App\Plugins\Email\Service\EmailService;

#[Route('/api/public/bookings')]
class PublicBookingController extends AbstractController
{
    private ResponseService $responseService;
    private EventBookingService $bookingService;
    private CrudManager $crudManager;
    private EmailService $emailService;

    public function __construct(
        ResponseService $responseService,
        EventBookingService $bookingService,
        CrudManager $crudManager,
        EmailService $emailService
    ) {
        $this->responseService = $responseService;
        $this->bookingService = $bookingService;
        $this->crudManager = $crudManager;
        $this->emailService = $emailService;
    }

    #[Route('/{token}', name: 'public_booking_get', methods: ['GET'])]
    public function getBooking(string $token): JsonResponse
    {
        try {
            $bookings = $this->crudManager->findMany(
                EventBookingEntity::class,
                [],
                1,
                1,
                ['bookingToken' => $token]
            );

            if (empty($bookings)) {
                return $this->responseService->json(false, 'Booking not found', null, 404);
            }

            $booking = $bookings[0];
            $event = $booking->getEvent();
            $organization = $event->getOrganization();

            $data = [
                'id' => $booking->getId(),
                'status' => $booking->getStatus(),
                'start_time' => $booking->getStartTime()->format('c'),
                'end_time' => $booking->getEndTime()->format('c'),
                'cancelled' => $booking->isCancelled(),
                'event' => [
                    'id' => $event->getId(),
                    'name' => $event->getName(),
                    'slug' => $event->getSlug(),
                    'description' => $event->getDescription()
                ],
                'organization' => [
                    'id' => $organization->getId(),
                    'name' => $organization->getName(),
                    'slug' => $organization->getSlug()
                ],
                'form_data' => $booking->getFormDataAsArray()
            ];

            // Add assigned_to info if booking was routed
            $assignedTo = $booking->getAssignedTo();
            if ($assignedTo) {
                $data['assigned_to'] = [
                    'id' => $assignedTo->getId(),
                    'name' => $assignedTo->getName(),
                    'email' => $assignedTo->getEmail()
                ];
            }

            return $this->responseService->json(true, 'Booking found', $data);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/{token}/cancel', name: 'public_booking_cancel', methods: ['POST'])]
    public function cancelBooking(string $token, Request $request): JsonResponse
    {
        try {
            $bookings = $this->crudManager->findMany(
                EventBookingEntity::class,
                [],
                1,
                1,
                ['bookingToken' => $token]
            );

            if (empty($bookings)) {
                return $this->responseService->json(false, 'Booking not found', null, 404);
            }

            $booking = $bookings[0];

            if ($booking->isCancelled()) {
                return $this->responseService->json(false, 'Booking is already cancelled', null, 400);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $reason = $data['reason'] ?? null;

            // Cancel the booking
            $booking->setCancelled(true);
            $booking->setStatus('canceled');

            // Store cancellation reason in form data if provided
            if ($reason) {
                $formData = $booking->getFormDataAsArray() ?? [];
                $formData['cancellation_reason'] = $reason;
                $formData['cancelled_at'] = (new \DateTime())->format('c');
                $formData['cancelled_by'] = 'guest';
                $booking->setFormDataFromArray($formData);
            }

            $this->bookingService->update($booking, []);

            // Send cancellation email
            $this->sendCancellationEmail($booking, $reason);

            return $this->responseService->json(true, 'Booking cancelled successfully');

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    private function sendCancellationEmail(EventBookingEntity $booking, ?string $reason): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            $organization = $event->getOrganization();

            $guestEmail = $formData['primary_contact']['email'] ?? null;
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';

            if (!$guestEmail) {
                return;
            }

            $startTime = $booking->getStartTime();

            // Send to guest
            $this->emailService->send(
                $guestEmail,
                'booking_cancelled',
                [
                    'guest_name' => $guestName,
                    'event_name' => $event->getName(),
                    'event_date' => $startTime->format('F j, Y'),
                    'event_time' => $startTime->format('g:i A'),
                    'company_name' => $organization->getName(),
                    'cancellation_reason' => $reason
                ]
            );

            // Send to host(s)
            $organizer = $event->getCreatedBy();
            if ($organizer) {
                $this->emailService->send(
                    $organizer->getEmail(),
                    'booking_cancelled_host',
                    [
                        'host_name' => $organizer->getName(),
                        'guest_name' => $guestName,
                        'guest_email' => $guestEmail,
                        'event_name' => $event->getName(),
                        'event_date' => $startTime->format('F j, Y'),
                        'event_time' => $startTime->format('g:i A'),
                        'company_name' => $organization->getName(),
                        'cancellation_reason' => $reason
                    ]
                );
            }

        } catch (\Exception $e) {
            // Log error but don't fail the cancellation
            error_log('Failed to send cancellation email: ' . $e->getMessage());
        }
    }
}