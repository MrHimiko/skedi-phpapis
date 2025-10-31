<?php

namespace App\Plugins\Account\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Account\Entity\UserAvailabilityEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Account\Repository\UserAvailabilityRepository;
use App\Plugins\Account\Exception\AccountException;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;

class UserAvailabilityService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private UserAvailabilityRepository $availabilityRepository;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        UserAvailabilityRepository $availabilityRepository
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->availabilityRepository = $availabilityRepository;
    }

    /**
     * Get availability items for a user within a time range
     */
    public function getAvailabilityByRange(
        UserEntity $user, 
        \DateTimeInterface $startTime, 
        \DateTimeInterface $endTime
    ): array {
        try {
            return $this->crudManager->findMany(
                UserAvailabilityEntity::class,
                [],
                1,
                1000,
                [
                    'user' => $user,
                    'deleted' => false
                ],
                function($queryBuilder) use ($startTime, $endTime) {
                    $queryBuilder->andWhere('t1.startTime < :endTime')
                        ->andWhere('t1.endTime > :startTime')
                        ->setParameter('startTime', $startTime)
                        ->setParameter('endTime', $endTime)
                        ->orderBy('t1.startTime', 'ASC');
                }
            );
        } catch (\Exception $e) {
            throw new AccountException('Failed to retrieve availability: ' . $e->getMessage());
        }
    }

    /**
     * Get availability items for multiple users within a time range
     */
    public function getAvailabilityForUsers(
        array $users, 
        \DateTimeInterface $startTime, 
        \DateTimeInterface $endTime
    ): array {
        try {
            $userIds = array_map(function($user) {
                return $user instanceof UserEntity ? $user->getId() : $user;
            }, $users);
            
            return $this->crudManager->findMany(
                UserAvailabilityEntity::class,
                [],
                1,
                1000,
                [
                    'deleted' => false
                ],
                function($queryBuilder) use ($userIds, $startTime, $endTime) {
                    $queryBuilder->andWhere('t1.user IN (:userIds)')
                        ->andWhere('t1.startTime < :endTime')
                        ->andWhere('t1.endTime > :startTime')
                        ->setParameter('userIds', $userIds)
                        ->setParameter('startTime', $startTime)
                        ->setParameter('endTime', $endTime)
                        ->orderBy('t1.startTime', 'ASC');
                }
            );
        } catch (\Exception $e) {
            throw new AccountException('Failed to retrieve availability for users: ' . $e->getMessage());
        }
    }

    /**
     * Check if a user is available at a specific time
     */
    public function isUserAvailable(
        UserEntity $user, 
        \DateTimeInterface $startTime, 
        \DateTimeInterface $endTime,
        ?int $excludeId = null
    ): bool {
        try {
            $conflicts = $this->crudManager->findMany(
                UserAvailabilityEntity::class,
                [],
                1,
                10, // We only need to know if any exist
                [
                    'user' => $user,
                    'deleted' => false
                ],
                function($queryBuilder) use ($startTime, $endTime, $excludeId) {
                    $queryBuilder->andWhere('t1.startTime < :endTime')
                        ->andWhere('t1.endTime > :startTime')
                        ->andWhere('t1.status != :cancelledStatus')
                        ->setParameter('startTime', $startTime)
                        ->setParameter('endTime', $endTime)
                        ->setParameter('cancelledStatus', 'cancelled');
                    
                    if ($excludeId) {
                        $queryBuilder->andWhere('t1.id != :excludeId')
                            ->setParameter('excludeId', $excludeId);
                    }
                }
            );
            
            return count($conflicts) === 0;
        } catch (\Exception $e) {
            throw new AccountException('Failed to check availability: ' . $e->getMessage());
        }
    }


    /**
     * Create a new availability record for internal events
     */
    public function createInternalAvailability(
        UserEntity $user,
        string $title,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        ?EventEntity $event = null,
        ?EventBookingEntity $booking = null,
        ?string $description = null,
        string $status = 'confirmed'
    ): UserAvailabilityEntity {
        try {
            // Validate time range
            if ($startTime >= $endTime) {
                throw new AccountException('End time must be after start time');
            }

            $availability = new UserAvailabilityEntity();
            $availability->setUser($user);
            $availability->setTitle($title);
            $availability->setDescription($description);
            $availability->setStartTime($startTime);
            $availability->setEndTime($endTime);
            $availability->setSource('internal');
            $availability->setStatus($status);
            
            if ($event) {
                $availability->setEvent($event);
                $availability->setSourceId('event_' . $event->getId());
            }
            
            if ($booking) {
                $availability->setBooking($booking);
                $availability->setSourceId('booking_' . $booking->getId());
            }

            $this->entityManager->persist($availability);
            $this->entityManager->flush();

            return $availability;
        } catch (\Exception $e) {
            throw new AccountException('Failed to create availability: ' . $e->getMessage());
        }
    }

    /**
     * Create a new availability record for external calendar events
     */
    public function createExternalAvailability(
        UserEntity $user,
        string $title,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        string $source,
        string $sourceId,
        ?string $description = null,
        string $status = 'confirmed'  
    ): UserAvailabilityEntity {
        try {

            $existing = $this->crudManager->findMany(
                UserAvailabilityEntity::class,
                [],
                1,
                1,
                [
                    'user' => $user,
                    'source' => $source,
                    'sourceId' => $sourceId
                ]
            );
            
            if (count($existing) > 0) {
                // Update existing entry instead of creating a new one
                $existing = $existing[0];
                $existing->setTitle($title);
                $existing->setDescription($description);
                $existing->setStartTime($startTime);
                $existing->setEndTime($endTime);
                $existing->setStatus($status);  // Make sure this is using the passed status
                $existing->setLastSynced(new \DateTime());
                $existing->setDeleted(false); // Undelete if previously deleted
                
                $this->entityManager->persist($existing);
                $this->entityManager->flush();
                
                return $existing;
            }
            
            // Create new entry
            $availability = new UserAvailabilityEntity();
            $availability->setUser($user);
            $availability->setTitle($title);
            $availability->setDescription($description);
            $availability->setStartTime($startTime);
            $availability->setEndTime($endTime);
            $availability->setSource($source);
            $availability->setSourceId($sourceId);
            $availability->setStatus($status);  // Make sure this is using the passed status
            $availability->setLastSynced(new \DateTime());
    
            $this->entityManager->persist($availability);
            $this->entityManager->flush();
    
            return $availability;
        } catch (\Exception $e) {
            throw new AccountException('Failed to create external availability: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing availability record
     */
    public function updateAvailability(
        UserAvailabilityEntity $availability,
        array $data
    ): void {
        try {
            $constraints = [
                'title' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 1, 'max' => 255]),
                ]),
                'description' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
                'start_time' => new Assert\Optional([
                    new Assert\Type('\DateTimeInterface'),
                ]),
                'end_time' => new Assert\Optional([
                    new Assert\Type('\DateTimeInterface'),
                ]),
                'status' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Choice(['choices' => ['confirmed', 'tentative', 'canceled']]),
                ]),
            ];

            // If both start_time and end_time are provided, validate that end is after start
            if (isset($data['start_time']) && isset($data['end_time'])) {
                if ($data['start_time'] >= $data['end_time']) {
                    throw new AccountException('End time must be after start time');
                }
            }

            $this->crudManager->update($availability, $data, $constraints);
            
            // Update lastSynced timestamp for external sources
            if ($availability->getSource() !== 'internal') {
                $availability->setLastSynced(new \DateTime());
                $this->entityManager->persist($availability);
                $this->entityManager->flush();
            }
        } catch (CrudException $e) {
            throw new AccountException('Failed to update availability: ' . $e->getMessage());
        }
    }

    /**
     * Delete (soft) an availability record
     */
    public function deleteAvailability(UserAvailabilityEntity $availability): void
    {
        try {
            $availability->setDeleted(true);
            $this->entityManager->persist($availability);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new AccountException('Failed to delete availability: ' . $e->getMessage());
        }
    }

    /**
     * Permanently delete an availability record
     */
    public function hardDeleteAvailability(UserAvailabilityEntity $availability): void
    {
        try {
            $this->entityManager->remove($availability);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new AccountException('Failed to delete availability permanently: ' . $e->getMessage());
        }
    }

    /**
     * Batch create availability records (useful for syncing external calendars)
     */
    public function batchCreateExternalAvailability(
        UserEntity $user,
        array $items,
        string $source
    ): array {
        $created = [];
        $this->entityManager->beginTransaction();
        
        try {
            foreach ($items as $item) {
                if (!isset($item['title'], $item['start_time'], $item['end_time'], $item['source_id'])) {
                    throw new AccountException('Missing required fields for availability');
                }
                
                // Pass the status directly rather than using a default value
                $status = isset($item['status']) ? $item['status'] : 'confirmed';
                
                $availability = $this->createExternalAvailability(
                    $user,
                    $item['title'],
                    $item['start_time'],
                    $item['end_time'],
                    $source,
                    $item['source_id'],
                    $item['description'] ?? null,
                    $status
                );
                
                $created[] = $availability;
            }
            
            $this->entityManager->commit();
            return $created;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new AccountException('Failed to batch create availability: ' . $e->getMessage());
        }
    }

    /**
     * Handle event booking being created
     * Creates availability records automatically
     */
    public function handleEventBookingCreated(EventBookingEntity $booking): void
    {
        try {
            $event = $booking->getEvent();
            $title = $event->getName();
            
            // Get event assignees (hosts)
            $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                ->findBy(['event' => $event]);
            
            foreach ($assignees as $assignee) {
                // Only create availability entries for hosts
                if (in_array($assignee->getRole(), ['creator', 'admin', 'host'])) {
                    $this->createInternalAvailability(
                        $assignee->getUser(),
                        $title,
                        $booking->getStartTime(),
                        $booking->getEndTime(),
                        $event,
                        $booking
                    );
                }
            }
        } catch (\Exception $e) {
            // Log but don't throw so booking creation can continue
            error_log('Failed to create availability records: ' . $e->getMessage());
        }
    }

    /**
     * Handle event booking being updated
     * Updates availability records
     */
    public function handleEventBookingUpdated(EventBookingEntity $booking): void
    {
        try {
            // Find all availability records for this booking
            $availabilityRecords = $this->entityManager->getRepository(UserAvailabilityEntity::class)
                ->findBy([
                    'booking' => $booking,
                    'deleted' => false
                ]);
            
            // Update existing records with new time or status
            foreach ($availabilityRecords as $record) {
                $record->setStartTime($booking->getStartTime());
                $record->setEndTime($booking->getEndTime());
                
                if ($booking->isCancelled()) {
                    $record->setStatus('canceled');
                }
                
                $this->entityManager->persist($record);
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log but don't throw
            error_log('Failed to update availability records: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle event booking being cancelled
     */
    public function handleEventBookingCancelled(EventBookingEntity $booking): void
    {
        try {
            // Find all availability records for this booking
            $availabilityRecords = $this->entityManager->getRepository(UserAvailabilityEntity::class)
                ->findBy([
                    'booking' => $booking,
                    'deleted' => false
                ]);
            
            // Mark all as cancelled
            foreach ($availabilityRecords as $record) {
                $record->setStatus('canceled');
                $this->entityManager->persist($record);
            }
            
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log but don't throw
            error_log('Failed to cancel availability records: ' . $e->getMessage());
        }
    }
}