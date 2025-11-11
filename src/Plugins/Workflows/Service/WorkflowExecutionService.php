<?php

namespace App\Plugins\Workflows\Service;

use App\Plugins\Workflows\Entity\WorkflowEntity;
use App\Plugins\Workflows\Entity\WorkflowExecutionEntity;
use App\Plugins\Workflows\Service\ActionRegistry;
use App\Plugins\Workflows\Service\WorkflowContextBuilder;
use App\Plugins\Events\Entity\EventBookingEntity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WorkflowExecutionService
{
    private EntityManagerInterface $entityManager;
    private ActionRegistry $actionRegistry;
    private WorkflowContextBuilder $contextBuilder;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        ActionRegistry $actionRegistry,
        WorkflowContextBuilder $contextBuilder,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->actionRegistry = $actionRegistry;
        $this->contextBuilder = $contextBuilder;
        $this->logger = $logger;
    }

    /**
     * Execute workflows for a trigger event
     * 
     * @param string $triggerType The trigger (e.g., 'booking.created')
     * @param EventBookingEntity $booking The booking that triggered this
     * @return array Results of all workflow executions
     */
    public function executeForTrigger(string $triggerType, EventBookingEntity $booking): array
    {
        $results = [];

        try {
            // Find all active workflows for this trigger
            $workflows = $this->findActiveWorkflows($triggerType, $booking->getEvent()->getOrganization());

            if (empty($workflows)) {
                return $results;
            }

            // Build context from booking
            $context = $this->contextBuilder->buildFromBooking($booking);

            // Execute each workflow
            foreach ($workflows as $workflow) {
                $result = $this->executeWorkflow($workflow, $context);
                $results[] = $result;
            }

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Workflow trigger execution failed', [
                'trigger' => $triggerType,
                'booking_id' => $booking->getId(),
                'error' => $e->getMessage()
            ]);

            return $results;
        }
    }

    /**
     * Execute a single workflow
     * 
     * @param WorkflowEntity $workflow The workflow to execute
     * @param array $context The context data
     * @return array Execution result
     */
    public function executeWorkflow(WorkflowEntity $workflow, array $context): array
    {
        // Create execution record
        $execution = new WorkflowExecutionEntity();
        $execution->setWorkflow($workflow);
        $execution->setContext($context);
        $execution->setStatus('running');

        $this->entityManager->persist($execution);
        $this->entityManager->flush();

        $executionResults = [];
        $hasError = false;

        try {
            // Get workflow steps from flow_data
            $flowData = $workflow->getFlowData();
            $steps = $flowData['steps'] ?? [];

            if (empty($steps)) {
                throw new \Exception('Workflow has no steps');
            }

            // Execute each step
            foreach ($steps as $index => $step) {
                $stepResult = $this->executeStep($step, $context, $index);
                $executionResults[] = $stepResult;

                // If step failed, stop execution
                if (!$stepResult['success']) {
                    $hasError = true;
                    break;
                }
            }

            // Update execution status
            if ($hasError) {
                $execution->setStatus('failed');
                $execution->setError('One or more steps failed');
            } else {
                $execution->setStatus('completed');
            }

            $execution->setCompletedAt(new \DateTime());
            $this->entityManager->persist($execution);
            $this->entityManager->flush();

            return [
                'success' => !$hasError,
                'workflow_id' => $workflow->getId(),
                'execution_id' => $execution->getId(),
                'steps' => $executionResults
            ];

        } catch (\Exception $e) {
            $execution->setStatus('failed');
            $execution->setError($e->getMessage());
            $execution->setCompletedAt(new \DateTime());

            $this->entityManager->persist($execution);
            $this->entityManager->flush();

            $this->logger->error('Workflow execution failed', [
                'workflow_id' => $workflow->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'workflow_id' => $workflow->getId(),
                'execution_id' => $execution->getId(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute a single step with retry logic
     * 
     * @param array $step The step configuration
     * @param array $context The context data
     * @param int $stepIndex The step index
     * @return array Step execution result
     */
    private function executeStep(array $step, array $context, int $stepIndex): array
    {
        $actionId = $step['action'] ?? null;
        $config = $step['config'] ?? [];

        if (!$actionId) {
            return [
                'success' => false,
                'step_index' => $stepIndex,
                'error' => 'Step has no action defined'
            ];
        }

        // Get the action from registry
        $action = $this->actionRegistry->getAction($actionId);

        if (!$action) {
            return [
                'success' => false,
                'step_index' => $stepIndex,
                'action' => $actionId,
                'error' => 'Action not found: ' . $actionId
            ];
        }

        // Validate configuration
        $validationErrors = $action->validate($config);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'step_index' => $stepIndex,
                'action' => $actionId,
                'error' => 'Configuration validation failed: ' . implode(', ', $validationErrors)
            ];
        }

        // Execute with retry logic (3 attempts)
        $maxRetries = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $result = $action->execute($config, $context);

                // Log success
                $this->logger->info('Workflow step executed', [
                    'step_index' => $stepIndex,
                    'action' => $actionId,
                    'attempt' => $attempt
                ]);

                return [
                    'success' => true,
                    'step_index' => $stepIndex,
                    'action' => $actionId,
                    'action_name' => $action->getName(),
                    'attempt' => $attempt,
                    'result' => $result
                ];

            } catch (\Exception $e) {
                $lastError = $e->getMessage();

                $this->logger->warning('Workflow step failed', [
                    'step_index' => $stepIndex,
                    'action' => $actionId,
                    'attempt' => $attempt,
                    'error' => $lastError
                ]);

                // Wait 1 second before retry (except on last attempt)
                if ($attempt < $maxRetries) {
                    sleep(1);
                }
            }
        }

        // All retries failed
        return [
            'success' => false,
            'step_index' => $stepIndex,
            'action' => $actionId,
            'action_name' => $action->getName(),
            'attempts' => $maxRetries,
            'error' => $lastError
        ];
    }

    /**
     * Find active workflows for a trigger
     * 
     * @param string $triggerType The trigger type
     * @param object $organization The organization
     * @return WorkflowEntity[]
     */
    private function findActiveWorkflows(string $triggerType, $organization): array
    {
        return $this->entityManager->getRepository(WorkflowEntity::class)
            ->findBy([
                'triggerType' => $triggerType,
                'status' => 'active',
                'deleted' => false,
                'organization' => $organization
            ]);
    }
}