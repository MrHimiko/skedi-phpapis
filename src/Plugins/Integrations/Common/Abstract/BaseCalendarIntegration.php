<?php

namespace App\Plugins\Integrations\Common\Abstract;

use App\Plugins\Integrations\Common\Interface\CalendarIntegrationInterface;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;
use DateTimeInterface;

abstract class BaseCalendarIntegration extends BaseIntegration implements CalendarIntegrationInterface
{
    protected UserAvailabilityService $userAvailabilityService;
    protected CrudManager $crudManager;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        UserAvailabilityService $userAvailabilityService,
        CrudManager $crudManager
    ) {
        parent::__construct($entityManager, $integrationRepository);
        
        $this->userAvailabilityService = $userAvailabilityService;
        $this->crudManager = $crudManager;
    }
    
    /**
     * Get event entity class name
     */
    abstract protected function getEventEntityClass(): string;
    
    /**
     * Save event to database
     */
    abstract protected function saveEvent(
        IntegrationEntity $integration,
        UserEntity $user,
        $providerEvent,
        string $calendarId,
        string $calendarName
    ): ?object;
    
    /**
     * {@inheritdoc}
     */
    public function getEventsForDateRange(UserEntity $user, DateTime $startDate, DateTime $endDate): array
    {
        try {
            $filters = [
                [
                    'field' => 'startTime',
                    'operator' => 'less_than',
                    'value' => $endDate
                ],
                [
                    'field' => 'endTime',
                    'operator' => 'greater_than',
                    'value' => $startDate
                ],
                [
                    'field' => 'status',
                    'operator' => 'not_equals',
                    'value' => 'cancelled'
                ]
            ];
            
            return $this->crudManager->findMany(
                $this->getEventEntityClass(),
                $filters,
                1,
                1000,
                ['user' => $user],
                function($queryBuilder) {
                    $queryBuilder->orderBy('t1.startTime', 'ASC');
                }
            );
        } catch (CrudException $e) {
            return [];
        }
    }
    
    /**
     * Delete events not in list
     */
    protected function deleteEventsNotInList(UserEntity $user, array $keepEventIds, string $calendarId = null): int
    {
        try {
            // Get all events for this calendar
            $filters = [
                [
                    'field' => 'calendarId',
                    'operator' => 'equals',
                    'value' => $calendarId
                ]
            ];
            
            $events = $this->crudManager->findMany(
                $this->getEventEntityClass(),
                $filters,
                1,
                10000,
                ['user' => $user]
            );
            
            $deletedCount = 0;
            foreach ($events as $event) {
                if (!in_array($event->getGoogleEventId(), $keepEventIds)) {
                    $event->setStatus('cancelled');
                    $this->entityManager->persist($event);
                    $deletedCount++;
                }
            }
            
            if ($deletedCount > 0) {
                $this->entityManager->flush();
            }
            
            return $deletedCount;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Sync user availability
     */
    protected function syncUserAvailability(UserEntity $user, array $events): void
    {
        try {
            foreach ($events as $event) {
                // Skip cancelled or transparent events
                if ($event->getStatus() === 'cancelled' || 
                    (method_exists($event, 'getTransparency') && $event->getTransparency() === 'transparent')) {
                    continue;
                }
                
                // Create unique source ID
                $sourceId = $this->getProvider() . '_' . $event->getCalendarId() . '_' . $event->getGoogleEventId();
                
                $this->userAvailabilityService->createExternalAvailability(
                    $user,
                    $event->getTitle() ?: 'Busy',
                    $event->getStartTime(),
                    $event->getEndTime(),
                    $this->getProvider(),
                    $sourceId,
                    $event->getDescription(),
                    $event->getStatus()
                );
            }
        } catch (\Exception $e) {
            // Continue without failing
        }
    }
}