<?php

namespace App\Plugins\Integrations\Google\Calendar\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;

use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: "App\Plugins\Integrations\Google\Calendar\Repository\GoogleCalendarEventRepository")]
#[ORM\Table(name: "integration_google_calendar_events")]
#[ORM\Index(columns: ["user_id", "start_time"])]
#[ORM\Index(columns: ["user_id", "end_time"])]
#[ORM\Index(columns: ["google_event_id"])]
#[ORM\Index(columns: ["calendar_id"])]
class GoogleCalendarEventEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "user_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $user;

    #[ORM\ManyToOne(targetEntity: IntegrationEntity::class)]
    #[ORM\JoinColumn(name: "integration_id", referencedColumnName: "id", nullable: false)]
    private IntegrationEntity $integration;

    #[ORM\Column(name: "google_event_id", type: "string", length: 255)]
    private string $googleEventId;

    #[ORM\Column(name: "calendar_id", type: "string", length: 255)]
    private string $calendarId;

    #[ORM\Column(name: "calendar_name", type: "string", length: 255, nullable: true)]
    private ?string $calendarName = null;

    #[ORM\Column(name: "title", type: "string", length: 255)]
    private string $title;

    #[ORM\Column(name: "description", type: "text", nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: "location", type: "string", length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(name: "start_time", type: "datetime", nullable: false)]
    private DateTimeInterface $startTime;

    #[ORM\Column(name: "end_time", type: "datetime", nullable: false)]
    private DateTimeInterface $endTime;

    #[ORM\Column(name: "is_all_day", type: "boolean", options: ["default" => false])]
    private bool $isAllDay = false;

    #[ORM\Column(name: "status", type: "string", length: 50, nullable: false)]
    private string $status = 'confirmed';

    #[ORM\Column(name: "transparency", type: "string", length: 50, nullable: true)]
    private ?string $transparency = null;

    #[ORM\Column(name: "organizer_email", type: "string", length: 255, nullable: true)]
    private ?string $organizerEmail = null;

    #[ORM\Column(name: "is_organizer", type: "boolean", options: ["default" => false])]
    private bool $isOrganizer = false;

    #[ORM\Column(name: "html_link", type: "string", length: 512, nullable: true)]
    private ?string $htmlLink = null;
    
    #[ORM\Column(name: "etag", type: "string", length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "synced_at", type: "datetime", nullable: false)]
    private DateTimeInterface $syncedAt;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
        $this->syncedAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function setUser(UserEntity $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getIntegration(): IntegrationEntity
    {
        return $this->integration;
    }

    public function setIntegration(IntegrationEntity $integration): self
    {
        $this->integration = $integration;
        return $this;
    }

    public function getGoogleEventId(): string
    {
        return $this->googleEventId;
    }

    public function setGoogleEventId(string $googleEventId): self
    {
        $this->googleEventId = $googleEventId;
        return $this;
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    public function setCalendarId(string $calendarId): self
    {
        $this->calendarId = $calendarId;
        return $this;
    }

    public function getCalendarName(): ?string
    {
        return $this->calendarName;
    }

    public function setCalendarName(?string $calendarName): self
    {
        $this->calendarName = $calendarName;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;
        return $this;
    }

    public function getStartTime(): DateTimeInterface
    {
        return $this->startTime;
    }

    public function setStartTime(DateTimeInterface $startTime): self
    {
        $this->startTime = $startTime;
        return $this;
    }

    public function getEndTime(): DateTimeInterface
    {
        return $this->endTime;
    }

    public function setEndTime(DateTimeInterface $endTime): self
    {
        $this->endTime = $endTime;
        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->isAllDay;
    }

    public function setIsAllDay(bool $isAllDay): self
    {
        $this->isAllDay = $isAllDay;
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

    public function getTransparency(): ?string
    {
        return $this->transparency;
    }

    public function setTransparency(?string $transparency): self
    {
        $this->transparency = $transparency;
        return $this;
    }

    public function getOrganizerEmail(): ?string
    {
        return $this->organizerEmail;
    }

    public function setOrganizerEmail(?string $organizerEmail): self
    {
        $this->organizerEmail = $organizerEmail;
        return $this;
    }

    public function isOrganizer(): bool
    {
        return $this->isOrganizer;
    }

    public function setIsOrganizer(bool $isOrganizer): self
    {
        $this->isOrganizer = $isOrganizer;
        return $this;
    }

    public function getHtmlLink(): ?string
    {
        return $this->htmlLink;
    }

    public function setHtmlLink(?string $htmlLink): self
    {
        $this->htmlLink = $htmlLink;
        return $this;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function setEtag(?string $etag): self
    {
        $this->etag = $etag;
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

    public function getSyncedAt(): DateTimeInterface
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(DateTimeInterface $syncedAt): self
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }

    /**
     * Mark availability as busy or free
     */
    public function isBusy(): bool
    {
        // If the event is transparent/"free", or cancelled, it doesn't block time
        if ($this->transparency === 'transparent' || $this->status === 'cancelled') {
            return false;
        }
        
        return true;
    }

    /**
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'user_id' => $this->getUser()->getId(),
            'integration_id' => $this->getIntegration()->getId(),
            'google_event_id' => $this->getGoogleEventId(),
            'calendar_id' => $this->getCalendarId(),
            'calendar_name' => $this->getCalendarName(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'location' => $this->getLocation(),
            'start_time' => $this->getStartTime()->format('Y-m-d H:i:s'),
            'end_time' => $this->getEndTime()->format('Y-m-d H:i:s'),
            'is_all_day' => $this->isAllDay(),
            'status' => $this->getStatus(),
            'transparency' => $this->getTransparency(),
            'is_busy' => $this->isBusy(),
            'is_organizer' => $this->isOrganizer(),
            'organizer_email' => $this->getOrganizerEmail(),
            'html_link' => $this->getHtmlLink(),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
            'synced_at' => $this->getSyncedAt()->format('Y-m-d H:i:s'),
        ];
    }
}