<?php

namespace App\Plugins\Teams\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Organizations\Entity\OrganizationEntity;

#[ORM\Entity]
#[ORM\Table(name: "teams")]
#[ORM\HasLifecycleCallbacks]
class TeamEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\Column(name: "name", type: "string", length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(name: "slug", type: "string", length: 255, unique: true, nullable: false)]
    private string $slug;
    
    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;
    
    #[ORM\ManyToOne(targetEntity: TeamEntity::class)]
    #[ORM\JoinColumn(name: "parent_team_id", referencedColumnName: "id", nullable: true)]
    private ?TeamEntity $parentTeam = null;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    #[ORM\Column(name: "color", type: "string", length: 50, options: ["default" => "#FFDE0E"])]
    private string $color = "#FFDE0E";


    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;
    
    private ?string $role = null;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    public function getId(): int
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;
        return $this;
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

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
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
    
    public function getParentTeam(): ?TeamEntity
    {
        return $this->parentTeam;
    }
    
    public function setParentTeam(?TeamEntity $parentTeam): self
    {
        $this->parentTeam = $parentTeam;
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function toArray(): array
    {
        $data = [
            'id'              => $this->getId(),
            'name'            => $this->getName(),
            'slug'            => $this->getSlug(),
            'color'           => $this->getColor(),
            'organization_id' => $this->getOrganization()->getId(),
            'deleted'         => $this->isDeleted(),
            'updated'         => $this->getUpdated()->format('Y-m-d H:i:s'),
            'created'         => $this->getCreated()->format('Y-m-d H:i:s'),
        ];
        
        if ($this->getParentTeam()) {
            $data['parent_team_id'] = $this->getParentTeam()->getId();
        }
        
        return $data;
    }
}