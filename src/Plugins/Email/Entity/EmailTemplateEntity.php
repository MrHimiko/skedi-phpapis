<?php

namespace App\Plugins\Email\Entity;

use App\Plugins\Email\Repository\EmailTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailTemplateRepository::class)]
#[ORM\Table(name: 'email_templates')]
class EmailTemplateEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\Column(length: 100, unique: true)]
    private string $name;
    
    #[ORM\Column(length: 255)]
    private string $providerId;
    
    #[ORM\Column(type: 'text')]
    private string $description;
    
    #[ORM\Column(type: 'json')]
    private array $defaultData = [];
    
    #[ORM\Column(type: 'json')]
    private array $requiredFields = [];
    
    #[ORM\Column(type: 'boolean')]
    private bool $active = true;
    
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
    
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    
    public function getId(): ?int
    {
        return $this->id;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    public function getProviderId(): string
    {
        return $this->providerId;
    }
    
    public function setProviderId(string $providerId): self
    {
        $this->providerId = $providerId;
        return $this;
    }
    
    public function getDescription(): string
    {
        return $this->description;
    }
    
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }
    
    public function getDefaultData(): array
    {
        return $this->defaultData;
    }
    
    public function setDefaultData(array $defaultData): self
    {
        $this->defaultData = $defaultData;
        return $this;
    }
    
    public function getRequiredFields(): array
    {
        return $this->requiredFields;
    }
    
    public function setRequiredFields(array $requiredFields): self
    {
        $this->requiredFields = $requiredFields;
        return $this;
    }
    
    public function isActive(): bool
    {
        return $this->active;
    }
    
    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }
    
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
    
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}