<?php

namespace App\Plugins\Email\Entity;

use App\Plugins\Email\Repository\EmailQueueRepository;
use App\Plugins\Events\Entity\EventBookingEntity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailQueueRepository::class)]
#[ORM\Table(name: 'email_queue')]
#[ORM\Index(columns: ['status', 'scheduled_at'])]
class EmailQueueEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\Column(name: 'to_email', type: 'text')]
    private string $to;
    
    #[ORM\Column(length: 100)]
    private string $template;
    
    #[ORM\Column(type: 'json')]
    private array $data = [];
    
    #[ORM\Column(type: 'json')]
    private array $options = [];
    
    #[ORM\Column(length: 20)]
    private string $status = 'pending';
    
    #[ORM\Column(type: 'smallint')]
    private int $priority = 5;
    
    #[ORM\Column(type: 'smallint')]
    private int $attempts = 0;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\ManyToOne(targetEntity: EventBookingEntity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?EventBookingEntity $booking = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $reminderType = null;

    
    #[ORM\Column(nullable: true)]
    private ?string $messageId = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;
    
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
    
    public function getId(): ?int
    {
        return $this->id;
    }
    
    public function getTo(): string
    {
        return $this->to;
    }
    
    public function setTo(string $to): self
    {
        $this->to = $to;
        return $this;
    }
    
    public function getTemplate(): string
    {
        return $this->template;
    }
    
    public function setTemplate(string $template): self
    {
        $this->template = $template;
        return $this;
    }
    
    public function getData(): array
    {
        return $this->data;
    }
    
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }
    
    public function getOptions(): array
    {
        return $this->options;
    }
    
    public function setOptions(array $options): self
    {
        $this->options = $options;
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
    
    public function getPriority(): int
    {
        return $this->priority;
    }
    
    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }
    
    public function getAttempts(): int
    {
        return $this->attempts;
    }
    
    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;
        return $this;
    }
    
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
    
    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;
        return $this;
    }
    
    public function getMessageId(): ?string
    {
        return $this->messageId;
    }
    
    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;
        return $this;
    }
    
    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }
    
    public function setScheduledAt(?\DateTime $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt ? \DateTimeImmutable::createFromMutable($scheduledAt) : null;
        return $this;
    }
    
    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
    
    public function setSentAt(?\DateTime $sentAt): self
    {
        $this->sentAt = $sentAt ? \DateTimeImmutable::createFromMutable($sentAt) : null;
        return $this;
    }
    
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }


    public function getBooking(): ?EventBookingEntity
    {
        return $this->booking;
    }

    public function setBooking(?EventBookingEntity $booking): self
    {
        $this->booking = $booking;
        return $this;
    }

    public function getReminderType(): ?string
    {
        return $this->reminderType;
    }

    public function setReminderType(?string $reminderType): self
    {
        $this->reminderType = $reminderType;
        return $this;
    }


}