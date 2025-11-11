<?php

namespace App\Plugins\Workflows\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Workflows\Service\WorkflowService;
use App\Plugins\Workflows\Service\ActionRegistry;
use App\Plugins\Workflows\Service\WorkflowExecutionService;
use App\Plugins\Workflows\Service\WorkflowContextBuilder;
use App\Plugins\Workflows\Exception\WorkflowsException;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;

#[Route('/api/user/workflows')]

class WorkflowController extends AbstractController
{
    private ResponseService $responseService;
    private WorkflowService $workflowService;
    private OrganizationService $organizationService;
    private ActionRegistry $actionRegistry;
    private WorkflowExecutionService $executionService;
    private WorkflowContextBuilder $contextBuilder;
    private UserOrganizationService $userOrganizationService;


    public function __construct(
        ResponseService $responseService,
        WorkflowService $workflowService,
        OrganizationService $organizationService,
        ActionRegistry $actionRegistry,
        WorkflowExecutionService $executionService,
        WorkflowContextBuilder $contextBuilder,
        UserOrganizationService $userOrganizationService
    ) {
        $this->responseService = $responseService;
        $this->workflowService = $workflowService;
        $this->organizationService = $organizationService;
        $this->actionRegistry = $actionRegistry;
        $this->executionService = $executionService;
        $this->contextBuilder = $contextBuilder;
        $this->userOrganizationService = $userOrganizationService; 
    }

    #[Route('', name: 'workflows_list#', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $organizationId = $request->query->get('organization_id');
            
            // Check if organization_id is provided
            if (!$organizationId) {
                return $this->responseService->json(false, 'Organization ID is required.', null, 400);
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            $page = max(1, (int)$request->query->get('page', 1));
            $limit = min(100, max(10, (int)$request->query->get('limit', 50)));

            // Get workflows for this organization
            $filters = [];
            
            $workflows = $this->workflowService->getMany($filters, $page, $limit, [
                'organization' => $organization->entity,
                'deleted' => false
            ]);
            
            // Get total count
            $total = $this->workflowService->getMany($filters, 1, 1, [
                'organization' => $organization->entity,
                'deleted' => false
            ], null, true);
            $totalCount = is_array($total) && !empty($total) ? (int)$total[0] : 0;

            // Convert to array format
            $workflowsData = [];
            foreach ($workflows as $workflow) {
                $workflowsData[] = $workflow->toArray();
            }

            return $this->responseService->json(true, 'Workflows retrieved successfully', [
                'data' => $workflowsData,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_get#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $organizationId = $request->query->get('organization_id');
            
            // Check if organization_id is provided
            if (!$organizationId) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $workflow = $this->workflowService->getOne($id, [
                'organization' => $organization->entity,
                'deleted' => false
            ]);
            
            if (!$workflow) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            return $this->responseService->json(true, 'Workflow retrieved successfully', $workflow->toArray());
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

   #[Route('', name: 'workflow_create#', methods: ['POST'])]
public function create(Request $request): JsonResponse
{
    try {
        echo "=== WORKFLOW CREATE DEBUG ===\n";
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        echo "User ID: " . $user->getId() . "\n";
        echo "Data: " . json_encode($data) . "\n";
        
        // Validate organization_id is provided
        if (!isset($data['organization_id'])) {
            return $this->responseService->json(false, 'Organization ID is required', null, 400);
        }

        echo "Checking organization access...\n";
        // Check if user has access to this organization
        if (!$organization = $this->userOrganizationService->getOrganizationByUser($data['organization_id'], $user)) {
            echo "Organization not found or no access\n";
            exit;
        }
        
        echo "Organization found: " . $organization->entity->getId() . "\n";
        echo "Organization name: " . $organization->entity->getName() . "\n";

        // Remove organization_id from data since we're passing organization object separately
        unset($data['organization_id']);
        
        echo "Data after unset: " . json_encode($data) . "\n";
        echo "Calling workflow service create...\n";
        
        $workflow = $this->workflowService->create($data, $organization->entity, $user);
        
        echo "Workflow created successfully: " . $workflow->getId() . "\n";
        exit;

        return $this->responseService->json(true, 'Workflow created successfully', $workflow->toArray(), 201);
    } catch (WorkflowsException $e) {
        echo "WorkflowsException: " . $e->getMessage() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        exit;
    } catch (\Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        exit;
    }
}
    #[Route('/{id}', name: 'workflow_update#', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $organizationId = $request->query->get('organization_id');
            
            if (!$organizationId) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $workflow = $this->workflowService->getOne($id, [
                'organization' => $organization->entity,
                'deleted' => false
            ]);
            
            if (!$workflow) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            $this->workflowService->update($workflow, $data);

            return $this->responseService->json(true, 'Workflow updated successfully', $workflow->toArray());
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $organizationId = $request->query->get('organization_id');
            
            if (!$organizationId) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $workflow = $this->workflowService->getOne($id, [
                'organization' => $organization->entity,
                'deleted' => false
            ]);
            
            if (!$workflow) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            $this->workflowService->delete($workflow);

            return $this->responseService->json(true, 'Workflow deleted successfully');
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }


   
    #[Route('/available-triggers', name: 'workflow_triggers#', methods: ['GET'])]
    public function getAvailableTriggers(): JsonResponse
    {
        try {
            $triggers = [
                [
                    'id' => 'booking.created',
                    'name' => 'Booking Created',
                    'description' => 'Triggered when a new booking is created',
                    'category' => 'bookings',
                    'icon' => 'PhCalendarPlus',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.customer_phone' => 'Customer Phone',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'booking.start_time' => 'Start Time',
                        'booking.end_time' => 'End Time',
                        'booking.status' => 'Booking Status',
                        'event.id' => 'Event ID',
                        'event.name' => 'Event Name',
                        'event.slug' => 'Event Slug',
                        'event.description' => 'Event Description',
                        'event.location' => 'Event Location',
                        'organization.id' => 'Organization ID',
                        'organization.name' => 'Organization Name',
                        'organization.slug' => 'Organization Slug',
                        'host.name' => 'Host Name',
                        'host.email' => 'Host Email'
                    ],
                    'config_schema' => []
                ],
                [
                    'id' => 'booking.cancelled',
                    'name' => 'Booking Cancelled',
                    'description' => 'Triggered when a booking is cancelled',
                    'category' => 'bookings',
                    'icon' => 'PhCalendarX',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.customer_phone' => 'Customer Phone',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'event.name' => 'Event Name',
                        'organization.name' => 'Organization Name'
                    ],
                    'config_schema' => []
                ]
            ];

            return $this->responseService->json(true, 'Triggers retrieved successfully', $triggers);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/available-actions', name: 'workflow_actions#', methods: ['GET'])]
    public function getAvailableActions(): JsonResponse
    {
        try {
            // Use ActionRegistry to get all actions dynamically
            $actions = $this->actionRegistry->toArray();

            return $this->responseService->json(true, 'Actions retrieved successfully', $actions);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/{id}/test', name: 'workflow_test#', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');

            $workflow = $this->workflowService->getOne($id, ['deleted' => false]);
            if (!$workflow) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions for organization

            // Build fake context for testing
            $fakeContext = $this->contextBuilder->buildFakeContext();

            // Execute workflow with fake data
            $result = $this->executionService->executeWorkflow($workflow, $fakeContext);

            return $this->responseService->json(true, 'Workflow test executed', $result);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
}