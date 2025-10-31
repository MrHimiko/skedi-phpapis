<?php
// src/Plugins/Workflows/Entity/WorkflowExecutionEntity.php

namespace App\Plugins\Workflows\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Workflows\Repository\WorkflowExecutionRepository;

#[ORM\Entity(repositoryClass: WorkflowExecutionRepository::class)]
#[ORM\Table(name: 'workflow_executions')]
class WorkflowExecutionEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowEntity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WorkflowEntity $workflow;

    #[ORM\Column(type: 'json')]
    private array $triggerData = [];

    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending';

    #[ORM\Column(name: 'started_at', type: 'datetime')]
    private \DateTime $startedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime', nullable: true)]
    private ?\DateTime $completedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    public function __construct()
    {
        $this->startedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): WorkflowEntity
    {
        return $this->workflow;
    }

    public function setWorkflow(WorkflowEntity $workflow): self
    {
        $this->workflow = $workflow;
        return $this;
    }

    public function getTriggerData(): array
    {
        return $this->triggerData;
    }

    public function setTriggerData(array $triggerData): self
    {
        $this->triggerData = $triggerData;
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): \DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTime $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTime
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTime $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow->getId(),
            'trigger_data' => $this->triggerData,
            'context' => $this->context,
            'status' => $this->status,
            'started_at' => $this->startedAt->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
            'error' => $this->error,
        ];
    }
}