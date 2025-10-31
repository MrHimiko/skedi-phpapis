<?php

namespace App\Plugins\Forms\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Forms\Entity\FormEntity;
use App\Plugins\Forms\Entity\FormSubmissionEntity;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Account\Entity\UserEntity;

class FormSubmissionService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
    }

    public function getSubmissionsForForm(FormEntity $form, array $filters, int $page, int $limit): array
    {
        try {
            return $this->crudManager->findMany(
                FormSubmissionEntity::class,
                $filters,
                $page,
                $limit,
                ['form' => $form]
            );
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?FormSubmissionEntity
    {
        return $this->crudManager->findOne(FormSubmissionEntity::class, $id, $criteria);
    }

    public function create(array $data): FormSubmissionEntity
    {
        try {
            $submission = new FormSubmissionEntity();

            // Set form if provided
            if (!empty($data['form_id'])) {
                $form = $this->entityManager->getRepository(FormEntity::class)->find($data['form_id']);
                if (!$form) {
                    throw new FormsException('Form not found');
                }
                $submission->setForm($form);
            }

            // Set event if provided
            if (!empty($data['event_id'])) {
                $event = $this->entityManager->getRepository(EventEntity::class)->find($data['event_id']);
                if ($event) {
                    $submission->setEvent($event);
                }
            }

            // Process form fields
            if (isset($data['fields'])) {
                $submission->setFieldsData($data['fields']);
            }

            // Set metadata
            if (isset($data['ip_address'])) {
                $submission->setIpAddress($data['ip_address']);
            }
            if (isset($data['user_agent'])) {
                $submission->setUserAgent($data['user_agent']);
            }
            if (isset($data['submission_source'])) {
                $submission->setSubmissionSource($data['submission_source']);
            }

            // Set submitter user if authenticated
            if (!empty($data['submitter_user_id'])) {
                $user = $this->entityManager->getRepository(UserEntity::class)->find($data['submitter_user_id']);
                if ($user) {
                    $submission->setSubmitterUser($user);
                }
            }

            $constraints = [
                'fields' => [
                    new Assert\NotBlank(['message' => 'Form fields are required.']),
                    new Assert\Type(['type' => 'array', 'message' => 'Fields must be an array.']),
                ],
            ];

            $this->crudManager->create($submission, $data, $constraints);
            return $submission;
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    /**
     * Create a submission for an event without a custom form
     */
    public function createForEvent(array $data, EventEntity $event): FormSubmissionEntity
    {
        try {
            $submission = new FormSubmissionEntity();
            $submission->setEvent($event);

            // Process form fields
            if (isset($data['fields'])) {
                $submission->setFieldsData($data['fields']);
            }

            // Set metadata
            if (isset($data['ip_address'])) {
                $submission->setIpAddress($data['ip_address']);
            }
            if (isset($data['user_agent'])) {
                $submission->setUserAgent($data['user_agent']);
            }
            if (isset($data['submission_source'])) {
                $submission->setSubmissionSource($data['submission_source']);
            }

            // Set submitter user if authenticated
            if (!empty($data['submitter_user_id'])) {
                $user = $this->entityManager->getRepository(UserEntity::class)->find($data['submitter_user_id']);
                if ($user) {
                    $submission->setSubmitterUser($user);
                }
            }

            // Validate required fields for default form
            $fields = $data['fields'] ?? [];
            if (empty($fields['system_contact_name'])) {
                throw new FormsException('Name is required');
            }
            if (empty($fields['system_contact_email'])) {
                throw new FormsException('Email is required');
            }
            
            // Validate email format
            if (!filter_var($fields['system_contact_email'], FILTER_VALIDATE_EMAIL)) {
                throw new FormsException('Invalid email format');
            }

            $this->entityManager->persist($submission);
            $this->entityManager->flush();

            return $submission;
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function delete(FormSubmissionEntity $submission, bool $hard = false): void
    {
        try {
            $this->crudManager->delete($submission, $hard);
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }
}