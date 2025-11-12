<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventBookingService;
use App\Service\CrudManager;
use App\Plugins\Events\Entity\EventBookingEntity;
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

            // Check if already cancelled
            if ($booking->isCancelled()) {
                return $this->responseService->json(false, 'Booking is already cancelled');
            }

            // Check if booking is in the past
            $now = new \DateTime();
            if ($booking->getEndTime() < $now) {
                return $this->responseService->json(false, 'Cannot cancel past bookings');
            }

            // Get cancellation reason if provided
            $data = json_decode($request->getContent(), true) ?: [];
            $reason = $data['reason'] ?? null;

            // Cancel the booking
            $this->bookingService->update($booking, [
                'status' => 'canceled',
                'cancelled' => true
            ]);

            // Send cancellation email to host with reason
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
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'] ?? '';

            // Get event assignees (hosts)
            $assignees = $this->crudManager->findMany(
                'App\Plugins\Events\Entity\EventAssigneeEntity',
                [],
                1,
                100,
                ['event' => $event]
            );

            foreach ($assignees as $assignee) {
                $this->emailService->send(
                    $assignee->getUser()->getEmail(),
                    'booking_cancelled_by_guest',
                    [
                        'host_name' => $assignee->getUser()->getName(),
                        'guest_name' => $guestName,
                        'guest_email' => $guestEmail,
                        'event_name' => $event->getName(),
                        'meeting_date' => $booking->getStartTime()->format('F j, Y'),
                        'meeting_time' => $booking->getStartTime()->format('g:i A'),
                        'cancellation_reason' => $reason ?: 'No reason provided'
                    ]
                );
            }
        } catch (\Exception $e) {
            error_log('Failed to send cancellation email: ' . $e->getMessage());
        }
    }
}