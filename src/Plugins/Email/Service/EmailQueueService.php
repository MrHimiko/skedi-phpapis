<?php

namespace App\Plugins\Email\Service;

use App\Plugins\Email\Entity\EmailQueueEntity;
use App\Plugins\Email\Repository\EmailQueueRepository;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;

class EmailQueueService
{
    private EntityManagerInterface $entityManager;
    private EmailQueueRepository $queueRepository;
    private CrudManager $crudManager;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EmailQueueRepository $queueRepository,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->queueRepository = $queueRepository;
        $this->crudManager = $crudManager;
    }
    
    /**
     * Add email to queue
     */
    public function add($to, string $template, array $data = [], array $options = []): int
    {
        try {
            $queueItem = new EmailQueueEntity();
            $queueItem->setTo(is_array($to) ? json_encode($to) : $to);
            $queueItem->setTemplate($template);
            $queueItem->setData($data);
            $queueItem->setOptions($options);
            $queueItem->setStatus('pending');
            $queueItem->setPriority($options['priority'] ?? 5);
            $queueItem->setAttempts(0);
            
            if (!empty($options['send_at'])) {
                $queueItem->setScheduledAt(new \DateTime($options['send_at']));
            }
            
            $this->entityManager->persist($queueItem);
            $this->entityManager->flush();
            
            return $queueItem->getId();
        } catch (\Exception $e) {
            // Log the actual error
            error_log('Failed to queue email: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get pending emails from queue
     */
    public function getPending(int $limit = 50): array
    {
        $now = new \DateTime();
        
        return $this->crudManager->findMany(
            EmailQueueEntity::class,
            [], 
            1,  
            $limit,
            [], 
            function($queryBuilder) use ($now) {
                $queryBuilder
                    ->andWhere('t1.status IN (:statuses)')
                    ->andWhere('(t1.scheduledAt IS NULL OR t1.scheduledAt <= :now)')
                    ->setParameter('statuses', ['pending', 'retry'])
                    ->setParameter('now', $now)
                    ->orderBy('t1.priority', 'DESC')
                    ->addOrderBy('t1.createdAt', 'ASC');
            }
        );
    }
    
    /**
     * Mark email as sent
     */
    public function markAsSent(EmailQueueEntity $queueItem, ?string $messageId = null): void
    {
        $queueItem->setStatus('sent');
        $queueItem->setSentAt(new \DateTime());
        $queueItem->setMessageId($messageId);
        $queueItem->setAttempts($queueItem->getAttempts() + 1);
        
        $this->entityManager->flush();
    }
    
    /**
     * Mark email as failed
     */
    public function markAsFailed(EmailQueueEntity $queueItem, string $error): void
    {
        $queueItem->setAttempts($queueItem->getAttempts() + 1);
        $queueItem->setLastError($error);
        
        // If max attempts reached, mark as failed permanently
        if ($queueItem->getAttempts() >= 3) {
            $queueItem->setStatus('failed');
        } else {
            $queueItem->setStatus('retry');
            // Exponential backoff for retry
            $nextAttempt = new \DateTime();
            $nextAttempt->modify('+' . pow(2, $queueItem->getAttempts()) . ' minutes');
            $queueItem->setScheduledAt($nextAttempt);
        }
        
        $this->entityManager->flush();
    }
    
    /**
     * Get queue statistics
     */
    public function getStatistics(): array
    {
        $stats = [];
        $statuses = ['pending', 'sent', 'failed', 'retry'];
        
        foreach ($statuses as $status) {
            $count = $this->crudManager->count(
                EmailQueueEntity::class,
                ['status' => $status]
            );
            $stats[$status] = $count;
        }
        
        return $stats;
    }
}