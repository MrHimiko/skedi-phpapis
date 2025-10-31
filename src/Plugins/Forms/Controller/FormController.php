<?php

namespace App\Plugins\Forms\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Forms\Service\FormService;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Forms\Entity\FormEntity;

#[Route('/api')]
class FormController extends AbstractController
{
    private ResponseService $responseService;
    private FormService $formService;
    private UserOrganizationService $userOrganizationService;
    private EventService $eventService;

    public function __construct(
        ResponseService $responseService,
        FormService $formService,
        UserOrganizationService $userOrganizationService,
        EventService $eventService
    ) {
        $this->responseService = $responseService;
        $this->formService = $formService;
        $this->userOrganizationService = $userOrganizationService;
        $this->eventService = $eventService;
    }

    // Global forms endpoint - with authentication
    #[Route('/forms', name: 'forms_get_many_global#', methods: ['GET'])]
    public function getFormsGlobal(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try {
            // Get forms that the user created or that belong to their organizations
            $forms = $this->formService->getManyForUser($user, $filters, $page, $limit);

            $result = [];
            foreach ($forms as $form) {
                $formArray = $form->toArray();
                
                // Add permission info - if you can access it, you can edit it
                $canAccess = $this->canUserAccessForm($form, $user);
                $formArray['permissions'] = [
                    'can_edit' => $canAccess,
                    'can_delete' => $canAccess,
                    'is_creator' => $form->getCreatedBy()->getId() === $user->getId()
                ];
                
                // Add events count
                $formArray['events_count'] = 0; // TODO: Implement actual count
                $result[] = $formArray;
            }

            return $this->responseService->json(true, 'Forms retrieved successfully.', $result);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Keep organization-based endpoint for backward compatibility
    #[Route('/organizations/{organization_id}/forms', name: 'forms_get_many#', methods: ['GET'], requirements: ['organization_id' => '\d+'])]
    public function getForms(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try {
            // Verify user has access to the organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Return forms for this user (same as global endpoint)
            $forms = $this->formService->getManyForUser($user, $filters, $page, $limit);

            $result = [];
            foreach ($forms as $form) {
                $result[] = $form->toArray();
            }

            return $this->responseService->json(true, 'Forms retrieved successfully.', $result);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms/{id}', name: 'forms_get_one_global#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getFormByIdGlobal(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $form = $this->formService->getOne($id);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            // Check if user can access this form
            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }

            return $this->responseService->json(true, 'Form retrieved successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Keep organization-based endpoint for backward compatibility
    #[Route('/organizations/{organization_id}/forms/{id}', name: 'forms_get_one#', methods: ['GET'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function getFormById(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($id);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            // Check if user can access this form
            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }

            return $this->responseService->json(true, 'Form retrieved successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms', name: 'forms_create_global#', methods: ['POST'])]
    public function createNewFormGlobal(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            $form = $this->formService->create($data, $user);

            return $this->responseService->json(true, 'Form created successfully.', $form->toArray(), 201);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Keep organization-based endpoint for backward compatibility
    #[Route('/organizations/{organization_id}/forms', name: 'forms_create#', methods: ['POST'], requirements: ['organization_id' => '\d+'])]
    public function createNewForm(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->create($data, $user);

            return $this->responseService->json(true, 'Form created successfully.', $form->toArray(), 201);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms/{id}', name: 'forms_update_global#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateFormGlobal(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $form = $this->formService->getOne($id);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }
            
            // Check if user can access this form
            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }
            
            $this->formService->update($form, $data);

            return $this->responseService->json(true, 'Form updated successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Keep organization-based endpoint for backward compatibility
    #[Route('/organizations/{organization_id}/forms/{id}', name: 'forms_update#', methods: ['PUT'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function updateForm(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($id);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            // Check if user can access this form
            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }

            $this->formService->update($form, $data);

            return $this->responseService->json(true, 'Form updated successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms/{id}', name: 'forms_delete_global#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteFormGlobal(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $form = $this->formService->getOne($id);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            // Check if user can access this form
            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }

            $this->formService->delete($form);

            return $this->responseService->json(true, 'Form deleted successfully.');
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    // Keep organization-based endpoint for backward compatibility
    #[Route('/organizations/{organization_id}/forms/{id}', name: 'forms_delete#', methods: ['DELETE'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function deleteForm(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($id);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            // Check if user can access this form
            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }

            $this->formService->delete($form);

            return $this->responseService->json(true, 'Form deleted successfully.');
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/events/{event_id}/forms', name: 'event_forms_attach#', methods: ['POST'], requirements: ['organization_id' => '\d+', 'event_id' => '\d+'])]
    public function attachFormToEvent(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            if (empty($data['form_id'])) {
                return $this->responseService->json(false, 'Form ID is required.');
            }

            $event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity);
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            // Get form and check access
            $form = $this->formService->getOne($data['form_id']);
            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            if (!$this->canUserAccessForm($form, $user)) {
                return $this->responseService->json(false, 'Access denied to form.', null, 403);
            }

            $eventForm = $this->formService->attachToEvent($form, $event);

            return $this->responseService->json(true, 'Form attached to event successfully.', $eventForm->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/events/{event_id}/forms', name: 'event_forms_detach#', methods: ['DELETE'], requirements: ['organization_id' => '\d+', 'event_id' => '\d+'])]
    public function detachFormFromEvent(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity);
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $this->formService->detachFromEvent($event);

            return $this->responseService->json(true, 'Form detached from event successfully.');
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/events/{event_id}/forms', name: 'event_forms_get#', methods: ['GET'], requirements: ['organization_id' => '\d+', 'event_id' => '\d+'])]
    public function getEventForm(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity);
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $form = $this->formService->getFormForEvent($event);

            if (!$form) {
                return $this->responseService->json(false, 'No form attached to this event.');
            }

            return $this->responseService->json(true, 'Event form retrieved successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    /**
     * Check if user can access a form (they created it or it belongs to their organization)
     */
   private function canUserAccessForm(FormEntity $form, $user): bool
    {
        // User can access if they created the form (regardless of organization)
        if ($form->getCreatedBy()->getId() === $user->getId()) {
            return true;
        }
        
        // User can access if they belong to the same organization as the form
        if ($form->getOrganization()) {
            $userOrganization = $this->userOrganizationService->getOrganizationByUser($form->getOrganization()->getId(), $user);
            return $userOrganization !== null; // Any member can view and edit
        }
        
        return false;
    }
}