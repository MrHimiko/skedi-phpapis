<?php

namespace App\Plugins\Email\Service;

use App\Plugins\Email\Entity\EmailLogEntity;
use App\Plugins\Email\Repository\EmailLogRepository;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;

class EmailLogService
{
    private EntityManagerInterface $entityManager;
    private EmailLogRepository $logRepository;
    private CrudManager $crudManager;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EmailLogRepository $logRepository,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->logRepository = $logRepository;
        $this->crudManager = $crudManager;
    }
    
    /**
     * Get logs by recipient
     */
    public function getByRecipient(string $email, int $page = 1, int $limit = 50): array
    {
        $filters = [
            'to' => ['LIKE', '%' . $email . '%']
        ];
        
        $orderBy = ['created_at' => 'DESC'];
        
        return $this->crudManager->getMany(
            EmailLogEntity::class,
            $filters,
            $page,
            $limit,
            $orderBy
        );
    }
    
    /**
     * Get email statistics for date range
     */
    public function getStatistics(\DateTime $startDate, \DateTime $endDate): array
    {
        $filters = [
            'created_at' => ['BETWEEN', [$startDate, $endDate]]
        ];
        
        // Get counts by status
        $stats = [
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'opened' => 0,
            'clicked' => 0
        ];
        
        // Count by status
        $statuses = ['sent', 'failed', 'sending'];
        foreach ($statuses as $status) {
            $count = $this->crudManager->count(
                EmailLogEntity::class,
                array_merge($filters, ['status' => $status])
            );
            $stats[$status] = $count;
            $stats['total'] += $count;
        }
        
        // Count opened
        $stats['opened'] = $this->crudManager->count(
            EmailLogEntity::class,
            array_merge($filters, ['opened_at' => ['NOT_NULL']])
        );
        
        // Count clicked
        $stats['clicked'] = $this->crudManager->count(
            EmailLogEntity::class,
            array_merge($filters, ['clicked_at' => ['NOT_NULL']])
        );
        
        return $stats;
    }
    
    /**
     * Update tracking information
     */
    public function updateTracking(string $messageId, string $event): void
    {
        $log = $this->crudManager->getOne(
            EmailLogEntity::class,
            ['message_id' => $messageId]
        );
        
        if (!$log) {
            return;
        }
        
        $updates = [];
        
        switch ($event) {
            case 'open':
                if (!$log->getOpenedAt()) {
                    $updates['opened_at'] = new \DateTime();
                }
                break;
                
            case 'click':
                if (!$log->getClickedAt()) {
                    $updates['clicked_at'] = new \DateTime();
                }
                break;
        }
        
        if (!empty($updates)) {
            $this->crudManager->update($log, $updates);
        }
    }
}