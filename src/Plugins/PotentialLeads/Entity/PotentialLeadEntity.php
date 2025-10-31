<?php

namespace App\Plugins\PotentialLeads\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: "App\Plugins\PotentialLeads\Repository\PotentialLeadRepository")]
#[ORM\Table(name: "potential_leads")]
#[ORM\HasLifecycleCallbacks]
class PotentialLeadEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(name: "email", type: "string", length: 255, nullable: false)]
    private string $email;

    #[ORM\Column(name: "name", type: "string", length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: "timezone", type: "string", length: 100, nullable: true)]
    private ?string $timezone = null;

    #[ORM\Column(name: "captured_at", type: "datetime", nullable: false)]
    private DateTimeInterface $capturedAt;

    #[ORM\Column(name: "created", type: "datetime", nullable: false)]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false)]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    public function __construct()
    {
        $this->capturedAt = new DateTime();
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updated = new DateTime();
    }

    // Getters and setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getCapturedAt(): DateTimeInterface
    {
        return $this->capturedAt;
    }

    public function setCapturedAt(DateTimeInterface $capturedAt): self
    {
        $this->capturedAt = $capturedAt;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;
        return $this;
    }
}