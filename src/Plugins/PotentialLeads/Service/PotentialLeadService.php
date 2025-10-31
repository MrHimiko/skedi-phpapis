<?php

namespace App\Plugins\PotentialLeads\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\PotentialLeads\Entity\PotentialLeadEntity;
use App\Plugins\PotentialLeads\Entity\OrganizationPotentialLeadEntity;
use App\Plugins\PotentialLeads\Entity\HostPotentialLeadEntity;
use App\Plugins\PotentialLeads\Repository\PotentialLeadRepository;
use App\Plugins\PotentialLeads\Repository\OrganizationPotentialLeadRepository;
use App\Plugins\PotentialLeads\Exception\PotentialLeadsException;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Contacts\Repository\ContactRepository;
use App\Plugins\Contacts\Repository\OrganizationContactRepository;

class PotentialLeadService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private PotentialLeadRepository $potentialLeadRepository;
    private OrganizationPotentialLeadRepository $organizationPotentialLeadRepository;
    private ContactRepository $contactRepository;
    private OrganizationContactRepository $organizationContactRepository;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        PotentialLeadRepository $potentialLeadRepository,
        OrganizationPotentialLeadRepository $organizationPotentialLeadRepository,
        ContactRepository $contactRepository,
        OrganizationContactRepository $organizationContactRepository
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->potentialLeadRepository = $potentialLeadRepository;
        $this->organizationPotentialLeadRepository = $organizationPotentialLeadRepository;
        $this->contactRepository = $contactRepository;
        $this->organizationContactRepository = $organizationContactRepository;
    }

    /**
     * Add potential lead from event booking form
     */
    public function addFromEvent(EventEntity $event, array $data): ?PotentialLeadEntity
    {
        try {
            $email = $data['email'] ?? null;
            if (!$email) {
                throw new PotentialLeadsException('Email is required');
            }

            $organization = $event->getOrganization();
            
            // Check if email already exists in contacts for this organization
            if ($this->isExistingContact($email, $organization)) {
                return null; // Already a contact, don't add as lead
            }

            // Find existing potential lead by email or create new one
            $existingLeads = $this->crudManager->findMany(
                PotentialLeadEntity::class,
                [],
                1,
                1,
                ['email' => $email]
            );

            if (!empty($existingLeads)) {
                // Use existing potential lead
                $potentialLead = $existingLeads[0];
                
                // Update name and timezone if provided and different
                $needsUpdate = false;
                if (!empty($data['name']) && $potentialLead->getName() !== $data['name']) {
                    $potentialLead->setName($data['name']);
                    $needsUpdate = true;
                }
                if (!empty($data['timezone']) && $potentialLead->getTimezone() !== $data['timezone']) {
                    $potentialLead->setTimezone($data['timezone']);
                    $needsUpdate = true;
                }
                
                if ($needsUpdate) {
                    $this->entityManager->persist($potentialLead);
                    $this->entityManager->flush();
                }
            } else {
                // Create new potential lead
                $potentialLead = $this->createPotentialLead($data);
            }

            // Check if organization relationship already exists
            $existingOrgLeads = $this->crudManager->findMany(
                OrganizationPotentialLeadEntity::class,
                [],
                1,
                1,
                [
                    'potentialLead' => $potentialLead,
                    'organization' => $organization,
                    'deleted' => false
                ]
            );

            if (empty($existingOrgLeads)) {
                // Create organization relationship using the existing method
                $orgLead = new OrganizationPotentialLeadEntity();
                $orgLead->setPotentialLead($potentialLead);
                $orgLead->setOrganization($organization);

                $this->entityManager->persist($orgLead);
                $this->entityManager->flush();
            }

            // Create host relationships for all assignees
            $this->createHostPotentialLeads($potentialLead, $event);

            return $potentialLead;

        } catch (CrudException $e) {
            throw new PotentialLeadsException('Failed to add potential lead: ' . $e->getMessage());
        }
    }
    /**
     * Create organization potential lead relationship if it doesn't exist
     */
    private function createOrganizationPotentialLeadIfNotExists(
        PotentialLeadEntity $potentialLead, 
        OrganizationEntity $organization
    ): void {
        // Check if relationship already exists
        $existing = $this->crudManager->findMany(
            OrganizationPotentialLeadEntity::class,
            [],
            1,
            1,
            [
                'potentialLead' => $potentialLead,
                'organization' => $organization,
                'deleted' => false
            ]
        );

        if (empty($existing)) {
            // Create new relationship
            $orgLead = new OrganizationPotentialLeadEntity();
            $orgLead->setPotentialLead($potentialLead);
            $orgLead->setOrganization($organization);

            $this->entityManager->persist($orgLead);
            $this->entityManager->flush();
        }
    }


    /**
     * Find existing potential lead or create new one
     */
    private function findOrCreatePotentialLead(array $data): PotentialLeadEntity
    {
        // First check if potential lead with this email already exists
        $existingLeads = $this->crudManager->findMany(
            PotentialLeadEntity::class,
            [],
            1,
            1,
            ['email' => $data['email']]
        );

        if (!empty($existingLeads)) {
            // Use existing potential lead
            $potentialLead = $existingLeads[0];
            
            // Update name and timezone if provided and different
            $needsUpdate = false;
            if (!empty($data['name']) && $potentialLead->getName() !== $data['name']) {
                $potentialLead->setName($data['name']);
                $needsUpdate = true;
            }
            if (!empty($data['timezone']) && $potentialLead->getTimezone() !== $data['timezone']) {
                $potentialLead->setTimezone($data['timezone']);
                $needsUpdate = true;
            }
            
            if ($needsUpdate) {
                $this->entityManager->persist($potentialLead);
                $this->entityManager->flush();
            }
            
            return $potentialLead;
        }

        // Create new potential lead
        return $this->createPotentialLead($data);
    }

    /**
     * Get host potential leads
     */
    public function getHostPotentialLeads(
        UserEntity $host, 
        ?OrganizationEntity $organization = null,
        array $filters = [], 
        int $page = 1, 
        int $limit = 50
    ): array {
        try {
            $criteria = [
                'host' => $host,
                'deleted' => false
            ];

            if ($organization) {
                $criteria['organization'] = $organization;
            }

            // Get all host leads first
            $allHostLeads = $this->crudManager->findMany(
                HostPotentialLeadEntity::class,
                [],
                1,
                10000, // Get all to filter
                $criteria
            );

            // Filter by search if needed
            if (!empty($filters['search'])) {
                $searchTerm = strtolower($filters['search']);
                $allHostLeads = array_filter($allHostLeads, function($hostLead) use ($searchTerm) {
                    $lead = $hostLead->getPotentialLead();
                    $emailMatch = strpos(strtolower($lead->getEmail()), $searchTerm) !== false;
                    $nameMatch = $lead->getName() && strpos(strtolower($lead->getName()), $searchTerm) !== false;
                    return $emailMatch || $nameMatch;
                });
                $allHostLeads = array_values($allHostLeads); // Re-index array
            }

            // Calculate pagination
            $totalCount = count($allHostLeads);
            $offset = ($page - 1) * $limit;
            $hostLeads = array_slice($allHostLeads, $offset, $limit);

            $data = [];
            foreach ($hostLeads as $hostLead) {
                $lead = $hostLead->getPotentialLead();
                $org = $hostLead->getOrganization();

                $data[] = [
                    'id' => $hostLead->getId(),
                    'potential_lead' => [
                        'id' => $lead->getId(),
                        'email' => $lead->getEmail(),
                        'name' => $lead->getName(),
                        'timezone' => $lead->getTimezone(),
                        'captured_at' => $lead->getCapturedAt()->format('Y-m-d H:i:s')
                    ],
                    'organization' => [
                        'id' => $org->getId(),
                        'name' => $org->getName()
                    ],
                    'created_at' => $hostLead->getCreated()->format('Y-m-d H:i:s')
                ];
            }

            return [
                'data' => $data,
                'count' => $totalCount,
                'page' => $page,
                'limit' => $limit
            ];
        } catch (CrudException $e) {
            throw new PotentialLeadsException('Failed to fetch host potential leads: ' . $e->getMessage());
        }
    }

    /**
     * Get organization potential leads
     */
    public function getOrganizationPotentialLeads(
        OrganizationEntity $organization,
        array $filters = [],
        int $page = 1,
        int $limit = 50
    ): array {
        try {
            // Get all organization leads first
            $allOrgLeads = $this->crudManager->findMany(
                OrganizationPotentialLeadEntity::class,
                [],
                1,
                10000, // Get all to filter
                [
                    'organization' => $organization,
                    'deleted' => false
                ]
            );

            // Filter by search if needed
            if (!empty($filters['search'])) {
                $searchTerm = strtolower($filters['search']);
                $allOrgLeads = array_filter($allOrgLeads, function($orgLead) use ($searchTerm) {
                    $lead = $orgLead->getPotentialLead();
                    $emailMatch = strpos(strtolower($lead->getEmail()), $searchTerm) !== false;
                    $nameMatch = $lead->getName() && strpos(strtolower($lead->getName()), $searchTerm) !== false;
                    return $emailMatch || $nameMatch;
                });
                $allOrgLeads = array_values($allOrgLeads); // Re-index array
            }

            // Calculate pagination
            $totalCount = count($allOrgLeads);
            $offset = ($page - 1) * $limit;
            $orgLeads = array_slice($allOrgLeads, $offset, $limit);

            $data = [];
            foreach ($orgLeads as $orgLead) {
                $lead = $orgLead->getPotentialLead();

                $data[] = [
                    'id' => $orgLead->getId(),
                    'email' => $lead->getEmail(),
                    'name' => $lead->getName(),
                    'timezone' => $lead->getTimezone(),
                    'captured_at' => $lead->getCapturedAt()->format('Y-m-d H:i:s'),
                    'created_at' => $orgLead->getCreated()->format('Y-m-d H:i:s')
                ];
            }

            return [
                'data' => $data,
                'count' => $totalCount,
                'page' => $page,
                'limit' => $limit
            ];
        } catch (CrudException $e) {
            throw new PotentialLeadsException('Failed to fetch organization potential leads: ' . $e->getMessage());
        }
    }

    /**
     * Delete host potential lead
     */
    public function deleteHostPotentialLead(int $leadId, UserEntity $host): void
    {
        try {
            // Use CrudManager to find the entity
            $hostLeads = $this->crudManager->findMany(
                HostPotentialLeadEntity::class,
                [],
                1,
                1,
                [
                    'id' => $leadId,
                    'host' => $host,
                    'deleted' => false
                ]
            );

            if (empty($hostLeads)) {
                throw new PotentialLeadsException('Potential lead not found');
            }

            $hostLead = $hostLeads[0];
            $hostLead->setDeleted(true);
            $this->entityManager->flush();

        } catch (\Exception $e) {
            throw new PotentialLeadsException('Failed to delete potential lead: ' . $e->getMessage());
        }
    }

    /**
     * Delete organization potential lead
     */
    public function deleteOrganizationPotentialLead(int $leadId, OrganizationEntity $organization): void
    {
        try {
            // Use CrudManager to find the entity
            $orgLeads = $this->crudManager->findMany(
                OrganizationPotentialLeadEntity::class,
                [],
                1,
                1,
                [
                    'id' => $leadId,
                    'organization' => $organization,
                    'deleted' => false
                ]
            );

            if (empty($orgLeads)) {
                throw new PotentialLeadsException('Potential lead not found');
            }

            $orgLead = $orgLeads[0];
            $orgLead->setDeleted(true);
            $this->entityManager->flush();

        } catch (\Exception $e) {
            throw new PotentialLeadsException('Failed to delete potential lead: ' . $e->getMessage());
        }
    }

    /**
     * Export host potential leads
     */
    public function exportHostPotentialLeads(
        UserEntity $host,
        ?OrganizationEntity $organization = null,
        array $filters = []
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        // Get all leads
        $result = $this->getHostPotentialLeads($host, $organization, $filters, 1, 10000);
        
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse();
        $response->setCallback(function () use ($result) {
            $handle = fopen('php://output', 'w+');
            
            // Add headers
            fputcsv($handle, ['Email', 'Name', 'Timezone', 'Captured At', 'Organization']);
            
            // Add data
            foreach ($result['data'] as $item) {
                fputcsv($handle, [
                    $item['potential_lead']['email'],
                    $item['potential_lead']['name'] ?? '',
                    $item['potential_lead']['timezone'] ?? '',
                    $item['potential_lead']['captured_at'],
                    $item['organization']['name']
                ]);
            }
            
            fclose($handle);
        });
        
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="potential_leads.csv"');
        
        return $response;
    }

    /**
     * Export organization potential leads
     */
    public function exportOrganizationPotentialLeads(
        OrganizationEntity $organization,
        array $filters = []
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        // Get all leads
        $result = $this->getOrganizationPotentialLeads($organization, $filters, 1, 10000);
        
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse();
        $response->setCallback(function () use ($result) {
            $handle = fopen('php://output', 'w+');
            
            // Add headers
            fputcsv($handle, ['Email', 'Name', 'Timezone', 'Captured At']);
            
            // Add data
            foreach ($result['data'] as $lead) {
                fputcsv($handle, [
                    $lead['email'],
                    $lead['name'] ?? '',
                    $lead['timezone'] ?? '',
                    $lead['captured_at']
                ]);
            }
            
            fclose($handle);
        });
        
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="potential_leads.csv"');
        
        return $response;
    }

    /**
     * Check if email is already a contact
     */
    private function isExistingContact(string $email, OrganizationEntity $organization): bool
    {
        // Check if contact exists using CrudManager
        $contacts = $this->crudManager->findMany(
            'App\Plugins\Contacts\Entity\ContactEntity',
            [],
            1,
            1,
            ['email' => $email]
        );

        if (empty($contacts)) {
            return false;
        }

        // Check if contact is associated with organization
        $orgContacts = $this->crudManager->findMany(
            'App\Plugins\Contacts\Entity\OrganizationContactEntity',
            [],
            1,
            1,
            [
                'contact' => $contacts[0],
                'organization' => $organization,
                'deleted' => false
            ]
        );

        return !empty($orgContacts);
    }

    /**
     * Check if email is already a potential lead
     */
    private function isExistingPotentialLead(string $email, OrganizationEntity $organization): bool
    {
        $potentialLeads = $this->crudManager->findMany(
            PotentialLeadEntity::class,
            [],
            1,
            1,
            ['email' => $email]
        );

        if (empty($potentialLeads)) {
            return false;
        }

        $orgLeads = $this->crudManager->findMany(
            OrganizationPotentialLeadEntity::class,
            [],
            1,
            1,
            [
                'potentialLead' => $potentialLeads[0],
                'organization' => $organization,
                'deleted' => false
            ]
        );

        return !empty($orgLeads);
    }

    /**
     * Create potential lead entity
     */
     private function createPotentialLead(array $data): PotentialLeadEntity
    {
        $potentialLead = new PotentialLeadEntity();
        $potentialLead->setEmail($data['email']);
        $potentialLead->setName($data['name'] ?? null);
        $potentialLead->setTimezone($data['timezone'] ?? null);
        
        if (isset($data['captured_at'])) {
            $potentialLead->setCapturedAt(new \DateTime($data['captured_at']));
        }

        $this->entityManager->persist($potentialLead);
        $this->entityManager->flush();

        return $potentialLead;
    }

    /**
     * Create host potential lead relationships
     */
    private function createHostPotentialLeads(
        PotentialLeadEntity $potentialLead, 
        EventEntity $event
    ): void {
        $organization = $event->getOrganization();
        
        // Get all assignees using CrudManager
        $assignees = $this->crudManager->findMany(
            'App\Plugins\Events\Entity\EventAssigneeEntity',
            [],
            1,
            100,
            ['event' => $event]
        );

        if (empty($assignees)) {
            // If no assignees, use event creator
            $host = $event->getCreatedBy();
            $this->createHostPotentialLead($potentialLead, $host, $organization);
        } else {
            // Create for each assignee
            foreach ($assignees as $assignee) {
                $this->createHostPotentialLead($potentialLead, $assignee->getUser(), $organization);
            }
        }
    }

    /**
     * Create single host potential lead
     */
    private function createHostPotentialLead(
        PotentialLeadEntity $potentialLead,
        UserEntity $host,
        OrganizationEntity $organization
    ): void {
        // Check if already exists using CrudManager
        $existing = $this->crudManager->findMany(
            HostPotentialLeadEntity::class,
            [],
            1,
            1,
            [
                'potentialLead' => $potentialLead,
                'host' => $host,
                'organization' => $organization,
                'deleted' => false
            ]
        );

        if (empty($existing)) {
            $hostLead = new HostPotentialLeadEntity();
            $hostLead->setPotentialLead($potentialLead);
            $hostLead->setHost($host);
            $hostLead->setOrganization($organization);

            $this->entityManager->persist($hostLead);
            $this->entityManager->flush();
        }
    }

    /**
     * Remove potential lead when converted to contact
     */
    public function removePotentialLead(string $email, OrganizationEntity $organization): void
    {
        try {
            $potentialLeads = $this->crudManager->findMany(
                PotentialLeadEntity::class,
                [],
                1,
                1,
                ['email' => $email]
            );

            if (empty($potentialLeads)) {
                return;
            }

            $potentialLead = $potentialLeads[0];

            // Soft delete organization relationship
            $orgLeads = $this->crudManager->findMany(
                OrganizationPotentialLeadEntity::class,
                [],
                1,
                1,
                [
                    'potentialLead' => $potentialLead,
                    'organization' => $organization,
                    'deleted' => false
                ]
            );

            if (!empty($orgLeads)) {
                $orgLeads[0]->setDeleted(true);
                $this->entityManager->flush();
            }

            // Soft delete host relationships
            $hostLeads = $this->crudManager->findMany(
                HostPotentialLeadEntity::class,
                [],
                1,
                100,
                [
                    'potentialLead' => $potentialLead,
                    'organization' => $organization,
                    'deleted' => false
                ]
            );

            foreach ($hostLeads as $hostLead) {
                $hostLead->setDeleted(true);
            }

            $this->entityManager->flush();

        } catch (\Exception $e) {
            // Log error but don't throw - this is cleanup
            error_log('Failed to remove potential lead: ' . $e->getMessage());
        }
    }
}