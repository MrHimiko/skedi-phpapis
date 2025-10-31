<?php

namespace App\Plugins\PotentialLeads\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;

#[ORM\Entity]
#[ORM\Table(name: "host_potential_leads")]
#[ORM\HasLifecycleCallbacks]
class HostPotentialLeadEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PotentialLeadEntity::class)]
    #[ORM\JoinColumn(name: "potential_lead_id", referencedColumnName: "id", nullable: false)]
    private PotentialLeadEntity $potentialLead;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "host_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $host;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\Column(name: "created", type: "datetime", nullable: false)]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false)]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    public function __construct()
    {
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

    public function getPotentialLead(): PotentialLeadEntity
    {
        return $this->potentialLead;
    }

    public function setPotentialLead(PotentialLeadEntity $potentialLead): self
    {
        $this->potentialLead = $potentialLead;
        return $this;
    }

    public function getHost(): UserEntity
    {
        return $this->host;
    }

    public function setHost(UserEntity $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function getOrganization(): OrganizationEntity
    {
        return $this->organization;
    }

    public function setOrganization(OrganizationEntity $organization): self
    {
        $this->organization = $organization;
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