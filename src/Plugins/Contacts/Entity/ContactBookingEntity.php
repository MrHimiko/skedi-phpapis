<?php

namespace App\Plugins\Contacts\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;

#[ORM\Entity]
#[ORM\Table(name: "contact_bookings")]
class ContactBookingEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContactEntity::class)]
    #[ORM\JoinColumn(name: "contact_id", referencedColumnName: "id", nullable: false)]
    private ContactEntity $contact;

    #[ORM\ManyToOne(targetEntity: EventBookingEntity::class)]
    #[ORM\JoinColumn(name: "booking_id", referencedColumnName: "id", nullable: false)]
    private EventBookingEntity $booking;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\ManyToOne(targetEntity: EventEntity::class)]
    #[ORM\JoinColumn(name: "event_id", referencedColumnName: "id", nullable: false)]
    private EventEntity $event;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "host_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $host;

    #[ORM\Column(name: "created", type: "datetime", nullable: false)]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->created = new DateTime();
    }

    // Add all getters and setters
    public function getId(): ?int { return $this->id; }
    
    public function getContact(): ContactEntity { return $this->contact; }
    public function setContact(ContactEntity $contact): self { $this->contact = $contact; return $this; }
    
    public function getBooking(): EventBookingEntity { return $this->booking; }
    public function setBooking(EventBookingEntity $booking): self { $this->booking = $booking; return $this; }
    
    public function getOrganization(): OrganizationEntity { return $this->organization; }
    public function setOrganization(OrganizationEntity $organization): self { $this->organization = $organization; return $this; }
    
    public function getEvent(): EventEntity { return $this->event; }
    public function setEvent(EventEntity $event): self { $this->event = $event; return $this; }
    
    public function getHost(): UserEntity { return $this->host; }
    public function setHost(UserEntity $host): self { $this->host = $host; return $this; }
    
    public function getCreated(): DateTimeInterface { return $this->created; }
}