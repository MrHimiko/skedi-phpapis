<?php

namespace App\Plugins\Events\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: "event_form_fields")]
#[ORM\HasLifecycleCallbacks]
class EventFormFieldEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: EventEntity::class)]
    #[ORM\JoinColumn(name: "event_id", referencedColumnName: "id", nullable: false)]
    private EventEntity $event;
    
    #[ORM\Column(name: "field_name", type: "string", length: 255, nullable: false)]
    private string $fieldName;
    
    #[ORM\Column(name: "field_type", type: "string", length: 50, nullable: false)]
    private string $fieldType;
    
    #[ORM\Column(name: "required", type: "boolean", options: ["default" => false])]
    private bool $required = false;
    
    #[ORM\Column(name: "display_order", type: "integer", options: ["default" => 0])]
    private int $displayOrder = 0;
    
    #[ORM\Column(name: "options", type: "text", nullable: true)]
    private ?string $options = null;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
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
    
    public function getFieldName(): string
    {
        return $this->fieldName;
    }
    
    public function setFieldName(string $fieldName): self
    {
        $this->fieldName = $fieldName;
        return $this;
    }
    
    public function getFieldType(): string
    {
        return $this->fieldType;
    }
    
    public function setFieldType(string $fieldType): self
    {
        $this->fieldType = $fieldType;
        return $this;
    }
    
    public function isRequired(): bool
    {
        return $this->required;
    }
    
    public function setRequired(bool $required): self
    {
        $this->required = $required;
        return $this;
    }
    
    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }
    
    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
        return $this;
    }
    
    public function getOptions(): ?string
    {
        return $this->options;
    }
    
    public function getOptionsAsArray(): ?array
    {
        return $this->options ? json_decode($this->options, true) : null;
    }
    
    public function setOptions(?string $options): self
    {
        $this->options = $options;
        return $this;
    }
    
    public function setOptionsFromArray(?array $options): self
    {
        $this->options = $options ? json_encode($options) : null;
        return $this;
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

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEvent()->getId(),
            'field_name' => $this->getFieldName(),
            'field_type' => $this->getFieldType(),
            'required' => $this->isRequired(),
            'display_order' => $this->getDisplayOrder(),
            'options' => $this->getOptionsAsArray(),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
        ];
    }
}