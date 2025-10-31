<?php

namespace App\Plugins\Email\Entity;

use App\Plugins\Email\Repository\EmailLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Table(name: 'email_logs')]
#[ORM\Index(columns: ['to', 'created_at'])]
#[ORM\Index(columns: ['message_id'])]
class EmailLogEntity
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
    
    #[ORM\Column(length: 20)]
    private string $status;
    
    #[ORM\Column(length: 50)]
    private string $provider;
    
    #[ORM\Column(nullable: true)]
    private ?string $messageId = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clickedAt = null;
    
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
    
    public function getStatus(): string
    {
        return $this->status;
    }
    
    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }
    
    public function getProvider(): string
    {
        return $this->provider;
    }
    
    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
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
    
    public function getError(): ?string
    {
        return $this->error;
    }
    
    public function setError(?string $error): self
    {
        $this->error = $error;
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
    
    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }
    
    public function setOpenedAt(?\DateTime $openedAt): self
    {
        $this->openedAt = $openedAt ? \DateTimeImmutable::createFromMutable($openedAt) : null;
        return $this;
    }
    
    public function getClickedAt(): ?\DateTimeImmutable
    {
        return $this->clickedAt;
    }
    
    public function setClickedAt(?\DateTime $clickedAt): self
    {
        $this->clickedAt = $clickedAt ? \DateTimeImmutable::createFromMutable($clickedAt) : null;
        return $this;
    }
    
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}