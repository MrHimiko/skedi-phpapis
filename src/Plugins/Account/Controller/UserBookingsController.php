<?php

namespace App\Plugins\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Account\Repository\UserRepository;
use App\Plugins\Account\Entity\UserAvailabilityEntity;
use App\Plugins\Account\Exception\AccountException;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api')]
class UserBookingsController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        UserRepository $userRepository,
        CrudManager $crudManager,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->userRepository = $userRepository;
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
    }

    #[Route('/user/{id}/bookings', name: 'user_bookings_get#', methods: ['GET'])]
    public function getUserBookings(int $id, Request $request): JsonResponse
    {
        $authenticatedUser = $request->attributes->get('user');
        
        // Security check - only allow access to own data
        if ($authenticatedUser->getId() !== $id) {
            return $this->responseService->json(false, 'deny', null, 403);
        }
        
        try {
            $user = $this->userRepository->find($id);
            if (!$user) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }
            
            // Validate and parse request parameters
            $params = $this->validateBookingParams($request);
            if ($params instanceof JsonResponse) {
                return $params; // Return error response
            }
            
            // Get availability records
            $availabilityRecords = $this->getUserAvailabilityRecords($user, $params['startDate'], $params['endDate']);
            
            // Extract booking IDs
            $bookingIds = $this->extractBookingIds($availabilityRecords);
            
            if (empty($bookingIds)) {
                return $this->createEmptyResponse($params['page'], $params['limit']);
            }
            
            // Get and format bookings
            $formattedBookings = $this->getFormattedBookings($bookingIds, $availabilityRecords, $user, $params['status']);
            
            // Apply pagination
            return $this->createPaginatedResponse($formattedBookings, $params['page'], $params['limit']);
            
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    private function validateBookingParams(Request $request): array|JsonResponse
    {
        $startTime = $request->query->get('start_time');
        $endTime = $request->query->get('end_time');
        $status = $request->query->get('status', 'all');
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(10, (int)$request->query->get('page_size', 20)));
        
        if (!$startTime || !$endTime) {
            return $this->responseService->json(false, 'Start time and end time are required', null, 400);
        }
        
        $startDate = new \DateTime($startTime);
        $endDate = new \DateTime($endTime);
        
        if ($startDate >= $endDate) {
            return $this->responseService->json(false, 'End time must be after start time', null, 400);
        }
        
        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'status' => $status,
            'page' => $page,
            'limit' => $limit
        ];
    }

    private function getUserAvailabilityRecords($user, $startDate, $endDate): array
    {
        return $this->crudManager->findMany(
            'App\Plugins\Account\Entity\UserAvailabilityEntity',
            [
                [
                    'field' => 'startTime',
                    'operator' => 'greater_than_or_equal',
                    'value' => $startDate
                ],
                [
                    'field' => 'endTime',
                    'operator' => 'less_than_or_equal',
                    'value' => $endDate
                ]
            ],
            1,
            1000,
            [
                'user' => $user,
                'deleted' => false
            ],
            function($queryBuilder) {
                $queryBuilder->orderBy('t1.startTime', 'ASC');
            }
        );
    }

    private function extractBookingIds(array $availabilityRecords): array
    {
        $bookingIds = [];
        foreach ($availabilityRecords as $record) {
            if ($record->getBooking() !== null) {
                $bookingIds[] = $record->getBooking()->getId();
            }
        }
        return $bookingIds;
    }

    private function getFormattedBookings(array $bookingIds, array $availabilityRecords, $user, string $status): array
    {
        $bookings = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventBookingEntity')
            ->findBy(['id' => $bookingIds]);
        
        $formattedBookings = [];
        
        foreach ($bookings as $booking) {
            // Find corresponding availability record
            $availabilityRecord = $this->findAvailabilityRecord($availabilityRecords, $booking->getId());
            if (!$availabilityRecord) continue;
            
            // Apply status filter
            if (!$this->shouldIncludeBooking($booking, $status)) continue;
            
            // Format booking with enhanced data
            $formattedBookings[] = $this->formatBookingData($booking, $availabilityRecord, $user);
        }
        
        return $formattedBookings;
    }

    private function findAvailabilityRecord(array $availabilityRecords, int $bookingId): ?object
    {
        foreach ($availabilityRecords as $record) {
            if ($record->getBooking() && $record->getBooking()->getId() === $bookingId) {
                return $record;
            }
        }
        return null;
    }

    private function shouldIncludeBooking($booking, string $status): bool
    {
        if ($booking->getStatus() === 'removed') {
            return false;
        }
        
        if ($status === 'all') {
            return true;
        }
        
        $now = new \DateTime();
        
        if ($status === 'past') {
            return $booking->getEndTime() < $now;
        }
        
        if ($status === 'upcoming') {
            return $booking->getStartTime() > $now;
        }
        
        return $booking->getStatus() === $status;
    }

    private function formatBookingData($booking, $availabilityRecord, $user): array
    {
        $event = $booking->getEvent();
        
        // Get guests
        $guests = $this->crudManager->findMany(
            'App\Plugins\Events\Entity\EventGuestEntity',
            [],
            1,
            100,
            ['booking' => $booking]
        );
        
        // Get hosts (event assignees)
        $assignees = $this->crudManager->findMany(
            'App\Plugins\Events\Entity\EventAssigneeEntity',
            [],
            1,
            100,
            ['event' => $event]
        );
        
        // Format hosts data
        $hosts = array_map(function($assignee) {
            return [
                'id' => $assignee->getId(),
                'user_id' => $assignee->getUser()->getId(),
                'name' => $assignee->getUser()->getName(),
                'email' => $assignee->getUser()->getEmail(),
                'role' => $assignee->getRole(),
            ];
        }, $assignees);
        
        // Get meeting link from form data
        $formData = $booking->getFormDataAsArray();
        $meetingLink = null;
        if (isset($formData['online_meeting']['link'])) {
            $meetingLink = $formData['online_meeting']['link'];
        }
        
        return [
            'id' => $availabilityRecord->getId(),
            'user_id' => $user->getId(),
            'title' => $availabilityRecord->getTitle(),
            'description' => $availabilityRecord->getDescription(),
            'start_time' => $booking->getStartTime()->format('Y-m-d H:i:s'),
            'end_time' => $booking->getEndTime()->format('Y-m-d H:i:s'),
            'source' => $availabilityRecord->getSource(),
            'source_id' => $availabilityRecord->getSourceId(),
            'status' => $booking->getStatus(),
            'booking_id' => $booking->getId(),
            'event_id' => $event->getId(),
            'event_name' => $event->getName(),
            'location' => $event->getLocation(),
            'guests' => array_map(fn($guest) => $guest->toArray(), $guests),
            'hosts' => $hosts,
            'meeting_link' => $meetingLink,
            'form_data' => $formData,
            'cancelled' => $booking->isCancelled(),
            'created' => $availabilityRecord->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $availabilityRecord->getUpdated()->format('Y-m-d H:i:s')
        ];
    }

    private function createEmptyResponse(int $page, int $limit): JsonResponse
    {
        return $this->responseService->json(true, 'retrieve', [
            'bookings' => [],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => 0,
                'total_items' => 0,
                'page_size' => $limit
            ]
        ]);
    }

    private function createPaginatedResponse(array $bookings, int $page, int $limit): JsonResponse
    {
        $total = count($bookings);
        $offset = ($page - 1) * $limit;
        $pagedBookings = array_slice($bookings, $offset, $limit);
        
        return $this->responseService->json(true, 'retrieve', [
            'bookings' => $pagedBookings,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total / $limit),
                'total_items' => $total,
                'page_size' => $limit
            ]
        ]);
    }
}