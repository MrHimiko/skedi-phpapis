<?php
// src/Plugins/Integrations/Controller/GoogleMeetController.php

namespace App\Plugins\Integrations\Google\Meet\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Integrations\Google\Meet\Service\GoogleMeetService;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use DateTime;

#[Route('/api')]
class GoogleMeetController extends AbstractController
{
    private ResponseService $responseService;
    private GoogleMeetService $googleMeetService;
    
    public function __construct(
        ResponseService $responseService,
        GoogleMeetService $googleMeetService
    ) {
        $this->responseService = $responseService;
        $this->googleMeetService = $googleMeetService;
    }
    
    /**
     * Get Google OAuth URL for Meet
     */
    #[Route('/user/integrations/google-meet/auth', name: 'google_meet_auth_url#', methods: ['GET'])]
    public function getGoogleMeetAuthUrl(Request $request): JsonResponse
    {
        try {
            $authUrl = $this->googleMeetService->getAuthUrl();
            
            return $this->responseService->json(true, 'retrieve', [
                'auth_url' => $authUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Handle Google OAuth callback for Meet
     * This matches EXACTLY the pattern used in GoogleCalendarController
     */
    #[Route('/user/integrations/google-meet/callback', name: 'google_meet_auth_callback#', methods: ['POST'])]
    public function handleGoogleMeetCallback(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        if (!isset($data['code'])) {
            return $this->responseService->json(false, 'Code parameter is required', null, 400);
        }
        
        try {
            $integration = $this->googleMeetService->handleAuthCallback($user, $data['code']);
            
            return $this->responseService->json(true, 'Google Meet connected successfully', $integration->toArray());
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Create a Google Meet link
     */
    #[Route('/user/integrations/{id}/meet-links', name: 'google_meet_create_link#', methods: ['POST'])]
    public function createMeetLink(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Basic validation
        if (empty($data['title']) || empty($data['start_time']) || empty($data['end_time'])) {
            return $this->responseService->json(false, 'Title, start time, and end time are required', null, 400);
        }
        
        try {
            // Get the user's integration
            $integration = $this->googleMeetService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google integration not found', null, 404);
            }
            
            // Parse dates
            $startTime = new DateTime($data['start_time']);
            $endTime = new DateTime($data['end_time']);
            
            if ($startTime >= $endTime) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }
            
            // Optional parameters
            $eventId = $data['event_id'] ?? null;
            $bookingId = $data['booking_id'] ?? null;
            
            // Additional options
            $options = [
                'description' => $data['description'] ?? null,
                'is_guest_allowed' => $data['is_guest_allowed'] ?? true,
                'enable_recording' => $data['enable_recording'] ?? false,
            ];
            
            // Create the Meet link
            $meetEvent = $this->googleMeetService->createMeetLink(
                $integration,
                $data['title'],
                $startTime,
                $endTime,
                $eventId,
                $bookingId,
                $options
            );
            
            return $this->responseService->json(true, 'Google Meet link created successfully', $meetEvent->toArray(), 201);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get Google Meet link for a booking
     */
    #[Route('/user/bookings/{bookingId}/meet-link', name: 'google_meet_get_link#', methods: ['GET'])]
    public function getMeetLinkForBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $meetEvent = $this->googleMeetService->getMeetLinkForBooking($bookingId);
            
            if (!$meetEvent) {
                return $this->responseService->json(false, 'No Google Meet link found for this booking', null, 404);
            }
            
            return $this->responseService->json(true, 'retrieve', $meetEvent->toArray());
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
}