<?php

namespace App\Plugins\Forms\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Account\Entity\UserEntity;

#[ORM\Entity(repositoryClass: "App\Plugins\Forms\Repository\FormSubmissionRepository")]
#[ORM\Table(name: "form_submissions")]
#[ORM\HasLifecycleCallbacks]
class FormSubmissionEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: FormEntity::class)]
    #[ORM\JoinColumn(name: "form_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private FormEntity $form;

    #[ORM\ManyToOne(targetEntity: EventEntity::class)]
    #[ORM\JoinColumn(name: "event_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?EventEntity $event = null;

    #[ORM\ManyToOne(targetEntity: EventBookingEntity::class)]
    #[ORM\JoinColumn(name: "booking_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?EventBookingEntity $booking = null;

    #[ORM\Column(name: "data_json", type: "json", nullable: false)]
    private array $dataJson = [];

    #[ORM\Column(name: "submitter_email", type: "string", length: 255, nullable: true)]
    private ?string $submitterEmail = null;

    #[ORM\Column(name: "submitter_name", type: "string", length: 255, nullable: true)]
    private ?string $submitterName = null;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "submitter_user_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?UserEntity $submitterUser = null;

    #[ORM\Column(name: "ip_address", type: "string", length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: "user_agent", type: "text", nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: "submission_source", type: "string", length: 50, options: ["default" => "web"])]
    private string $submissionSource = 'web';

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
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

    public function getForm(): FormEntity
    {
        return $this->form;
    }

    public function setForm(FormEntity $form): self
    {
        $this->form = $form;
        return $this;
    }

    public function getEvent(): ?EventEntity
    {
        return $this->event;
    }

    public function setEvent(?EventEntity $event): self
    {
        $this->event = $event;
        return $this;
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

    public function getDataJson(): array
    {
        return $this->dataJson;
    }

    public function setDataJson(array $dataJson): self
    {
        $this->dataJson = $dataJson;
        return $this;
    }

    public function getSubmitterEmail(): ?string
    {
        return $this->submitterEmail;
    }

    public function setSubmitterEmail(?string $submitterEmail): self
    {
        $this->submitterEmail = $submitterEmail;
        return $this;
    }

    public function getSubmitterName(): ?string
    {
        return $this->submitterName;
    }

    public function setSubmitterName(?string $submitterName): self
    {
        $this->submitterName = $submitterName;
        return $this;
    }

    public function getSubmitterUser(): ?UserEntity
    {
        return $this->submitterUser;
    }

    public function setSubmitterUser(?UserEntity $submitterUser): self
    {
        $this->submitterUser = $submitterUser;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getSubmissionSource(): string
    {
        return $this->submissionSource;
    }

    public function setSubmissionSource(string $submissionSource): self
    {
        $this->submissionSource = $submissionSource;
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
        $data = [
            'id' => $this->getId(),
            'form_id' => $this->getForm()->getId(),
            'data' => $this->getDataJson(),
            'submitter_email' => $this->getSubmitterEmail(),
            'submitter_name' => $this->getSubmitterName(),
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'submission_source' => $this->getSubmissionSource(),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
        ];

        if ($this->getEvent()) {
            $data['event_id'] = $this->getEvent()->getId();
        }

        if ($this->getBooking()) {
            $data['booking_id'] = $this->getBooking()->getId();
        }

        if ($this->getSubmitterUser()) {
            $data['submitter_user_id'] = $this->getSubmitterUser()->getId();
        }

        return $data;
    }
}