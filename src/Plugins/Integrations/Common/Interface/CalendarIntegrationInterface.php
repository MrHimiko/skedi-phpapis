<?php

namespace App\Plugins\Integrations\Common\Interface;

use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Account\Entity\UserEntity;
use DateTime;
use DateTimeInterface;

interface CalendarIntegrationInterface extends IntegrationInterface
{
    /**
     * Sync calendar events
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array;
    
    /**
     * Get calendars
     */
    public function getCalendars(IntegrationEntity $integration): array;
    
    /**
     * Create calendar event
     */
    public function createCalendarEvent(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startDateTime,
        DateTimeInterface $endDateTime,
        array $options = []
    ): array;
    
    /**
     * Get events for date range
     */
    public function getEventsForDateRange(UserEntity $user, DateTime $startDate, DateTime $endDate): array;
}