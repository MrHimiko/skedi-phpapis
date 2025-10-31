<?php

namespace App\Plugins\Forms\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Forms\Service\FormService;
use App\Plugins\Forms\Service\FormSubmissionService;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Forms\Entity\FormEntity;

#[Route('/api')]
class FormSubmissionController extends AbstractController
{
    private ResponseService $responseService;
    private FormService $formService;
    private FormSubmissionService $submissionService;
    private UserOrganizationService $userOrganizationService;
    private OrganizationService $organizationService;
    private EventService $eventService;


    public function __construct(
        ResponseService $responseService,
        FormService $formService,
        FormSubmissionService $submissionService,
        UserOrganizationService $userOrganizationService,
        OrganizationService $organizationService,
        EventService $eventService
    ) {
        $this->responseService = $responseService;
        $this->formService = $formService;
        $this->submissionService = $submissionService;
        $this->userOrganizationService = $userOrganizationService;
        $this->organizationService = $organizationService;
        $this->eventService = $eventService;
    }

    #[Route('/organizations/{organization_id}/forms/{form_id}/submissions', name: 'form_submissions_get#', methods: ['GET'], requirements: ['organization_id' => '\d+', 'form_id' => '\d+'])]
    public function getFormSubmissions(int $organization_id, int $form_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($form_id, ['organization' => $organization->entity]);
            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            $submissions = $this->submissionService->getSubmissionsForForm($form, $filters, $page, $limit);

            $result = [];
            foreach ($submissions as $submission) {
                $result[] = $submission->toArray();
            }

            return $this->responseService->json(true, 'Form submissions retrieved successfully.', $result);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/forms/{form_id}/submissions/{id}', name: 'form_submissions_get_one#', methods: ['GET'], requirements: ['organization_id' => '\d+', 'form_id' => '\d+', 'id' => '\d+'])]
    public function getFormSubmission(int $organization_id, int $form_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($form_id, ['organization' => $organization->entity]);
            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            $submission = $this->submissionService->getOne($id, ['form' => $form]);
            if (!$submission) {
                return $this->responseService->json(false, 'Submission was not found.');
            }

            return $this->responseService->json(true, 'Submission retrieved successfully.', $submission->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/forms/{form_id}/submissions/{id}', name: 'form_submissions_delete#', methods: ['DELETE'], requirements: ['organization_id' => '\d+', 'form_id' => '\d+', 'id' => '\d+'])]
    public function deleteFormSubmission(int $organization_id, int $form_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($form_id, ['organization' => $organization->entity]);
            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            $submission = $this->submissionService->getOne($id, ['form' => $form]);
            if (!$submission) {
                return $this->responseService->json(false, 'Submission was not found.');
            }

            $this->submissionService->delete($submission);

            return $this->responseService->json(true, 'Submission deleted successfully.');
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Public submission endpoint
    #[Route('/public/organizations/{org_slug}/forms/{form_slug}/submit', name: 'public_form_submit', methods: ['POST'])]
    public function submitForm(string $org_slug, string $form_slug, Request $request): JsonResponse
    {
        $data = $request->attributes->get('data');

        try {
            $organization = $this->organizationService->getBySlug($org_slug);
            if (!$organization) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }

            $form = $this->formService->getBySlug($form_slug, $organization);
            if (!$form || $form->isDeleted() || !$form->isActive()) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }

            // Add metadata
            $data['form_id'] = $form->getId();
            $data['ip_address'] = $request->getClientIp();
            $data['user_agent'] = $request->headers->get('User-Agent');
            $data['submission_source'] = 'web';

            $submission = $this->submissionService->create($data);

            return $this->responseService->json(true, 'Form submitted successfully.', [
                'submission_id' => $submission->getId()
            ], 201);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Public form info endpoint
    #[Route('/public/organizations/{org_slug}/forms/{form_slug}', name: 'public_form_info', methods: ['GET'])]
    public function getPublicFormInfo(string $org_slug, string $form_slug, Request $request): JsonResponse
    {
        try {
            $organization = $this->organizationService->getBySlug($org_slug);
            if (!$organization) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }

            $form = $this->formService->getBySlug($form_slug, $organization);
            if (!$form || $form->isDeleted() || !$form->isActive()) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }

            $formData = $form->toArray();
            
            // Remove sensitive data
            unset($formData['created_by']);
            
            return $this->responseService->json(true, 'retrieve', $formData);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }



    #[Route('/public/events/{event_id}/form', name: 'public_event_form_by_id', methods: ['GET'], requirements: ['event_id' => '\d+'])]
    public function getPublicEventFormById(int $event_id, Request $request): JsonResponse
    {
        try {
            // Get event by ID
            $event = $this->eventService->getOne($event_id);
            
            if (!$event || $event->isDeleted()) {
                return $this->responseService->json(false, 'Event not found', null, 404);
            }
            
            // Try to get custom form attached to event
            $form = $this->formService->getFormForEvent($event);
            
            if ($form && !$form->isDeleted() && $form->isActive()) {
                // Return the custom form
                $formData = $form->toArray();
                
                // Remove sensitive data
                unset($formData['created_by']);
                
                return $this->responseService->json(true, 'retrieve', $formData);
            }
            
            // No custom form attached, return default form structure
            $defaultForm = $this->getDefaultFormStructure();
            
            return $this->responseService->json(true, 'retrieve', $defaultForm);
            
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    /**
     * Get the default form structure when no custom form is attached
     */
    private function getDefaultFormStructure(): array
    {
        return [
            'id' => 'default-form',
            'name' => 'Default Booking Form',
            'slug' => 'default-form',
            'description' => 'Default form for event bookings',
            'fields' => [
                [
                    'id' => FormEntity::SYSTEM_FIELD_NAME,
                    'type' => 'text',
                    'name' => FormEntity::SYSTEM_FIELD_NAME,
                    'label' => 'Your Name',
                    'placeholder' => 'Enter your name',
                    'required' => true,
                    'deletable' => false,
                    'system_field' => true,
                    'order' => 1,
                    'colSpan' => 12
                ],
                [
                    'id' => FormEntity::SYSTEM_FIELD_EMAIL,
                    'type' => 'email',
                    'name' => FormEntity::SYSTEM_FIELD_EMAIL,
                    'label' => 'Email Address',
                    'placeholder' => 'your@email.com',
                    'required' => true,
                    'deletable' => false,
                    'system_field' => true,
                    'order' => 2,
                    'colSpan' => 12
                ],
                [
                    'id' => 'notes',
                    'type' => 'textarea',
                    'name' => 'notes',
                    'label' => 'Notes (Optional)',
                    'placeholder' => 'Any additional information...',
                    'required' => false,
                    'deletable' => true,
                    'system_field' => false,
                    'order' => 3,
                    'colSpan' => 12,
                    'rows' => 3
                ],
                [
                    'id' => FormEntity::GUEST_REPEATER_FIELD,
                    'type' => 'guest_repeater',
                    'name' => FormEntity::GUEST_REPEATER_FIELD,
                    'label' => 'Add Guests',
                    'placeholder' => '',
                    'required' => false,
                    'deletable' => true,
                    'system_field' => false,
                    'max_guests' => 10,
                    'order' => 4,
                    'unique' => true,
                    'colSpan' => 12
                ]
            ],
            'settings' => [
                'layout' => [
                    'settings' => [
                        'submit_button_text' => 'Submit',
                        'show_progress_bar' => false,
                        'show_field_labels' => true,
                        'show_required_indicator' => true
                    ]
                ],
                'confirmations' => [
                    'message' => 'Thank you for your submission!',
                    'redirectUrl' => null
                ]
            ],
            'is_active' => true,
            'allow_multiple_submissions' => true,
            'requires_authentication' => false,
            'is_default' => true // Flag to indicate this is the default form
        ];
    }


    #[Route('/public/events/{event_id}/form/submit', name: 'public_event_form_submit', methods: ['POST'], requirements: ['event_id' => '\d+'])]
    public function submitEventForm(int $event_id, Request $request): JsonResponse
    {
        $data = $request->attributes->get('data');

        try {
            // Get event by ID
            $event = $this->eventService->getOne($event_id);
            
            if (!$event || $event->isDeleted()) {
                return $this->responseService->json(false, 'Event not found', null, 404);
            }
            
            // Try to get custom form attached to event
            $form = $this->formService->getFormForEvent($event);
            
            // If no custom form, we'll process the default form submission
            if (!$form) {
                // Create a virtual form entity for the submission service
                // In a real implementation, you might want to have a special "default form" entry in the database
                // For now, we'll process the submission without a form entity
                
                // Add metadata
                $data['event_id'] = $event->getId();
                $data['ip_address'] = $request->getClientIp();
                $data['user_agent'] = $request->headers->get('User-Agent');
                $data['submission_source'] = 'web';
                $data['is_default_form'] = true;
                
                // Process the submission (you may need to update the submission service to handle this case)
                $submission = $this->submissionService->createForEvent($data, $event);
                
                return $this->responseService->json(true, 'Form submitted successfully.', [
                    'submission_id' => $submission->getId()
                ], 201);
            }
            
            // Custom form exists, process normally
            if ($form->isDeleted() || !$form->isActive()) {
                return $this->responseService->json(false, 'Form is not available', null, 404);
            }
            
            // Add metadata
            $data['form_id'] = $form->getId();
            $data['event_id'] = $event->getId();
            $data['ip_address'] = $request->getClientIp();
            $data['user_agent'] = $request->headers->get('User-Agent');
            $data['submission_source'] = 'web';

            $submission = $this->submissionService->create($data);

            return $this->responseService->json(true, 'Form submitted successfully.', [
                'submission_id' => $submission->getId()
            ], 201);
            
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

}