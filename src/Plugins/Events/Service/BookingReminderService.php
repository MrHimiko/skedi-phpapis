<?php

namespace App\Plugins\Events\Service;

use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Email\Service\EmailService;
use App\Plugins\Email\Entity\EmailQueueEntity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BookingReminderService
{
    private EmailService $emailService;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    
    // Reminder configurations
    private const REMINDERS = [
        '24h' => [
            'hours' => 24,
            'text' => 'Your meeting is tomorrow',
            'time_text' => '24 hours'
        ],
        '1h' => [
            'hours' => 1,
            'text' => 'Your meeting starts in 1 hour',
            'time_text' => '1 hour'
        ],
        '15min' => [
            'minutes' => 15,
            'text' => 'Your meeting starts in 15 minutes',
            'time_text' => '15 minutes'
        ]
    ];
    
    public function __construct(
        EmailService $emailService,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->emailService = $emailService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }
    


    /**
     * Queue all reminder emails for a booking - FIXED to send to both guests AND hosts
     */
    public function queueRemindersForBooking(EventBookingEntity $booking): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            
            // Skip if no guest email
            if (empty($formData['primary_contact']['email'])) {
                return;
            }
            
            $guestEmail = $formData['primary_contact']['email'];
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            
            // Get meeting details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Determine location
            $location = $this->getLocationText($event);
            $meetingLink = $this->getMeetingLink($booking);
            
            // Common data for all reminders
            $baseData = [
                'guest_name' => $guestName,
                'meeting_name' => $event->getName(),
                'meeting_date' => $startTime->format('F j, Y'),
                'meeting_time' => $startTime->format('g:i A'),
                'meeting_duration' => $duration,
                'meeting_location' => $location,
                'meeting_link' => $meetingLink,
                'organizer_name' => $event->getCreatedBy() ? $event->getCreatedBy()->getName() : 'Organizer',
                'company_name' => $event->getOrganization() ? $event->getOrganization()->getName() : ''
            ];
            
            // Queue each reminder
            foreach (self::REMINDERS as $type => $config) {
                $scheduledAt = clone $startTime;
                
                if (isset($config['hours'])) {
                    $scheduledAt->modify("-{$config['hours']} hours");
                } else {
                    $scheduledAt->modify("-{$config['minutes']} minutes");
                }
                
                // Only queue if scheduled time is in the future
                if ($scheduledAt > new \DateTime()) {
                    $reminderData = array_merge($baseData, [
                        'reminder_time' => $config['time_text'],
                        'reminder_text' => $config['text']
                    ]);
                    
                    // *** SEND TO GUEST (original working code) ***
                    $queueResult = $this->emailService->queue(
                        $guestEmail,
                        'meeting_reminder',
                        $reminderData,
                        [
                            'send_at' => $scheduledAt->format('Y-m-d H:i:s'),
                            'priority' => 5
                        ]
                    );
                    
                    // Update the queue record with booking reference
                    if ($queueResult['success'] && !empty($queueResult['queue_id'])) {
                        $queueItem = $this->entityManager->getRepository(EmailQueueEntity::class)->find($queueResult['queue_id']);
                        if ($queueItem) {
                            $queueItem->setBooking($booking);
                            $queueItem->setReminderType($type);
                            $this->entityManager->flush();
                        }
                    }
                    
                    $this->logger->info("Queued {$type} reminder for GUEST booking {$booking->getId()}");
                    
                    // *** NOW ALSO SEND TO HOSTS (new addition) ***
                    try {
                        $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                            ->findBy(['event' => $event]);
                        
                        foreach ($assignees as $assignee) {
                            // Update reminder data for host
                            $hostReminderData = array_merge($reminderData, [
                                'host_name' => $assignee->getUser()->getName()
                            ]);
                            
                            $hostQueueResult = $this->emailService->queue(
                                $assignee->getUser()->getEmail(),
                                'meeting_reminder',
                                $hostReminderData,
                                [
                                    'send_at' => $scheduledAt->format('Y-m-d H:i:s'),
                                    'priority' => 5
                                ]
                            );
                            
                            // Update the queue record with booking reference
                            if ($hostQueueResult['success'] && !empty($hostQueueResult['queue_id'])) {
                                $queueItem = $this->entityManager->getRepository(EmailQueueEntity::class)->find($hostQueueResult['queue_id']);
                                if ($queueItem) {
                                    $queueItem->setBooking($booking);
                                    $queueItem->setReminderType($type);
                                    $this->entityManager->flush();
                                }
                            }
                            
                            $this->logger->info("Queued {$type} reminder for HOST {$assignee->getUser()->getEmail()} booking {$booking->getId()}");
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning("Failed to queue host reminders: " . $e->getMessage());
                        // Don't fail the whole process if host reminders fail
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to queue reminders for booking {$booking->getId()}: " . $e->getMessage());
        }
    }
    
    /**
     * Cancel all pending reminders for a booking
     */
    public function cancelRemindersForBooking(EventBookingEntity $booking): void
    {
        try {
            // Find all pending reminders for this booking
            $reminders = $this->entityManager->getRepository(EmailQueueEntity::class)
                ->createQueryBuilder('q')
                ->where('q.booking = :booking')
                ->andWhere('q.status IN (:statuses)')
                ->setParameter('booking', $booking)
                ->setParameter('statuses', ['pending', 'retry'])
                ->getQuery()
                ->getResult();
            
            // Delete them
            foreach ($reminders as $reminder) {
                $this->entityManager->remove($reminder);
            }
            
            $this->entityManager->flush();
            
            $this->logger->info("Cancelled " . count($reminders) . " reminders for booking {$booking->getId()}");
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to cancel reminders for booking {$booking->getId()}: " . $e->getMessage());
        }
    }
    
    /**
     * Update reminders when booking is rescheduled
     */
    public function updateRemindersForBooking(EventBookingEntity $booking): void
    {
        // Cancel existing reminders
        $this->cancelRemindersForBooking($booking);
        
        // Queue new reminders with updated times
        $this->queueRemindersForBooking($booking);
    }
    
    private function getLocationText($event): string
    {
        $location = 'Online Meeting';
        $eventLocation = $event->getLocation();
        
        if ($eventLocation && is_array($eventLocation)) {
            if (isset($eventLocation['type'])) {
                switch ($eventLocation['type']) {
                    case 'in_person':
                        $location = $eventLocation['address'] ?? 'In-Person Meeting';
                        break;
                    case 'phone':
                        $location = 'Phone Call';
                        break;
                    case 'google_meet':
                        $location = 'Google Meet';
                        break;
                    case 'zoom':
                        $location = 'Zoom Meeting';
                        break;
                    case 'custom':
                        $location = $eventLocation['label'] ?? 'Custom Location';
                        break;
                }
            }
        }
        
        return $location;
    }
    
    private function getMeetingLink($booking): string
    {
        $formData = $booking->getFormDataAsArray();
        
        if (!empty($formData['online_meeting']['link'])) {
            return $formData['online_meeting']['link'];
        } elseif (!empty($formData['meeting_link'])) {
            return $formData['meeting_link'];
        }
        
        return '';
    }
}