<?php

namespace App\Plugins\Contacts\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;

use App\Service\ResponseService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Contacts\Service\ContactService;
use App\Plugins\Contacts\Exception\ContactsException;
use App\Plugins\Contacts\Entity\HostContactEntity;
use App\Plugins\Contacts\Entity\ContactEntity;    
use App\Plugins\Contacts\Entity\OrganizationContactEntity;
use App\Plugins\Contacts\Service\ContactExportService;
use App\Plugins\Contacts\Controller\Response;

#[Route('/api/user')]
class ContactController extends AbstractController
{
    private ResponseService $responseService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private ContactService $contactService;
    private EntityManagerInterface $entityManager;
    private ContactExportService $contactExportService;

    public function __construct(
        ResponseService $responseService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        ContactService $contactService,
        EntityManagerInterface $entityManager,
        ContactExportService $contactExportService
    ) {
        $this->responseService = $responseService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->contactService = $contactService;
        $this->entityManager = $entityManager;
        $this->contactExportService = $contactExportService;
    }

    #[Route('/contacts/my-contacts', name: 'my_contacts_list#', methods: ['GET'])]
    public function myContacts(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get query parameters
            $page = (int) $request->query->get('page', 1);
            $limit = (int) $request->query->get('limit', 50);
            $filters = [
                'search' => $request->query->get('search', '')
            ];

            // Get host contacts for current user across all organizations
            $result = $this->contactService->getHostContacts(
                $user,
                null, // No organization filter for "My Contacts"
                $filters,
                $page,
                $limit
            );

            return $this->responseService->json(true, 'My contacts retrieved successfully.', $result);
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/contacts/{id}/host-favorite', name: 'toggle_host_favorite#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function toggleHostFavorite(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get request body for organization ID
            $data = json_decode($request->getContent(), true);
            $organizationId = $data['organization_id'] ?? null;
            
            if (!$organizationId) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }

