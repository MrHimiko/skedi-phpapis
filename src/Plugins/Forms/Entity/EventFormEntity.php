<?php

namespace App\Plugins\Forms\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Events\Entity\EventEntity;

#[ORM\Entity(repositoryClass: "App\Plugins\Forms\Repository\EventFormRepository")]
#[ORM\Table(name: "event_forms")]
#[ORM\HasLifecycleCallbacks]
class EventFormEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: EventEntity::class)]
    #[ORM\JoinColumn(name: "event_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private EventEntity $event;

    #[ORM\ManyToOne(targetEntity: FormEntity::class)]
    #[ORM\JoinColumn(name: "form_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private FormEntity $form;

    #[ORM\Column(name: "is_active", type: "boolean", options: ["default" => true])]
    private bool $isActive = true;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->created = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEvent(): EventEntity
    {
        return $this->event;
    }

    public function setEvent(EventEntity $event): self
    {
        $this->event = $event;
        return $this;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEvent()->getId(),
            'form_id' => $this->getForm()->getId(),
            'is_active' => $this->isActive(),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
        ];
    }
}