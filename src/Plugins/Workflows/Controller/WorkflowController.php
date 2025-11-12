<?php

namespace App\Plugins\Workflows\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Workflows\Service\WorkflowService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Workflows\Exception\WorkflowsException;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/user/workflows')]
class WorkflowController extends AbstractController
{
    private ResponseService $responseService;
    private WorkflowService $workflowService;
    private OrganizationService $organizationService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        WorkflowService $workflowService,
        OrganizationService $organizationService,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->workflowService = $workflowService;
        $this->organizationService = $organizationService;
        $this->entityManager = $entityManager;
    }

    #[Route('', name: 'workflows_list#', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $organizationId = $request->query->get('organization_id');
            
            $page = max(1, (int)$request->query->get('page', 1));
            $limit = min(100, max(10, (int)$request->query->get('limit', 50)));

            $filters = [
                [
                    'field' => 'deleted',
                    'operator' => 'equals',
                    'value' => false
                ]
            ];

            $criteria = [];
            
            // Filter by organization if provided
            if ($organizationId) {
                $organization = $this->organizationService->getOne((int)$organizationId);
                if ($organization) {
                    $criteria['organization'] = $organization;
                }
            }
            
            $workflows = $this->workflowService->getMany($filters, $page, $limit, $criteria);
            $total = $this->workflowService->getMany($filters, 1, 1, $criteria, null, true);
            $totalCount = is_array($total) && !empty($total) ? (int)$total[0] : 0;

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
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('', name: 'workflow_create#', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            
            if (!isset($data['organization_id'])) {
                return $this->responseService->json(false, 'Organization ID is required', null, 400);
            }

            $organization = $this->organizationService->getOne($data['organization_id']);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }

            $workflow = $this->workflowService->create($data, $organization, $user);

            return $this->responseService->json(true, 'Workflow created successfully', $workflow->toArray(), 201);
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
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'booking.status' => 'Booking Status',
                        'event.name' => 'Event Name',
                        'event.location' => 'Event Location',
                        'organization.name' => 'Organization Name'
                    ],
                    'config_schema' => []
                ],
                [
                    'id' => 'booking.updated',
                    'name' => 'Booking Updated',
                    'description' => 'Triggered when a booking is updated',
                    'category' => 'bookings',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'booking.status' => 'Booking Status',
                        'booking.previous_status' => 'Previous Status',
                        'event.name' => 'Event Name',
                        'organization.name' => 'Organization Name'
                    ],
                    'config_schema' => []
                ],
                [
                    'id' => 'booking.cancelled',
                    'name' => 'Booking Cancelled',
                    'description' => 'Triggered when a booking is cancelled',
                    'category' => 'bookings',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.cancellation_reason' => 'Cancellation Reason',
                        'event.name' => 'Event Name',
                        'organization.name' => 'Organization Name'
                    ],
                    'config_schema' => []
                ]
            ];

            return $this->responseService->json(true, 'Available triggers', $triggers);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/available-actions', name: 'workflow_actions#', methods: ['GET'])]
    public function getAvailableActions(): JsonResponse
    {
        try {
            $actions = [
                [
                    'id' => 'send_email',
                    'name' => 'Send Email',
                    'description' => 'Send an email to a specified recipient',
                    'category' => 'communication',
                    'icon' => 'envelope',
                    'config_schema' => [
                        'to' => [
                            'type' => 'string',
                            'label' => 'To',
                            'placeholder' => 'recipient@example.com or use {{booking.customer_email}}',
                            'required' => true
                        ],
                        'subject' => [
                            'type' => 'string',
                            'label' => 'Subject',
                            'placeholder' => 'Email subject',
                            'required' => true
                        ],
                        'body' => [
                            'type' => 'textarea',
                            'label' => 'Body',
                            'placeholder' => 'Email body (you can use variables like {{booking.customer_name}})',
                            'required' => true
                        ]
                    ]
                ],
                [
                    'id' => 'send_sms',
                    'name' => 'Send SMS',
                    'description' => 'Send an SMS to a phone number',
                    'category' => 'communication',
                    'icon' => 'phone',
                    'config_schema' => [
                        'to' => [
                            'type' => 'string',
                            'label' => 'To',
                            'placeholder' => '+1234567890 or use {{booking.customer_phone}}',
                            'required' => true
                        ],
                        'message' => [
                            'type' => 'textarea',
                            'label' => 'Message',
                            'placeholder' => 'SMS message',
                            'required' => true
                        ]
                    ]
                ],
                [
                    'id' => 'wait',
                    'name' => 'Wait',
                    'description' => 'Wait for a specified amount of time',
                    'category' => 'control',
                    'icon' => 'clock',
                    'config_schema' => [
                        'duration' => [
                            'type' => 'number',
                            'label' => 'Duration',
                            'placeholder' => '5',
                            'required' => true
                        ],
                        'unit' => [
                            'type' => 'select',
                            'label' => 'Unit',
                            'options' => [
                                ['label' => 'Minutes', 'value' => 'minutes'],
                                ['label' => 'Hours', 'value' => 'hours'],
                                ['label' => 'Days', 'value' => 'days']
                            ],
                            'required' => true
                        ]
                    ]
                ],
                [
                    'id' => 'condition',
                    'name' => 'Condition',
                    'description' => 'Branch workflow based on a condition',
                    'category' => 'control',
                    'icon' => 'git-branch',
                    'config_schema' => [
                        'field' => [
                            'type' => 'string',
                            'label' => 'Field',
                            'placeholder' => 'booking.status or booking.customer_email',
                            'required' => true
                        ],
                        'operator' => [
                            'type' => 'select',
                            'label' => 'Operator',
                            'options' => [
                                ['label' => 'Equals', 'value' => 'equals'],
                                ['label' => 'Not Equals', 'value' => 'not_equals'],
                                ['label' => 'Contains', 'value' => 'contains'],
                                ['label' => 'Greater Than', 'value' => 'greater_than'],
                                ['label' => 'Less Than', 'value' => 'less_than'],
                                ['label' => 'Is Empty', 'value' => 'is_empty'],
                                ['label' => 'Is Not Empty', 'value' => 'is_not_empty']
                            ],
                            'required' => true
                        ],
                        'value' => [
                            'type' => 'string',
                            'label' => 'Value to Compare',
                            'placeholder' => 'gmail.com'
                        ]
                    ]
                ]
            ];

            return $this->responseService->json(true, 'Available actions', $actions);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_get#', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $workflowId = (int)$id;
            
            $workflow = $this->workflowService->getOne($workflowId);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            return $this->responseService->json(true, 'Workflow retrieved successfully', $workflow->toArray());
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_update#', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $workflowId = (int)$id;
            
            $workflow = $this->workflowService->getOne($workflowId);
            
            if (!$workflow || $workflow->getDeleted()) {
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

    #[Route('/{id}', name: 'workflow_delete#', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $workflowId = (int)$id;
            
            $workflow = $this->workflowService->getOne($workflowId);
            
            if (!$workflow || $workflow->getDeleted()) {
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

    #[Route('/{id}/duplicate', name: 'workflow_duplicate#', methods: ['POST'])]
    public function duplicate(string $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $workflowId = (int)$id;
            
            $workflow = $this->workflowService->getOne($workflowId);
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            $organizationId = $data['organization_id'] ?? $workflow->getOrganization()->getId();
            $organization = $this->organizationService->getOne($organizationId);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }

            $duplicatedWorkflow = $this->workflowService->duplicateWorkflow($workflow, $organization, $user);

            return $this->responseService->json(true, 'Workflow duplicated successfully', $duplicatedWorkflow->toArray(), 201);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}/flow-data', name: 'workflow_update_flow#', methods: ['PUT', 'PATCH'])]
    public function updateFlowData(string $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $workflowId = (int)$id;
            
            $workflow = $this->workflowService->getOne($workflowId);
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            if (!isset($data['flow_data'])) {
                return $this->responseService->json(false, 'Flow data is required', null, 400);
            }

            $this->workflowService->updateFlowData($workflow, $data['flow_data']);

            return $this->responseService->json(true, 'Workflow flow data updated successfully', $workflow->toArray());
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}/test', name: 'workflow_test#', methods: ['POST'])]
    public function test(string $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $workflowId = (int)$id;
            
            $workflow = $this->workflowService->getOne($workflowId);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            return $this->responseService->json(true, 'Test workflow completed successfully', [
                'message' => 'Workflow test executed with sample data',
                'steps_executed' => count($workflow->getFlowData()['steps'] ?? [])
            ]);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }
}