            // Get organization
            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.');
            }

            // Get contact
            $contact = $this->entityManager->find('App\Plugins\Contacts\Entity\ContactEntity', $id);
            if (!$contact) {
                return $this->responseService->json(false, 'Contact not found.');
            }

            // Toggle favorite
            $isFavorite = $this->contactService->toggleHostContactFavorite($user, $contact, $organization);

            return $this->responseService->json(true, 'Favorite status updated.', [
                'is_favorite' => $isFavorite
            ]);
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/contacts', name: 'contacts_list#', methods: ['GET'], requirements: ['organization_id' => '\d+'])]
    public function list(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get query parameters
            $page = (int) $request->query->get('page', 1);
            $limit = (int) $request->query->get('limit', 50);
            $filters = [
                'search' => $request->query->get('search', '')
            ];
            
            // Get contacts with meeting information
            $result = $this->contactService->getContactsWithMeetingInfo(
                $organization,
                $filters,
                $page,
                $limit
            );

            return $this->responseService->json(true, 'Contacts retrieved successfully.', $result);
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/contacts/{id}', name: 'contacts_get#', methods: ['GET'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function get(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            return $this->responseService->json(true, 'Contact retrieved successfully.', $orgContact->toArray());
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/contacts/{id}', name: 'contacts_update#', methods: ['PUT'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function update(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            // Update contact
            $data = json_decode($request->getContent(), true);
            $this->contactService->update($orgContact->getContact(), $data);

            return $this->responseService->json(true, 'Contact updated successfully.', $orgContact->toArray());
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/contacts/{id}', name: 'contacts_delete#', methods: ['DELETE'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function delete(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            // Soft delete the organization contact relationship
            $this->contactService->delete($orgContact);

            return $this->responseService->json(true, 'Contact deleted successfully.');
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/contacts/{id}/favorite', name: 'contacts_toggle_favorite#', methods: ['PUT'], requirements: ['organization_id' => '\d+', 'id' => '\d+'])]
    public function toggleFavorite(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            // Toggle favorite
            $isFavorite = $this->contactService->toggleFavorite($orgContact);

            return $this->responseService->json(true, 'Favorite status updated.', [
                'is_favorite' => $isFavorite
            ]);
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

   #[Route('/contacts/{contact_id}/toggle-favorite', name: 'my_contact_toggle_favorite#', methods: ['POST'])]
    public function toggleMyContactFavorite(int $contact_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Get the contact first
            $contact = $this->entityManager->getRepository(ContactEntity::class)->find($contact_id);
            
            if (!$contact) {
                return $this->responseService->json(false, 'Contact not found', null, 404);
            }
            
            // Look for host contacts
            $hostContacts = $this->entityManager->getRepository(HostContactEntity::class)
                ->findBy([
                    'contact' => $contact,
                    'host' => $user,
                    'deleted' => false
                ]);
            
            if (empty($hostContacts)) {
                return $this->responseService->json(false, 'Contact not found in your contacts.', null, 404);
            }
            
            // Toggle favorite
            $hostContact = $hostContacts[0];
            $hostContact->setIsFavorite(!$hostContact->isFavorite());
            $this->entityManager->flush();
            
            return $this->responseService->json(
                true, 
                'Favorite status updated successfully.', 
                ['is_favorite' => $hostContact->isFavorite()]
            );
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Error: ' . $e->getMessage(), null, 400);
        }
    }




    #[Route('/organizations/{organization_id}/contacts/{contact_id}/toggle-favorite', name: 'org_contact_toggle_favorite#', methods: ['POST'])]
    public function toggleOrgContactFavorite(int $organization_id, int $contact_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.', null, 404);
            }
            
            // Fix: Use the correct method name
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }
            
            // Get the organization contact
            $orgContact = $this->entityManager->getRepository(OrganizationContactEntity::class)
                ->findOneBy([
                    'id' => $contact_id,
                    'organization' => $organization,
                    'deleted' => false
                ]);
                
            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact not found.', null, 404);
            }
            
            // Toggle favorite
            $orgContact->setIsFavorite(!$orgContact->isFavorite());
            $this->entityManager->flush();
            
            return $this->responseService->json(
                true, 
                'Favorite status updated successfully.', 
                ['is_favorite' => $orgContact->isFavorite()]
            );
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Error: ' . $e->getMessage(), null, 400);
        }
    }

    #[Route('/contacts/{contact_id}', name: 'delete_my_contact#', methods: ['DELETE'])]
    public function deleteMyContact(int $contact_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // First, find all host_contact records for this contact and user
            $hostContacts = $this->entityManager->getRepository(HostContactEntity::class)
                ->findBy([
                    'contact' => $contact_id,
                    'host' => $user,
                    'deleted' => false
                ]);
            
            if (empty($hostContacts)) {
                return $this->responseService->json(false, 'Contact not found in your contacts.', null, 404);
            }
            
            // Soft delete all host_contact records
            foreach ($hostContacts as $hostContact) {
                $hostContact->setDeleted(true);
            }
            
            $this->entityManager->flush();
            
            return $this->responseService->json(true, 'Contact removed successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Error: ' . $e->getMessage(), null, 400);
        }
    }


    #[Route('/contacts/export', name: 'export_my_contacts#', methods: ['GET'])]
    public function exportMyContacts(Request $request): \Symfony\Component\HttpFoundation\Response  // Use full namespace
    {
        $user = $request->attributes->get('user');
        
        try {
            $filters = [
                'search' => $request->query->get('search', '')
            ];
            
            return $this->contactExportService->exportContacts($user, null, $filters);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Export failed: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/contacts/export', name: 'export_org_contacts#', methods: ['GET'])]
    public function exportOrganizationContacts(int $organization_id, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->attributes->get('user');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.', null, 404);
            }
            
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'Access denied.', null, 403);
            }
            
            $filters = [
                'search' => $request->query->get('search', '')
            ];
            
            return $this->contactExportService->exportContacts($user, $organization, $filters);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Export failed: ' . $e->getMessage(), null, 500);
        }
    }

}