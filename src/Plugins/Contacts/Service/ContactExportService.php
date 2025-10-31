<?php

namespace App\Plugins\Contacts\Service;

use App\Service\ExportService;
use App\Service\CrudManager;
use App\Plugins\Contacts\Entity\ContactEntity;
use App\Plugins\Contacts\Entity\HostContactEntity;
use App\Plugins\Contacts\Entity\OrganizationContactEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use Doctrine\ORM\EntityManagerInterface;

class ContactExportService extends ExportService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private ContactService $contactService;
    
    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        ContactService $contactService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->contactService = $contactService;
    }
    
    /**
     * Export contacts based on type (my-contacts or organization)
     */
    public function exportContacts(
        ?UserEntity $user = null, 
        ?OrganizationEntity $organization = null,
        array $filters = []
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $this->user = $user;
        $this->organization = $organization;
        $this->filters = $filters;
        
        return $this->exportToCsv($filters);
    }
    
    protected function getExportData(array $filters): array
    {
        $exportData = [];
        
        if ($this->organization) {
            // Export organization contacts
            $result = $this->contactService->getContactsWithMeetingInfo(
                $this->organization,
                $filters,
                1,
                10000 // Get all contacts
            );
            
            foreach ($result['data'] as $contactData) {
                $exportData[] = $this->formatOrganizationContact($contactData);
            }
        } else {
            // Export "My Contacts"
            $result = $this->contactService->getHostContacts(
                $this->user,
                null,
                $filters,
                1,
                10000 // Get all contacts
            );
            
            foreach ($result['data'] as $contactData) {
                $exportData[] = $this->formatHostContact($contactData);
            }
        }
        
        return $exportData;
    }
    
    protected function formatOrganizationContact($contactData): array
    {
        return [
            'name' => $contactData['contact']['name'] ?? '',
            'email' => $contactData['contact']['email'] ?? '',
            'phone' => $contactData['contact']['phone'] ?? '',
            'is_favorite' => $contactData['organization_contact']['is_favorite'] ?? false,
            'last_meeting_date' => $contactData['last_meeting']['date'] ?? null,
            'last_meeting_event' => $contactData['last_meeting']['event_name'] ?? '',
            'next_meeting_date' => $contactData['next_meeting']['date'] ?? null,
            'next_meeting_event' => $contactData['next_meeting']['event_name'] ?? '',
            'created_at' => $contactData['created_at'] ?? null
        ];
    }
    
    protected function formatHostContact($contactData): array
    {
        return [
            'name' => $contactData['contact']['name'] ?? '',
            'email' => $contactData['contact']['email'] ?? '',
            'phone' => $contactData['contact']['phone'] ?? '',
            'organization' => $contactData['organization']['name'] ?? '',
            'is_favorite' => $contactData['host_info']['is_favorite'] ?? false,
            'meeting_count' => $contactData['host_info']['meeting_count'] ?? 0,
            'first_meeting' => $contactData['host_info']['first_meeting'] ?? null,
            'last_meeting' => $contactData['host_info']['last_meeting'] ?? null,
            'last_meeting_date' => $contactData['last_meeting']['date'] ?? null,
            'last_meeting_event' => $contactData['last_meeting']['event_name'] ?? '',
            'next_meeting_date' => $contactData['next_meeting']['date'] ?? null,
            'next_meeting_event' => $contactData['next_meeting']['event_name'] ?? '',
            'created_at' => $contactData['created_at'] ?? null
        ];
    }
    
    protected function getExportColumns(): array
    {
        if ($this->organization) {
            // Organization contacts columns
            return [
                'name' => 'Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'is_favorite' => 'Favorite',
                'last_meeting_date' => 'Last Meeting Date',
                'last_meeting_event' => 'Last Meeting Event',
                'next_meeting_date' => 'Next Meeting Date',
                'next_meeting_event' => 'Next Meeting Event',
                'created_at' => 'Added On'
            ];
        } else {
            // My Contacts columns
            return [
                'name' => 'Name',
                'email' => 'Email',
                'phone' => 'Phone',
                'organization' => 'Organization',
                'is_favorite' => 'Favorite',
                'meeting_count' => 'Total Meetings',
                'first_meeting' => 'First Meeting',
                'last_meeting' => 'Last Meeting',
                'last_meeting_date' => 'Last Meeting Date',
                'last_meeting_event' => 'Last Meeting Event',
                'next_meeting_date' => 'Next Meeting Date',
                'next_meeting_event' => 'Next Meeting Event',
                'created_at' => 'Added On'
            ];
        }
    }
    
    protected function getFilename(): string
    {
        $date = date('Y-m-d');
        
        if ($this->organization) {
            $orgName = preg_replace('/[^a-zA-Z0-9]/', '_', $this->organization->getName());
            return "contacts_{$orgName}_{$date}.csv";
        } else {
            return "my_contacts_{$date}.csv";
        }
    }
    
    private ?UserEntity $user = null;
    private ?OrganizationEntity $organization = null;
    private array $filters = [];
}