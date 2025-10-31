<?php

namespace App\Plugins\Forms\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;

#[ORM\Entity(repositoryClass: "App\Plugins\Forms\Repository\FormRepository")]
#[ORM\Table(name: "forms")]
#[ORM\HasLifecycleCallbacks]
class FormEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\Column(name: "name", type: "string", length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(name: "slug", type: "string", length: 255, nullable: false, unique: true)]
    private string $slug;

    #[ORM\Column(name: "description", type: "text", nullable: true)]
    private ?string $description = null;

    // Made nullable - forms are now global
    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: true)]
    private ?OrganizationEntity $organization = null;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "created_by", referencedColumnName: "id", nullable: false)]
    private UserEntity $createdBy;

    #[ORM\Column(name: "fields_json", type: "json", nullable: false)]
    private array $fieldsJson = [];

    #[ORM\Column(name: "settings_json", type: "json", nullable: true)]
    private ?array $settingsJson = [];

    #[ORM\Column(name: "is_active", type: "boolean", options: ["default" => true])]
    private bool $isActive = true;

    #[ORM\Column(name: "allow_multiple_submissions", type: "boolean", options: ["default" => true])]
    private bool $allowMultipleSubmissions = true;

    #[ORM\Column(name: "requires_authentication", type: "boolean", options: ["default" => false])]
    private bool $requiresAuthentication = false;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    // Use consistent system field names
    const SYSTEM_FIELD_NAME = 'system_contact_name';
    const SYSTEM_FIELD_EMAIL = 'system_contact_email';
    const GUEST_REPEATER_FIELD = 'system_contact_guests';

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
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

    public function getOrganization(): ?OrganizationEntity
    {
        return $this->organization;
    }

    public function setOrganization(?OrganizationEntity $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    public function getCreatedBy(): UserEntity
    {
        return $this->createdBy;
    }

    public function setCreatedBy(UserEntity $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getDefaultFields(): array
    {
        return [
            [
                'type' => 'text',
                'name' => self::SYSTEM_FIELD_NAME,
                'label' => 'Your Name',
                'placeholder' => 'Enter your name',
                'required' => true,
                'deletable' => false,
                'system_field' => true,
                'order' => 1
            ],
            [
                'type' => 'email',
                'name' => self::SYSTEM_FIELD_EMAIL,
                'label' => 'Email Address',
                'placeholder' => 'your@email.com',
                'required' => true,
                'deletable' => false,
                'system_field' => true,
                'order' => 2
            ],
            [
                'id' => self::GUEST_REPEATER_FIELD,
                'type' => 'guest_repeater',
                'name' => self::GUEST_REPEATER_FIELD,
                'label' => 'Add Guests',
                'placeholder' => '',
                'required' => false,
                'deletable' => true,
                'system_field' => false,
                'max_guests' => 10,
                'order' => 3,
                'unique' => true,
                'colSpan' => 12
            ]
        ];
    }

    /**
     * Ensure system fields are always present in fields JSON
     */
    public function setFieldsJson(array $fieldsJson): self
    {
        // Clean up any duplicate system fields with old naming
        $cleanedFields = [];
        $systemFieldNames = [self::SYSTEM_FIELD_NAME, self::SYSTEM_FIELD_EMAIL, self::GUEST_REPEATER_FIELD];
        $oldSystemNames = ['name', 'email', 'additional_guests'];
        $foundSystemFields = [];
        
        foreach ($fieldsJson as $field) {
            $fieldName = $field['name'] ?? '';
            
            // Skip old system field names only if they're marked as system fields
            if (in_array($fieldName, $oldSystemNames) && !empty($field['system_field'])) {
                continue;
            }
            
            // Track found system fields
            if (in_array($fieldName, $systemFieldNames)) {
                $foundSystemFields[$fieldName] = true;
            }
            
            // Add all fields to cleaned fields (including custom fields)
            $cleanedFields[] = $field;
        }
        
        // Add missing system fields at the beginning
        $defaultFields = $this->getDefaultFields();
        $systemFieldsToAdd = [];
        
        foreach ($defaultFields as $defaultField) {
            if (!isset($foundSystemFields[$defaultField['name']])) {
                // Only add required system fields (name and email)
                if (in_array($defaultField['name'], [self::SYSTEM_FIELD_NAME, self::SYSTEM_FIELD_EMAIL])) {
                    $systemFieldsToAdd[] = $defaultField;
                }
            }
        }
        
        // Prepend missing system fields to the beginning
        $this->fieldsJson = array_merge($systemFieldsToAdd, $cleanedFields);
        
        return $this;
    }


    public function getFieldsJson(): array
    {
        return $this->fieldsJson;
    }

    public function getSettingsJson(): ?array
    {
        return $this->settingsJson;
    }

    public function setSettingsJson(?array $settingsJson): self
    {
        $this->settingsJson = $settingsJson;
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

    public function isAllowMultipleSubmissions(): bool
    {
        return $this->allowMultipleSubmissions;
    }

    public function setAllowMultipleSubmissions(bool $allowMultipleSubmissions): self
    {
        $this->allowMultipleSubmissions = $allowMultipleSubmissions;
        return $this;
    }

    public function isRequiresAuthentication(): bool
    {
        return $this->requiresAuthentication;
    }

    public function setRequiresAuthentication(bool $requiresAuthentication): self
    {
        $this->requiresAuthentication = $requiresAuthentication;
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

    public function setCreated(DateTimeInterface $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    public function setUpdated(DateTimeInterface $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updated = new DateTime();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'description' => $this->getDescription(),
            'organization' => $this->getOrganization() ? [
                'id' => $this->getOrganization()->getId(),
                'name' => $this->getOrganization()->getName(),
                'slug' => $this->getOrganization()->getSlug()
            ] : null,
            'created_by' => [
                'id' => $this->getCreatedBy()->getId(),
                'name' => $this->getCreatedBy()->getName(),
                'email' => $this->getCreatedBy()->getEmail()
            ],
            'fields' => $this->getFieldsJson(),
            // Return settings as-is if it's not null, otherwise return empty object
            'settings' => $this->getSettingsJson() ?: (object)[],
            'is_active' => $this->isActive(),
            'allow_multiple_submissions' => $this->isAllowMultipleSubmissions(), 
            'requires_authentication' => $this->isRequiresAuthentication(),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s')
        ];
    }
}