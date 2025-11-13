<?php

namespace App\Plugins\Events\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventAssigneeEntity;
use App\Plugins\Events\Exception\EventsException;
use App\Service\CrudManager;
use App\Exception\CrudException;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Plugins\Account\Entity\UserEntity;
use DateTimeInterface;
use DateTime;

class EventScheduleService
{
    private EntityManagerInterface $entityManager;
    private CrudManager $crudManager;
    private UserAvailabilityService $userAvailabilityService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CrudManager $crudManager,
        UserAvailabilityService $userAvailabilityService
    ) {
        $this->entityManager = $entityManager;
        $this->crudManager = $crudManager;
        $this->userAvailabilityService = $userAvailabilityService;
    }

    /**
     * Get schedules for an event
     */
    public function getScheduleForEvent(EventEntity $event): array
    {
        $schedule = $event->getSchedule();
        
        // Return default schedule if none exists
        if (empty($schedule)) {
            return [
                'monday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
                'tuesday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
                'wednesday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
                'thursday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
                'friday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
                'saturday' => ['enabled' => false, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
                'sunday' => ['enabled' => false, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []]
            ];
        }
        
        return $schedule;
    }

    /**
     * Update schedule for an event
     * 
     * @param EventEntity $event
     * @param array $scheduleData Format: [
     *   'monday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 
     *               'breaks' => [['start_time' => '12:00:00', 'end_time' => '13:00:00']]],
     *   'tuesday' => ...
     * ]
     */
    public function updateEventSchedule(EventEntity $event, array $scheduleData): array
    {
        try {
            // Validate schedule data
            $validatedSchedule = $this->validateAndSanitizeSchedule($scheduleData);
            
            // Update the event with new schedule
            $event->setSchedule($validatedSchedule);
            
            $this->entityManager->persist($event);
            $this->entityManager->flush();
            
            return $validatedSchedule;
        } catch (\Exception $e) {
            throw new EventsException('Failed to update event schedule: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate and sanitize schedule data
     */
    private function validateAndSanitizeSchedule(array $scheduleData): array
    {
        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $validatedSchedule = [];
        
        // Create default schedule structure for all days first
        foreach ($validDays as $day) {
            $validatedSchedule[$day] = [
                'enabled' => $day !== 'saturday' && $day !== 'sunday', // Default: Mon-Fri enabled
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'breaks' => []
            ];
        }
        
        // Override with provided data
        foreach ($scheduleData as $day => $dayData) {
            $day = strtolower($day);
            
            // Skip invalid days
            if (!in_array($day, $validDays)) {
                continue;
            }
            
            // Validate required fields in day data
            if (!is_array($dayData)) {
                continue;
            }
            
            // Set enabled status
            if (isset($dayData['enabled'])) {
                $validatedSchedule[$day]['enabled'] = (bool)$dayData['enabled'];
            }
            
            // Validate and set start_time
            if (!empty($dayData['start_time'])) {
                try {
                    // Validate time format
                    $startTime = new DateTime($dayData['start_time']);
                    $validatedSchedule[$day]['start_time'] = $startTime->format('H:i:s');
                } catch (\Exception $e) {
                    // Use default if invalid
                }
            }
            
            // Validate and set end_time
            if (!empty($dayData['end_time'])) {
                try {
                    // Validate time format
                    $endTime = new DateTime($dayData['end_time']);
                    $validatedSchedule[$day]['end_time'] = $endTime->format('H:i:s');
                } catch (\Exception $e) {
                    // Use default if invalid
                }
            }
            
            // Make sure end time is after start time
            $startTime = new DateTime($validatedSchedule[$day]['start_time']);
            $endTime = new DateTime($validatedSchedule[$day]['end_time']);
            
            if ($startTime >= $endTime) {
                // If times are invalid, reset to default
                $validatedSchedule[$day]['start_time'] = '09:00:00';
                $validatedSchedule[$day]['end_time'] = '17:00:00';
            }
            
            // Process breaks
            if (!empty($dayData['breaks']) && is_array($dayData['breaks'])) {
                $validatedBreaks = [];
                
                foreach ($dayData['breaks'] as $breakData) {
                    if (!is_array($breakData)) {
                        continue;
                    }
                    
                    $validBreak = [];
                    
                    // Validate break start time
                    if (!empty($breakData['start_time'])) {
                        try {
                            $breakStartTime = new DateTime($breakData['start_time']);
                            $validBreak['start_time'] = $breakStartTime->format('H:i:s');
                        } catch (\Exception $e) {
                            continue; // Skip invalid break
                        }
                    } else {
                        continue; // Skip if no start time
                    }
                    
                    // Validate break end time
                    if (!empty($breakData['end_time'])) {
                        try {
                            $breakEndTime = new DateTime($breakData['end_time']);
                            $validBreak['end_time'] = $breakEndTime->format('H:i:s');
                        } catch (\Exception $e) {
                            continue; // Skip invalid break
                        }
                    } else {
                        continue; // Skip if no end time
                    }
                    
                    // Make sure break end time is after break start time
                    $breakStartTime = new DateTime($validBreak['start_time']);
                    $breakEndTime = new DateTime($validBreak['end_time']);
                    
                    if ($breakStartTime >= $breakEndTime) {
                        continue; // Skip invalid break
                    }
                    
                    // Make sure break is within the day's time range
                    $dayStartTime = new DateTime($validatedSchedule[$day]['start_time']);
                    $dayEndTime = new DateTime($validatedSchedule[$day]['end_time']);
                    
                    if ($breakStartTime < $dayStartTime || $breakEndTime > $dayEndTime) {
                        continue; // Skip break outside day's range
                    }
                    
                    $validatedBreaks[] = $validBreak;
                }
                
                $validatedSchedule[$day]['breaks'] = $validatedBreaks;
            }
        }
        
        return $validatedSchedule;
    }

    /**
     * Check if a specific time slot is available based on the schedule (timezone-aware)
     */
    public function isTimeSlotAvailable(EventEntity $event, DateTimeInterface $startDateTime, DateTimeInterface $endDateTime, ?string $clientTimezone = null): bool
    {
        // Ensure the dates are in UTC for internal processing
        $startUtc = clone $startDateTime;
        $endUtc = clone $endDateTime;
        
        if ($startDateTime->getTimezone()->getName() !== 'UTC') {
            $startUtc->setTimezone(new \DateTimeZone('UTC'));
        }
        
        if ($endDateTime->getTimezone()->getName() !== 'UTC') {
            $endUtc->setTimezone(new \DateTimeZone('UTC'));
        }
        
        // Get the day of the week
        $dayOfWeek = strtolower($startUtc->format('l'));
        
        // Get the event schedule
        $schedule = $this->getScheduleForEvent($event);
        
        // Check if this day is enabled
        if (!isset($schedule[$dayOfWeek]) || !$schedule[$dayOfWeek]['enabled']) {
            return false; // Day not available
        }
        
        // Extract just the time component for comparison
        $startTime = $startUtc->format('H:i:s');
        $endTime = $endUtc->format('H:i:s');
        
        // Check if within working hours
        $scheduleStartTime = $schedule[$dayOfWeek]['start_time'];
        $scheduleEndTime = $schedule[$dayOfWeek]['end_time'];
        
        if ($startTime < $scheduleStartTime || $endTime > $scheduleEndTime) {
            return false; // Not within working hours
        }
        
        // Check for conflicts with breaks
        foreach ($schedule[$dayOfWeek]['breaks'] as $break) {
            $breakStartTime = $break['start_time'];
            $breakEndTime = $break['end_time'];
            
            // Check for overlap
            if (
                ($startTime < $breakEndTime && $endTime > $breakStartTime) ||
                ($startTime <= $breakStartTime && $endTime >= $breakEndTime)
            ) {
                return false; // Overlaps with a break
            }
        }
        
        return true; // Available
    }

    public function getAvailableTimeSlots(
        EventEntity $event, 
        DateTimeInterface $date, 
        ?int $durationMinutes = null, 
        ?string $clientTimezone = null,
        int $bufferMinutes = 0 
    ): array {
        // Get available slots based on event schedule with buffer time
        $slots = $this->getBaseAvailableTimeSlots($event, $date, $durationMinutes, $clientTimezone, $bufferMinutes);
        
        // No need to check host availability if there are no slots
        if (empty($slots)) {
            return [];
        }
        
        // Get event assignees who are eligible to host
        $hosts = $this->getEventHosts($event);
        
        if (empty($hosts)) {
            // No hosts assigned yet, return slots as is
            return $slots;
        }
        
        // Filter slots based on availability rules
        $availabilityType = $event->getAvailabilityType();
        
        if ($availabilityType === 'one_host_available') {
            // Filter out slots where no hosts are available
            return $this->filterSlotsByOneHostAvailable($slots, $hosts);
        } else {
            // All hosts must be available (more restrictive)
            return $this->filterSlotsByAllHostsAvailable($slots, $hosts);
        }
    }

    /**
     * Get base available time slots without checking host availability
     */
    /**
     * Get base available time slots without checking host availability
     */
    private function getBaseAvailableTimeSlots(
        EventEntity $event, 
        DateTimeInterface $date, 
        ?int $durationMinutes = null, 
        ?string $clientTimezone = null,
        int $bufferMinutes = 0
    ): array {
        // Set default timezone if not provided
        if (!$clientTimezone) {
            $clientTimezone = 'UTC';
        }
        
        try {
            // Validate timezone
            new \DateTimeZone($clientTimezone);
        } catch (\Exception $e) {
            // Default to UTC if invalid timezone
            $clientTimezone = 'UTC';
        }
        
        // Default to first duration option if not specified
        if ($durationMinutes === null) {
            $durations = $event->getDuration();
            $durationMinutes = isset($durations[0]['duration']) ? (int)$durations[0]['duration'] : 30;
        }

        // Create client date objects for the requested date
        $clientDate = clone $date;
        if ($date->getTimezone()->getName() !== $clientTimezone) {
            $clientDate->setTimezone(new \DateTimeZone($clientTimezone));
        }
        
        // Reset to midnight in client timezone
        $clientStartOfDay = clone $clientDate;
        $clientStartOfDay->setTime(0, 0, 0);
        
        $clientEndOfDay = clone $clientDate;
        $clientEndOfDay->setTime(23, 59, 59);
        
        // Convert to UTC for processing - these define our search window
        $utcStartOfClientDay = clone $clientStartOfDay;
        $utcStartOfClientDay->setTimezone(new \DateTimeZone('UTC'));
        
        $utcEndOfClientDay = clone $clientEndOfDay;
        $utcEndOfClientDay->setTimezone(new \DateTimeZone('UTC'));
        
        // We need to check UTC days that might contribute to the requested client day
        // This could include the day before, the day itself, and potentially the day after
        $datesToCheck = [];
        
        // Start checking from 1 day before to account for timezones
        $currentDayCheck = clone $utcStartOfClientDay;
        $currentDayCheck->setTime(0, 0, 0);
        $currentDayCheck->modify('-1 day'); // Start from previous day
        
        // Check 3 days total (previous, current, next) to cover all timezone scenarios
        for ($i = 0; $i < 3; $i++) {
            $datesToCheck[] = clone $currentDayCheck;
            $currentDayCheck->modify('+1 day');
        }
        
        $allSlots = [];
        
        // Process each UTC day that could contribute slots to the requested client day
        foreach ($datesToCheck as $utcDayToCheck) {
            $dayOfWeek = strtolower($utcDayToCheck->format('l'));
            $schedule = $this->getScheduleForEvent($event);
            
            // Skip if day is not enabled in schedule
            if (!isset($schedule[$dayOfWeek]) || !$schedule[$dayOfWeek]['enabled']) {
                continue;
            }
            
            // Extract schedule times for this UTC day
            $scheduleStartTime = clone $utcDayToCheck;
            list($hours, $minutes, $seconds) = explode(':', $schedule[$dayOfWeek]['start_time']);
            $scheduleStartTime->setTime((int)$hours, (int)$minutes, (int)$seconds);
            
            $scheduleEndTime = clone $utcDayToCheck;
            list($hours, $minutes, $seconds) = explode(':', $schedule[$dayOfWeek]['end_time']);
            $scheduleEndTime->setTime((int)$hours, (int)$minutes, (int)$seconds);
            
            // MIDNIGHT CROSSOVER FIX: If start_time > end_time, it means availability crosses midnight
            if ($schedule[$dayOfWeek]['start_time'] > $schedule[$dayOfWeek]['end_time']) {
                // Add 1 day to end time since it's on the next day
                $scheduleEndTime->modify('+1 day');
            }
            
            // Convert breaks to DateTime objects
            $breaks = [];
            foreach ($schedule[$dayOfWeek]['breaks'] as $break) {
                $breakStart = clone $utcDayToCheck;
                list($hours, $minutes, $seconds) = explode(':', $break['start_time']);
                $breakStart->setTime((int)$hours, (int)$minutes, (int)$seconds);
                
                $breakEnd = clone $utcDayToCheck;
                list($hours, $minutes, $seconds) = explode(':', $break['end_time']);
                $breakEnd->setTime((int)$hours, (int)$minutes, (int)$seconds);
                
                // Handle midnight crossover for breaks as well
                if ($break['start_time'] > $break['end_time']) {
                    $breakEnd->modify('+1 day');
                }
                
                $breaks[] = [
                    'start' => $breakStart,
                    'end' => $breakEnd
                ];
            }
            
            // Get existing bookings for this UTC day and the next day (for crossover)
            $existingBookings = $this->getEventBookingsForDate($event, $utcDayToCheck);
            
            // Also get bookings from next day if schedule crosses midnight
            if ($schedule[$dayOfWeek]['start_time'] > $schedule[$dayOfWeek]['end_time']) {
                $nextDay = clone $utcDayToCheck;
                $nextDay->modify('+1 day');
                $nextDayBookings = $this->getEventBookingsForDate($event, $nextDay);
                $existingBookings = array_merge($existingBookings, $nextDayBookings);
            }
            
            // Generate time slots in 15-minute increments
            $slotStart = clone $scheduleStartTime;
            $slotIncrement = new \DateInterval('PT15M'); // 15-min increments
            $eventDuration = new \DateInterval('PT' . $durationMinutes . 'M');
            
            while ($slotStart < $scheduleEndTime) {
                $slotEnd = clone $slotStart;
                $slotEnd->add($eventDuration);
                
                // If slot end is after schedule end, break
                if ($slotEnd > $scheduleEndTime) {
                    break;
                }
                
                // Check for conflicts
                $hasConflict = false;
                
                // Check breaks
                foreach ($breaks as $break) {
                    if ($slotStart < $break['end'] && $slotEnd > $break['start']) {
                        $hasConflict = true;
                        break;
                    }
                }
                
                // Check bookings WITH BUFFER TIME (in minutes)
                if (!$hasConflict) {
                    foreach ($existingBookings as $booking) {
                        // Apply buffer time: extend the busy period AFTER the booking ends
                        $busyUntil = clone $booking['end'];
                        $busyUntil->add(new \DateInterval('PT' . $bufferMinutes . 'M')); // Add buffer minutes
                        
                        // Check if new slot conflicts with booking OR its buffer period
                        if ($slotStart < $busyUntil && $slotEnd > $booking['start']) {
                            $hasConflict = true;
                            break;
                        }
                    }
                }
                
                if (!$hasConflict) {
                    // Create client-timezone versions of slot times
                    $clientSlotStart = clone $slotStart;
                    $clientSlotStart->setTimezone(new \DateTimeZone($clientTimezone));
                    
                    $clientSlotEnd = clone $slotEnd;
                    $clientSlotEnd->setTimezone(new \DateTimeZone($clientTimezone));
                    
                    // Only show slots where the START time is on the requested client day
                    // This gives a cleaner UX - users navigate to the day to see slots that start on that day
                    if ($clientSlotStart->format('Y-m-d') === $clientDate->format('Y-m-d')) {
                        $allSlots[] = [
                            'start' => $slotStart->format('Y-m-d H:i:s'),
                            'end' => $slotEnd->format('Y-m-d H:i:s'),
                            'start_client' => $clientSlotStart->format('Y-m-d H:i:s'),
                            'end_client' => $clientSlotEnd->format('Y-m-d H:i:s'),
                            'timezone' => $clientTimezone
                        ];
                    }
                }
                
                // Move to next slot
                $slotStart->add($slotIncrement);
            }
        }
        
        // FILTER PAST TIMES + ADVANCE NOTICE
        // Get current time in client timezone
        $now = new \DateTime('now', new \DateTimeZone($clientTimezone));
        
        // Get advance notice in minutes (check if event has this property, default to 0)
        $advanceNoticeMinutes = 0;
        if (method_exists($event, 'getAdvanceNotice')) {
            $advanceNoticeMinutes = $event->getAdvanceNotice() ?? 0;
        }
        
        // Calculate minimum booking time = now + advance notice
        $minimumBookingTime = clone $now;
        if ($advanceNoticeMinutes > 0) {
            $minimumBookingTime->add(new \DateInterval('PT' . $advanceNoticeMinutes . 'M'));
        }
        
        // Filter out slots that are in the past or within advance notice period
        $allSlots = array_filter($allSlots, function($slot) use ($minimumBookingTime, $clientTimezone) {
            $slotStartClient = new \DateTime($slot['start_client'], new \DateTimeZone($clientTimezone));
            return $slotStartClient >= $minimumBookingTime;
        });
        
        // Re-index array after filtering
        $allSlots = array_values($allSlots);
        
        return $allSlots;
    }


    /**
     * Filter time slots where at least one host is available
     */
    private function filterSlotsByOneHostAvailable(array $slots, array $hosts): array
    {
        $availableSlots = [];
        
        foreach ($slots as $slot) {
            $startTime = new DateTime($slot['start']);
            $endTime = new DateTime($slot['end']);
            
            // Check if at least one host is available for this slot
            foreach ($hosts as $host) {
                if ($this->userAvailabilityService->isUserAvailable($host, $startTime, $endTime)) {
                    $availableSlots[] = $slot;
                    break; // One available host is enough
                }
            }
        }
        
        return $availableSlots;
    }
    
    /**
     * Filter time slots where all hosts are available
     */
    private function filterSlotsByAllHostsAvailable(array $slots, array $hosts): array
    {
        $availableSlots = [];
        
        foreach ($slots as $slot) {
            $startTime = new DateTime($slot['start']);
            $endTime = new DateTime($slot['end']);
            
            $allAvailable = true;
            
            // Check if all hosts are available for this slot
            foreach ($hosts as $host) {
                if (!$this->userAvailabilityService->isUserAvailable($host, $startTime, $endTime)) {
                    $allAvailable = false;
                    break;
                }
            }
            
            if ($allAvailable) {
                $availableSlots[] = $slot;
            }
        }
        
        return $availableSlots;
    }
    
    /**
     * Get hosts (users who can host the event)
     */
    private function getEventHosts(EventEntity $event): array
    {
        $assignees = $this->entityManager->getRepository(EventAssigneeEntity::class)
            ->findBy(['event' => $event]);
        
        $hosts = [];
        
        foreach ($assignees as $assignee) {
            // Depending on your role system, you might want to filter by specific roles
            // Here we assume 'creator', 'admin', and 'host' roles can host events
            $role = $assignee->getRole();
            if (in_array($role, ['creator', 'admin', 'host', 'member'])) {
                $hosts[] = $assignee->getUser();
            }
        }
        
        return $hosts;
    }

    /**
     * Handle booking creation to also create availability records
     */
    public function handleBookingCreated(EventBookingEntity $booking): void
    {
        try {
            $event = $booking->getEvent();
            $hosts = $this->getEventHosts($event);
            
            // FIX: If no hosts, use the creator
            if (empty($hosts) && $event->getCreatedBy()) {
                $hosts = [$event->getCreatedBy()];
            }
            
            foreach ($hosts as $host) {
                $this->userAvailabilityService->createInternalAvailability(
                    $host,
                    $event->getName(),
                    $booking->getStartTime(),
                    $booking->getEndTime(),
                    $event,
                    $booking
                );
            }
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            error_log('Failed to create host availability records: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle booking updates to also update availability records
    */

    public function handleBookingUpdated(EventBookingEntity $booking): void
    {
        try {
            // Find all availability records for this booking
            $availabilityRecords = $this->entityManager->getRepository('App\Plugins\Account\Entity\UserAvailabilityEntity')
                ->findBy([
                    'booking' => $booking,
                    'deleted' => false
                ]);
            
            // Update each record with new times
            foreach ($availabilityRecords as $record) {
                $record->setStartTime($booking->getStartTime());
                $record->setEndTime($booking->getEndTime());
                
                if ($booking->isCancelled()) {
                    $record->setStatus('cancelled');
                }
                
                $this->entityManager->persist($record);
            }
            
            // If booking was cancelled, no need to check for new hosts
            if ($booking->isCancelled()) {
                $this->entityManager->flush();
                return;
            }
            
            // Check if new hosts need availability records
            $event = $booking->getEvent();
            $hosts = $this->getEventHosts($event);
            
            // Get user IDs with existing records
            $existingUserIds = array_map(function($record) {
                return $record->getUser()->getId();
            }, $availabilityRecords);
            
            // Create records for any new hosts
            foreach ($hosts as $host) {
                if (!in_array($host->getId(), $existingUserIds)) {
                    $this->userAvailabilityService->createInternalAvailability(
                        $host,
                        $event->getName(),
                        $booking->getStartTime(),
                        $booking->getEndTime(),
                        $event,
                        $booking
                    );
                }
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log error but don't fail the booking update
            error_log('Failed to update host availability records: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle booking cancellation to also cancel availability records
     */
    public function handleBookingCancelled(EventBookingEntity $booking): void
    {
        try {
            // Find all availability records for this booking
            $availabilityRecords = $this->entityManager->getRepository('App\Plugins\Account\Entity\UserAvailabilityEntity')
                ->findBy([
                    'booking' => $booking,
                    'deleted' => false
                ]);
            
            // Mark each record as cancelled
            foreach ($availabilityRecords as $record) {
                $record->setStatus('cancelled');
                $this->entityManager->persist($record);
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log error but don't fail the booking cancellation
            error_log('Failed to cancel host availability records: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if a specific time slot is available for the event and all required hosts
     */
    public function isTimeSlotAvailableForAll(
        EventEntity $event, 
        DateTimeInterface $startDateTime, 
        DateTimeInterface $endDateTime, 
        ?string $clientTimezone = null,
        ?int $excludeBookingId = null
    ): bool {
        // First check if the slot works with the event schedule
        if (!$this->isTimeSlotAvailable($event, $startDateTime, $endDateTime, $clientTimezone)) {
            return false;
        }
        
        // Check if there are conflicting bookings (except the one being updated)
        $existingBookings = $this->crudManager->findMany(
            EventBookingEntity::class,
            [],
            1,
            100,
            [
                'event' => $event,
                'cancelled' => false
            ]
        );
        
        foreach ($existingBookings as $booking) {
            if ($excludeBookingId && $booking->getId() === $excludeBookingId) {
                continue; // Skip the booking being updated
            }
            
            $bookingStart = $booking->getStartTime();
            $bookingEnd = $booking->getEndTime();
            
            // Check for overlap
            if (
                ($startDateTime < $bookingEnd && $endDateTime > $bookingStart) ||
                ($startDateTime <= $bookingStart && $endDateTime >= $bookingEnd)
            ) {
                return false; // Overlaps with an existing booking
            }
        }
        
        // Get hosts and check availability
        $hosts = $this->getEventHosts($event);
        
        // FIX: If no hosts, use the creator
        if (empty($hosts)) {
            $creator = $event->getCreatedBy();
            if ($creator) {
                $hosts = [$creator];
            } else {
                // No creator, so slot is available (edge case)
                return true;
            }
        }
        
        $availabilityType = $event->getAvailabilityType();
        
        if ($availabilityType === 'one_host_available') {
            // At least one host must be available
            foreach ($hosts as $host) {
                if ($this->userAvailabilityService->isUserAvailable($host, $startDateTime, $endDateTime)) {
                    return true;
                }
            }
            
            // No hosts available
            return false;
        } else {
            // All hosts must be available
            foreach ($hosts as $host) {
                if (!$this->userAvailabilityService->isUserAvailable($host, $startDateTime, $endDateTime)) {
                    return false;
                }
            }
            
            // All hosts are available
            return true;
        }
    }


    /**
     * Get all bookings for a specific event and date
     */
    private function getEventBookingsForDate(EventEntity $event, DateTimeInterface $date): array
    {
        $startOfDay = clone $date;
        $startOfDay->setTime(0, 0, 0);
        
        $endOfDay = clone $date;
        $endOfDay->setTime(23, 59, 59);
        
        try {
            // Use direct query - CrudManager can't handle date filters properly
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('b')
            ->from(EventBookingEntity::class, 'b')
            ->where('b.event = :event')
            ->andWhere('b.cancelled = false')
            ->andWhere('b.startTime >= :startOfDay')
            ->andWhere('b.startTime <= :endOfDay')
            ->setParameter('event', $event)
            ->setParameter('startOfDay', $startOfDay)
            ->setParameter('endOfDay', $endOfDay);
            
            $bookings = $qb->getQuery()->getResult();
            
            $formattedBookings = [];
            foreach ($bookings as $booking) {
                $formattedBookings[] = [
                    'start' => $booking->getStartTime(),
                    'end' => $booking->getEndTime()
                ];
            }
            
            return $formattedBookings;
            
        } catch (\Exception $e) {
            error_log('Error fetching bookings: ' . $e->getMessage());
            return [];
        }
    }   

}