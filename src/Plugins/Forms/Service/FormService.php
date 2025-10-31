<?php

namespace App\Plugins\Forms\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Forms\Entity\FormEntity;
use App\Plugins\Forms\Entity\EventFormEntity;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Service\SlugService;

class FormService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private SlugService $slugService;
    private UserOrganizationService $userOrganizationService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        SlugService $slugService,
        UserOrganizationService $userOrganizationService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->slugService = $slugService;
        $this->userOrganizationService = $userOrganizationService;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                FormEntity::class,
                $filters,
                $page,
                $limit,
                $criteria + ['deleted' => false]
            );
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getManyForUser(UserEntity $user, array $filters, int $page, int $limit): array
    {
        try {
            // Get all forms and filter in PHP
            // This is not ideal but avoids custom queries
            $allUserForms = [];
            
            // Get forms created by user
            $userCreatedForms = $this->crudManager->findMany(
                FormEntity::class,
                $filters,
                1,
                1000, // Get all for now
                ['deleted' => false, 'createdBy' => $user]
            );
            
            foreach ($userCreatedForms as $form) {
                $allUserForms[$form->getId()] = $form;
            }
            
            // Get user's organizations using UserOrganizationService
            $userOrganizations = $this->userOrganizationService->getOrganizationsByUser($user);
            
            foreach ($userOrganizations as $userOrg) {
                if ($userOrg->entity) {
                    // Get forms for this organization
                    $orgForms = $this->crudManager->findMany(
                        FormEntity::class,
                        $filters,
                        1,
                        1000,
                        ['deleted' => false, 'organization' => $userOrg->entity]
                    );
                    
                    foreach ($orgForms as $form) {
                        $allUserForms[$form->getId()] = $form;
                    }
                }
            }
            
            // Convert to array and sort by ID desc
            $forms = array_values($allUserForms);
            usort($forms, fn($a, $b) => $b->getId() - $a->getId());
            
            // Apply pagination
            $offset = ($page - 1) * $limit;
            return array_slice($forms, $offset, $limit);
            
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?FormEntity
    {
        return $this->crudManager->findOne(FormEntity::class, $id, $criteria + ['deleted' => false]);
    }

    public function getBySlug(string $slug): ?FormEntity
    {
        try {
            $forms = $this->crudManager->findMany(
                FormEntity::class,
                [],
                1,
                1,
                ['slug' => $slug, 'deleted' => false]
            );
            
            return !empty($forms) ? $forms[0] : null;
        } catch (CrudException $e) {
            return null;
        }
    }

    public function create(array $data, UserEntity $user): FormEntity
    {
        try {
            $form = new FormEntity();
            $form->setCreatedBy($user);

            // Set organization if provided
            if (!empty($data['organization_id'])) {
                $organization = $this->crudManager->findOne(
                    'App\Plugins\Organizations\Entity\OrganizationEntity',
                    $data['organization_id']
                );
                if (!$organization) {
                    throw new FormsException('Organization not found.');
                }
                $form->setOrganization($organization);
            }

            // Always generate slug from name
            if (isset($data['name'])) {
                $data['slug'] = $this->generateUniqueSlug($data['name']);
            } else {
                throw new FormsException('Form name is required.');
            }

            // Initialize with default fields if no fields provided
            if (!isset($data['fields']) || empty($data['fields'])) {
                $data['fields'] = $form->getDefaultFields();
            } else {
                // Ensure system fields are included
                $form->setFieldsJson($data['fields']);
                $data['fields'] = $form->getFieldsJson();
            }

            $constraints = [
                'name' => [
                    new Assert\NotBlank(['message' => 'Form name is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 1, 'max' => 255]),
                ],
                'slug' => [
                    new Assert\NotBlank(['message' => 'Form slug is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['max' => 255]),
                ],
                'description' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(['max' => 65535]),
                ]),
                'fields' => [
                    new Assert\Type('array'),
                ],
                'settings' => new Assert\Optional([
                    new Assert\Type('array'),
                ]),
                'is_active' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'allow_multiple_submissions' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'requires_authentication' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'organization_id' => new Assert\Optional([
                    new Assert\NotBlank(),
                    new Assert\Type('integer'),
                    new Assert\Positive(['message' => 'Organization ID must be a positive number.']),
                ]),
            ];

            $transform = [
                'fields' => function ($value) use (&$form) {
                    $fieldsArray = is_array($value) ? $value : [];
                    $form->setFieldsJson($fieldsArray);
                    return $fieldsArray;
                },
                'settings' => function ($value) use (&$form) {
                    $settingsArray = is_array($value) ? $value : [];
                    $form->setSettingsJson($settingsArray);
                    return $settingsArray;
                },
            ];

            $this->crudManager->create($form, $data, $constraints, $transform);

            return $form;
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function update(FormEntity $form, array $data): void
    {
        try {
            // Auto-generate slug from name if name is being updated
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = $this->generateUniqueSlugForUpdate($form, $data['name']);
            }
            
            // Handle organization_id separately
            if (isset($data['organization_id'])) {
                $organization = $this->crudManager->findOne(
                    'App\Plugins\Organizations\Entity\OrganizationEntity',
                    $data['organization_id']
                );
                if (!$organization) {
                    throw new FormsException('Organization not found.');
                }
                $form->setOrganization($organization);
                // Remove from data to avoid validation issues
                unset($data['organization_id']);
            }
            
            // Manually set the fields and persist directly
            if (isset($data['fields'])) {
                $form->setFieldsJson($data['fields']);
                $this->entityManager->persist($form);
                $this->entityManager->flush();
            }
            
            // Also handle settings manually if present
            if (isset($data['settings'])) {
                $form->setSettingsJson($data['settings']);
                $this->entityManager->persist($form);
                $this->entityManager->flush();
            }
            
            // Remove fields and settings from data since we handled them manually
            unset($data['fields']);
            unset($data['settings']);
            
            // Only update other properties through CrudManager if there are any left
            if (!empty($data)) {
                $constraints = [
                    'name' => [
                        new Assert\Length(['max' => 255])
                    ],
                    'slug' => [
                        new Assert\Length(['max' => 255])
                    ],
                    'description' => [
                        new Assert\Length(['max' => 65535])
                    ],
                    'is_active' => [
                        new Assert\Type('bool')
                    ],
                    'allow_multiple_submissions' => [
                        new Assert\Type('bool')
                    ],
                    'requires_authentication' => [
                        new Assert\Type('bool')
                    ]
                ];

                $this->crudManager->update($form, $data, $constraints);
            }
            
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function delete(FormEntity $form, bool $hard = false): void
    {
        try {
            $this->crudManager->delete($form, $hard);
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    /**
     * Generate a unique slug by adding numbers if duplicates exist
     */
    private function generateUniqueSlug(string $name): string
    {
        $baseSlug = $this->slugService->generateSlug($name);
        $slug = $baseSlug;
        $counter = 1;
        
        // Keep trying until we find a unique slug
        while ($this->getBySlug($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }

    /**
     * Generate a unique slug for updates, reusing existing generateUniqueSlug logic
     */
    private function generateUniqueSlugForUpdate(FormEntity $currentForm, string $name): string
    {
        $baseSlug = $this->slugService->generateSlug($name);
        
        // If the current form already has this slug, keep it
        if ($currentForm->getSlug() === $baseSlug) {
            return $baseSlug;
        }
        
        // Otherwise, use the standard unique slug generation
        return $this->generateUniqueSlug($name);
    }

    public function attachToEvent(FormEntity $form, EventEntity $event): EventFormEntity
    {
        try {
            // Check if already attached
            $existingAttachments = $this->crudManager->findMany(
                EventFormEntity::class,
                [],
                1,
                1,
                ['event' => $event]
            );

            if (!empty($existingAttachments)) {
                $existing = $existingAttachments[0];
                // Update existing attachment to use the new form
                $existing->setForm($form);
                $existing->setIsActive(true);
                $this->entityManager->flush();
                return $existing;
            }

            // Create new attachment
            $eventForm = new EventFormEntity();
            $eventForm->setForm($form);
            $eventForm->setEvent($event);

            $this->entityManager->persist($eventForm);
            $this->entityManager->flush();

            return $eventForm;
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function detachFromEvent(EventEntity $event): void
    {
        try {
            $eventForms = $this->crudManager->findMany(
                EventFormEntity::class,
                [],
                1,
                1,
                ['event' => $event, 'isActive' => true]
            );

            if (!empty($eventForms)) {
                $eventForm = $eventForms[0];
                $eventForm->setIsActive(false);
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getFormForEvent(EventEntity $event): ?FormEntity
    {
        try {
            $eventForms = $this->crudManager->findMany(
                EventFormEntity::class,
                [],
                1,
                1,
                ['event' => $event, 'isActive' => true]
            );
            
            return !empty($eventForms) ? $eventForms[0]->getForm() : null;
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }
}