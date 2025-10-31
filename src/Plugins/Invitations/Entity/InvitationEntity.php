<?php

namespace App\Plugins\Invitations\Entity;

use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Teams\Entity\TeamEntity;
use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: "App\Plugins\Invitations\Repository\InvitationRepository")]
#[ORM\Table(name: 'invitations')]
#[ORM\HasLifecycleCallbacks]
class InvitationEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'invited_by_id', nullable: false)]
    private UserEntity $invitedBy;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: 'organization_id', nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\ManyToOne(targetEntity: TeamEntity::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: true)]
    private ?TeamEntity $team = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $role = 'member';

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $token;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $acceptedAt = null;

    #[ORM\Column(name: 'deleted', type: 'boolean', options: ['default' => false])]
    private bool $deleted = false;

    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: false)]
    private DateTimeInterface $created;

    #[ORM\Column(name: 'updated_at', type: 'datetime', nullable: false)]
    private DateTimeInterface $updated;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    public function getId(): int
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

    public function getInvitedBy(): UserEntity
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(UserEntity $invitedBy): self
    {
        $this->invitedBy = $invitedBy;
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

    public function getTeam(): ?TeamEntity
    {
        return $this->team;
    }

    public function setTeam(?TeamEntity $team): self
    {
        $this->team = $team;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
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

    public function getAcceptedAt(): ?\DateTime
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTime $acceptedAt): self
    {
        $this->acceptedAt = $acceptedAt;
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

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updated = new DateTime();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'email' => $this->getEmail(),
            'invited_by' => $this->getInvitedBy()->toArray(),
            'organization' => [
                'id' => $this->getOrganization()->getId(),
                'name' => $this->getOrganization()->getName(),
            ],
            'team' => $this->getTeam() ? [
                'id' => $this->getTeam()->getId(),
                'name' => $this->getTeam()->getName(),
            ] : null,
            'role' => $this->getRole(),
            'status' => $this->getStatus(),
            'accepted_at' => $this->getAcceptedAt()?->format('Y-m-d H:i:s'),
            'created_at' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated_at' => $this->getUpdated()->format('Y-m-d H:i:s'),
        ];
    }
}