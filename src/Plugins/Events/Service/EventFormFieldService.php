<?php

namespace App\Plugins\Events\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventFormFieldEntity;
use App\Plugins\Events\Repository\EventFormFieldRepository;
use App\Plugins\Events\Exception\EventsException;

class EventFormFieldService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private EventFormFieldRepository $formFieldRepository;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        EventFormFieldRepository $formFieldRepository
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->formFieldRepository = $formFieldRepository;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                EventFormFieldEntity::class,
                $filters,
                $page,
                $limit,
                $criteria
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?EventFormFieldEntity
    {
        return $this->crudManager->findOne(EventFormFieldEntity::class, $id, $criteria);
    }

    public function create(array $data): EventFormFieldEntity
    {
        try {
            if (empty($data['event_id'])) {
                throw new EventsException('Event ID is required');
            }
            
            $event = $this->entityManager->getRepository(EventEntity::class)->find($data['event_id']);
            if (!$event) {
                throw new EventsException('Event not found');
            }
            
            if (empty($data['field_name']) || empty($data['field_type'])) {
                throw new EventsException('Field name and type are required');
            }
            
            $formField = new EventFormFieldEntity();
            $formField->setEvent($event);
            $formField->setFieldName($data['field_name']);
            $formField->setFieldType($data['field_type']);
            $formField->setRequired(!empty($data['required']) ? (bool)$data['required'] : false);
            $formField->setDisplayOrder(!empty($data['display_order']) ? (int)$data['display_order'] : 0);
            
            if (!empty($data['options']) && is_array($data['options'])) {
                $formField->setOptionsFromArray($data['options']);
            } elseif (!empty($data['options']) && is_string($data['options'])) {
                $formField->setOptions($data['options']);
            }
            
            $this->entityManager->persist($formField);
            $this->entityManager->flush();
            
            return $formField;
        } catch (\Exception $e) {
            throw new EventsException($e->getMessage());
        }
    }

    public function update(EventFormFieldEntity $formField, array $data): void
    {
        try {
            if (!empty($data['field_name'])) {
                $formField->setFieldName($data['field_name']);
            }
            
            if (!empty($data['field_type'])) {
                $formField->setFieldType($data['field_type']);
            }
            
            if (isset($data['required'])) {
                $formField->setRequired((bool)$data['required']);
            }
            
            if (isset($data['display_order'])) {
                $formField->setDisplayOrder((int)$data['display_order']);
            }
            
            if (!empty($data['options']) && is_array($data['options'])) {
                $formField->setOptionsFromArray($data['options']);
            } elseif (!empty($data['options']) && is_string($data['options'])) {
                $formField->setOptions($data['options']);
            }
            
            $this->entityManager->persist($formField);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new EventsException($e->getMessage());
        }
    }

    public function delete(EventFormFieldEntity $formField): void
    {
        try {
            $this->entityManager->remove($formField);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new EventsException($e->getMessage());
        }
    }
    
    public function getFormFieldsByEvent(EventEntity $event): array
    {
        return $this->formFieldRepository->findByEventOrderByDisplayOrder($event->getId());
    }
    
    public function validateFormData(EventEntity $event, array $formData): array
    {
        $errors = [];
        $formFields = $this->getFormFieldsByEvent($event);
        
        // Check for required fields
        foreach ($formFields as $field) {
            if ($field->isRequired() && empty($formData[$field->getFieldName()])) {
                $errors[$field->getFieldName()] = 'This field is required';
            }
        }
        
        // Check field types
        foreach ($formData as $fieldName => $value) {
            $matchingField = null;
            foreach ($formFields as $field) {
                if ($field->getFieldName() === $fieldName) {
                    $matchingField = $field;
                    break;
                }
            }
            
            if (!$matchingField) {
                continue; // Unknown field, skip validation
            }
            
            // Validate based on field type
            switch ($matchingField->getFieldType()) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$fieldName] = 'Invalid email format';
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$fieldName] = 'This field must be a number';
                    }
                    break;
                case 'select':
                    $options = $matchingField->getOptionsAsArray();
                    if ($options && !in_array($value, $options)) {
                        $errors[$fieldName] = 'Invalid option selected';
                    }
                    break;
                // Add more validations as needed
            }
        }
        
        return $errors;
    }
}