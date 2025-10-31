<?php

namespace App\Plugins\Workflows\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Workflows\Entity\WorkflowEntity;
use App\Plugins\Workflows\Entity\WorkflowExecutionEntity;
use App\Plugins\Workflows\Exception\WorkflowsException;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;

class WorkflowService
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

    public function getMany(array $filters, int $page, int $limit, array $criteria = [], ?callable $callback = null, bool $count = false): array
    {
        try {
            return $this->crudManager->findMany(WorkflowEntity::class, $filters, $page, $limit, $criteria, $callback, $count);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?WorkflowEntity
    {
        return $this->crudManager->findOne(WorkflowEntity::class, $id, $criteria);
    }

    public function create(array $data, OrganizationEntity $organization, UserEntity $user): WorkflowEntity
    {
        try {
            $workflow = new WorkflowEntity();
            $workflow->setOrganization($organization);
            $workflow->setCreatedBy($user);

            // Set default empty flow data if not provided
            if (!isset($data['flow_data'])) {
                $data['flow_data'] = [];
            }

            $this->crudManager->create(
                $workflow,
                $data,
                [
                    'name' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 2, 'max' => 255]),
                    ],
                    'description' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'trigger_type' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                    ],
                    'trigger_config' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'flow_data' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'status' => new Assert\Optional([
                        new Assert\Choice(['choices' => ['active', 'inactive', 'draft']]),
                    ]),
                ]
            );

            return $workflow;
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function update(WorkflowEntity $workflow, array $data): void
    {
        try {
            $this->crudManager->update(
                $workflow,
                $data,
                [
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 2, 'max' => 255]),
                    ]),
                    'description' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'trigger_type' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'trigger_config' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'flow_data' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'status' => new Assert\Optional([
                        new Assert\Choice(['choices' => ['active', 'inactive', 'draft']]),
                    ]),
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function delete(WorkflowEntity $workflow): void
    {
        try {
            $this->crudManager->delete($workflow);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function getWorkflowsByOrganization(OrganizationEntity $organization): array
    {
        return $this->crudManager->findMany(
            WorkflowEntity::class,
            [],
            1,
            1000,
            [
                'organization' => $organization,
                'deleted' => false
            ]
        );
    }

    public function getActiveWorkflowsForTrigger(string $triggerType, OrganizationEntity $organization): array
    {
        return $this->crudManager->findMany(
            WorkflowEntity::class,
            [],
            1,
            1000,
            [
                'organization' => $organization,
                'triggerType' => $triggerType,
                'status' => 'active',
                'deleted' => false
            ]
        );
    }

    public function updateFlowData(WorkflowEntity $workflow, array $flowData): void
    {
        try {
            // Validate flow data structure
            $this->validateFlowData($flowData);
            
            $this->crudManager->update(
                $workflow,
                ['flowData' => $flowData],
                [
                    'flowData' => [
                        new Assert\Type('array'),
                    ]
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function validateFlowData(array $flowData): bool
    {
        // Basic validation - can be expanded later
        if (empty($flowData)) {
            return true; // Empty flow is allowed
        }

        // Check if steps array exists and is array
        if (isset($flowData['steps']) && !is_array($flowData['steps'])) {
            throw new WorkflowsException('Flow data steps must be an array');
        }

        // Validate each step has required fields
        if (isset($flowData['steps'])) {
            foreach ($flowData['steps'] as $index => $step) {
                if (!is_array($step)) {
                    throw new WorkflowsException("Step {$index} must be an array");
                }
                
                if (!isset($step['type']) || empty($step['type'])) {
                    throw new WorkflowsException("Step {$index} must have a type");
                }
            }
        }

        return true;
    }

    public function duplicateWorkflow(WorkflowEntity $originalWorkflow, OrganizationEntity $organization, UserEntity $user): WorkflowEntity
    {
        try {
            $data = [
                'name' => $originalWorkflow->getName() . ' (Copy)',
                'description' => $originalWorkflow->getDescription(),
                'trigger_type' => $originalWorkflow->getTriggerType(),
                'trigger_config' => $originalWorkflow->getTriggerConfig(),
                'flow_data' => $originalWorkflow->getFlowData(),
                'status' => 'draft' // Always create copy as draft
            ];

            return $this->create($data, $organization, $user);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    // Execution methods
    public function createExecution(WorkflowEntity $workflow, array $triggerData): WorkflowExecutionEntity
    {
        try {
            $execution = new WorkflowExecutionEntity();
            $execution->setWorkflow($workflow);
            
            $this->crudManager->create(
                $execution,
                [
                    'triggerData' => $triggerData,
                    'status' => 'running'
                ],
                [
                    'triggerData' => [new Assert\Type('array')],
                    'status' => [new Assert\Choice(['choices' => ['running', 'completed', 'failed']])]
                ]
            );

            return $execution;
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function updateExecution(WorkflowExecutionEntity $execution, array $data): void
    {
        try {
            $this->crudManager->update(
                $execution,
                $data,
                [
                    'executionData' => new Assert\Optional([new Assert\Type('array')]),
                    'status' => new Assert\Optional([new Assert\Choice(['choices' => ['running', 'completed', 'failed']])]),
                    'error' => new Assert\Optional([new Assert\Type('string')])
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }
}