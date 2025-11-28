<?php

namespace App\Plugins\Contacts\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Contacts\Entity\ContactEntity;
use App\Plugins\Contacts\Entity\OrganizationContactEntity;
use App\Plugins\Contacts\Repository\ContactRepository;
use App\Plugins\Contacts\Repository\OrganizationContactRepository;
use App\Plugins\Contacts\Exception\ContactsException;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Contacts\Entity\HostContactEntity;
use App\Plugins\Contacts\Entity\ContactBookingEntity;
use App\Plugins\Events\Entity\EventAssigneeEntity;
use App\Plugins\PotentialLeads\Service\PotentialLeadService;

class ContactService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private ContactRepository $contactRepository;
    private OrganizationContactRepository $organizationContactRepository;
    private PotentialLeadService $potentialLeadService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        ContactRepository $contactRepository,
        OrganizationContactRepository $organizationContactRepository,
        PotentialLeadService $potentialLeadService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->contactRepository = $contactRepository;
        $this->organizationContactRepository = $organizationContactRepository;
        $this->potentialLeadService = $potentialLeadService;
    }

    public function getMany(OrganizationEntity $organization, array $filters, int $page, int $limit): array
    {
        try {
            return $this->crudManager->findMany(
                OrganizationContactEntity::class,
                [],
                $page,
                $limit,
                [
                    'organization' => $organization,
                    'deleted' => false
                ]
            );
        } catch (CrudException $e) {
            throw new ContactsException($e->getMessage());
        }
    }

    /**
     * Get contacts with meeting information
     */
    public function getContactsWithMeetingInfo(OrganizationEntity $organization, array $filters, int $page, int $limit): array
    {
        try {
            // For search, we need to use callback because we need to search across related entity fields
            $callback = null;
            if (!empty($filters['search'])) {
                $callback = function($queryBuilder) use ($filters) {
                    $queryBuilder->join('t1.contact', 'c');
                    $searchTerm = '%' . strtolower($filters['search']) . '%';
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->orX(
                            $queryBuilder->expr()->like('LOWER(c.name)', ':search'),
                            $queryBuilder->expr()->like('LOWER(c.email)', ':search'),
                            $queryBuilder->expr()->like('LOWER(c.phone)', ':search')
                        )
                    )->setParameter('search', $searchTerm);
                };
            }

            // Get organization contacts
            $orgContacts = $this->crudManager->findMany(
                OrganizationContactEntity::class,
                [],
                $page,
                $limit,
                [
                    'organization' => $organization,
                    'deleted' => false
                ],
                $callback
            );

            // Get total count
            $totalCount = $this->crudManager->findMany(
                OrganizationContactEntity::class,
                [],
                1,
                1,
                [
                    'organization' => $organization,
                    'deleted' => false
                ],
                $callback,
                true // count flag
            );

            // Process contacts and add meeting information
            $data = [];
            foreach ($orgContacts as $orgContact) {
                $contact = $orgContact->getContact();

                // Get last meeting
                $lastMeeting = $this->getLastMeetingForContact($contact, $organization);
                
                // Get next meeting
                $nextMeeting = $this->getNextMeetingForContact($contact, $organization);

                $contactData = [
                    'id' => $orgContact->getId(),
                    'contact' => [
                        'id' => $contact->getId(),
                        'name' => $contact->getName(),
                        'email' => $contact->getEmail(),
                        'phone' => $contact->getPhone(),
                    ],
                    'organization_contact' => [
                        'is_favorite' => $orgContact->isFavorite(),
                        'interaction_count' => $orgContact->getInteractionCount(),
                    ],
                    'last_meeting' => $lastMeeting,
                    'next_meeting' => $nextMeeting,
                    'created_at' => $orgContact->getCreated()->format('Y-m-d H:i:s')
                ];

                $data[] = $contactData;
            }

            return [
                'data' => $data,
                'count' => $totalCount[0] ?? 0,
                'page' => $page,
                'limit' => $limit
            ];
        } catch (CrudException $e) {
            throw new ContactsException('Failed to fetch contacts: ' . $e->getMessage());
        }
    }

    /**
     * Get last meeting for a contact using CrudManager filters
     */
    private function getLastMeetingForContact(ContactEntity $contact, OrganizationEntity $organization): ?array
    {
        try {
            // Use current time with seconds precision for accurate comparison
            $now = new \DateTime();
            
            // Get all contact bookings for this contact
            $contactBookings = $this->crudManager->findMany(
                ContactBookingEntity::class,
                [],
                1,
                100, // Get more to filter in PHP
                [
                    'contact' => $contact,
                    'organization' => $organization
                ]
            );
            
            // Filter for past meetings in PHP
            $pastMeetings = [];
            foreach ($contactBookings as $contactBooking) {
                $booking = $contactBooking->getBooking();
                
                // Fixed logic: A meeting is "past" if it's start time is before or equal to now
                if (!$booking->isCancelled() && $booking->getStartTime() <= $now) {
                    $pastMeetings[] = $contactBooking;
                }
            }
            
            // Sort by start time descending to get the most recent past meeting
            usort($pastMeetings, function($a, $b) {
                return $b->getBooking()->getStartTime() <=> $a->getBooking()->getStartTime();
            });
            
            if (!empty($pastMeetings)) {
                $contactBooking = $pastMeetings[0];
                $booking = $contactBooking->getBooking();
                $event = $contactBooking->getEvent();
                
                return [
                    'date' => $booking->getStartTime()->format('Y-m-d H:i:s'),
                    'event_name' => $event->getName(),
                    'event_id' => $event->getId(),
                    'booking_id' => $booking->getId()
                ];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }


    /**
     * Get next meeting for a contact using CrudManager
     */
    private function getNextMeetingForContact(ContactEntity $contact, OrganizationEntity $organization): ?array
    {
        try {
            // Use current time with seconds precision for accurate comparison
            $now = new \DateTime();
            
            // Get all contact bookings for this contact
            $contactBookings = $this->crudManager->findMany(
                ContactBookingEntity::class,
                [],
                1,
                100, // Get more to filter in PHP
                [
                    'contact' => $contact,
                    'organization' => $organization
                ]
            );
            
            // Filter for future meetings in PHP
            $futureMeetings = [];
            foreach ($contactBookings as $contactBooking) {
                $booking = $contactBooking->getBooking();
                
                // Fixed logic: A meeting is "next/future" if it's start time is after now
                // This includes meetings that are later today
                if (!$booking->isCancelled() && $booking->getStartTime() > $now) {
                    $futureMeetings[] = $contactBooking;
                }
            }
            
            // Sort by start time ascending to get the next upcoming
            usort($futureMeetings, function($a, $b) {
                return $a->getBooking()->getStartTime() <=> $b->getBooking()->getStartTime();
            });
            
            if (!empty($futureMeetings)) {
                $contactBooking = $futureMeetings[0];
                $booking = $contactBooking->getBooking();
                $event = $contactBooking->getEvent();
                
                return [
                    'date' => $booking->getStartTime()->format('Y-m-d H:i:s'),
                    'event_name' => $event->getName(),
                    'event_id' => $event->getId(),
                    'booking_id' => $booking->getId()
                ];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get host contacts (for "My Contacts" view)
     */
    public function getHostContacts(UserEntity $host, ?OrganizationEntity $organization, array $filters, int $page, int $limit): array
    {
        try {
            $criteria = [
                'host' => $host,
                'deleted' => false
            ];
            
            // Add organization filter if provided
            if ($organization) {
                $criteria['organization'] = $organization;
            }
            
            // For search, we need callback to search in related contact entity
            $callback = null;
            if (!empty($filters['search'])) {
                $callback = function($queryBuilder) use ($filters) {
                    $queryBuilder->join('t1.contact', 'c');
                    $searchTerm = '%' . strtolower($filters['search']) . '%';
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->orX(
                            $queryBuilder->expr()->like('LOWER(c.name)', ':search'),
                            $queryBuilder->expr()->like('LOWER(c.email)', ':search'),
                            $queryBuilder->expr()->like('LOWER(c.phone)', ':search')
                        )
                    )->setParameter('search', $searchTerm);
                };
            }
            
            // Get host contacts
            $hostContacts = $this->crudManager->findMany(
                HostContactEntity::class,
                [],
                $page,
                $limit,
                $criteria,
                $callback
            );

            // Get total count
            $totalCount = $this->crudManager->findMany(
                HostContactEntity::class,
                [],
                1,
                1,
                $criteria,
                $callback,
                true // count flag
            );

            // Process host contacts
            $data = [];
            foreach ($hostContacts as $hostContact) {
                $contact = $hostContact->getContact();
                $org = $hostContact->getOrganization();

                // Get meeting information
                $lastMeeting = $this->getLastMeetingForContact($contact, $org);
                $nextMeeting = $this->getNextMeetingForContact($contact, $org);

                // Check if this contact is a favorite in the organization
                $orgContact = $this->organizationContactRepository->findOneBy([
                    'contact' => $contact,
                    'organization' => $org,
                    'deleted' => false
                ]);
                
                $isFavorite = $orgContact ? $orgContact->isFavorite() : false;

                $data[] = [
                    'id' => $contact->getId(),
                    'contact' => [
                        'id' => $contact->getId(),
                        'name' => $contact->getName(),
                        'email' => $contact->getEmail(),
                        'phone' => $contact->getPhone(),
                    ],
                    'organization' => [
                        'id' => $org->getId(),
                        'name' => $org->getName()
                    ],
                    'host_info' => [
                        'meeting_count' => $hostContact->getMeetingCount(),
                        'first_meeting' => $hostContact->getFirstMeeting() ? 
                            $hostContact->getFirstMeeting()->format('Y-m-d H:i:s') : null,
                        'last_meeting' => $hostContact->getLastMeeting() ? 
                            $hostContact->getLastMeeting()->format('Y-m-d H:i:s') : null,
                        'is_favorite' => $hostContact->isFavorite()
                    ],
                    'organization_contact' => [
                        'is_favorite' => $isFavorite
                    ],
                    'last_meeting' => $lastMeeting,
                    'next_meeting' => $nextMeeting,
                    'created_at' => $hostContact->getCreated()->format('Y-m-d H:i:s')
                ];
            }

            return [
                'data' => $data,
                'count' => $totalCount[0] ?? 0,
                'page' => $page,
                'limit' => $limit
            ];
        } catch (CrudException $e) {
            throw new ContactsException('Failed to fetch host contacts: ' . $e->getMessage());
        }
    }

    // Keep all other existing methods exactly as they are
    public function getOne(OrganizationEntity $organization, int $id): ?OrganizationContactEntity
    {
        return $this->organizationContactRepository->findOneBy([
            'id' => $id,
            'organization' => $organization,
            'deleted' => false
        ]);
    }

    public function create(array $data): ContactEntity
    {
        $contact = new ContactEntity();
        $contact->setEmail($data['email']);
        $contact->setName($data['name'] ?? null);
        $contact->setPhone($data['phone'] ?? null);
        
        $this->entityManager->persist($contact);
        $this->entityManager->flush();

        return $contact;
    }

    public function update(ContactEntity $contact, array $data): void
    {
        if (isset($data['name'])) {
            $contact->setName($data['name']);
        }
        if (isset($data['email'])) {
            $contact->setEmail($data['email']);
        }
        if (isset($data['phone'])) {
            $contact->setPhone($data['phone']);
        }

        $this->entityManager->flush();
    }

    public function delete(OrganizationContactEntity $orgContact): void
    {
        $orgContact->setDeleted(true);
        $this->entityManager->flush();
    }

    public function toggleFavorite(int $contactId, UserEntity $user, ?OrganizationEntity $organization = null): bool
    {
        try {
            if ($organization) {
                // Toggle favorite for organization contact
                $orgContacts = $this->crudManager->findMany(
                    OrganizationContactEntity::class,
                    [],
                    1,
                    1,
                    [
                        'contact' => $contactId,
                        'organization' => $organization,
                        'deleted' => false
                    ]
                );
                
                if (!empty($orgContacts)) {
                    $contact = $orgContacts[0];
                    $contact->setIsFavorite(!$contact->isFavorite());
                    $this->entityManager->flush();
                    return $contact->isFavorite();
                }
            } else {
                // Toggle favorite for host contact (My Contacts)
                $hostContacts = $this->crudManager->findMany(
                    HostContactEntity::class,
                    [],
                    1,
                    1,
                    [
                        'contact' => $contactId,
                        'host' => $user,
                        'deleted' => false
                    ]
                );
                
                if (!empty($hostContacts)) {
                    $hostContact = $hostContacts[0];
                    $hostContact->setIsFavorite(!$hostContact->isFavorite());
                    $this->entityManager->flush();
                    return $hostContact->isFavorite();
                }
            }
            
            throw new ContactsException('Contact not found');
        } catch (\Exception $e) {
            throw new ContactsException('Failed to toggle favorite: ' . $e->getMessage());
        }
    }

    public function getContactByEmail(string $email): ?ContactEntity
    {
        return $this->contactRepository->findOneBy(['email' => $email]);
    }

    /**
     * Create or update contact from booking data
     */
    public function createOrUpdateFromBooking(EventBookingEntity $booking): ContactEntity
    {
        return $this->createContactFromBooking($booking);
    }

    /**
     * Create contact from booking data
     */
    public function createContactFromBooking(EventBookingEntity $booking): ContactEntity
    {
        $formData = $booking->getFormDataAsArray();
        $email = $formData['primary_contact']['email'] ?? null;
        $name = $formData['primary_contact']['name'] ?? null;

        if (!$email) {
            throw new ContactsException('No email found in booking data.');
        }

        // Check if contact exists
        $contact = $this->getContactByEmail($email);

        if (!$contact) {
            // Create new contact
            $contact = $this->create([
                'email' => $email,
                'name' => $name,
                'phone' => $formData['primary_contact']['phone'] ?? null,
            ]);
        } else if ($name && !$contact->getName()) {
            // Update contact name if it was empty
            $this->update($contact, ['name' => $name]);
        }

        $event = $booking->getEvent();
        $organization = $event->getOrganization();

        // Create or update organization contact
        $this->createOrUpdateOrganizationContact($contact, $organization);

        // Create host contacts for all event assignees
        $this->createHostContactsFromBooking($contact, $booking);

        // Remove from potential leads if exists
        $this->potentialLeadService->removePotentialLead($email, $organization);


        // Create contact booking record
        $this->createContactBooking($contact, $booking);

        return $contact;
    }

    public function createOrUpdateOrganizationContact(ContactEntity $contact, OrganizationEntity $organization): OrganizationContactEntity
    {
        $orgContact = $this->organizationContactRepository->findOneBy([
            'contact' => $contact,
            'organization' => $organization,
            'deleted' => false
        ]);

        if (!$orgContact) {
            $orgContact = new OrganizationContactEntity();
            $orgContact->setContact($contact);
            $orgContact->setOrganization($organization);
            
            $this->entityManager->persist($orgContact);
        } else {
            $orgContact->incrementInteractionCount();
        }

        $this->entityManager->flush();

        return $orgContact;
    }

    private function createHostContactsFromBooking(ContactEntity $contact, EventBookingEntity $booking): void
    {
        $event = $booking->getEvent();
        $organization = $event->getOrganization();
        
        $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
            ->findBy(['event' => $event]);
        
        foreach ($assignees as $assignee) {
            $host = $assignee->getUser();
            
            $existingHostContact = $this->entityManager->getRepository('App\Plugins\Contacts\Entity\HostContactEntity')
                ->findOneBy([
                    'contact' => $contact,
                    'host' => $host
                ]);
            
            if (!$existingHostContact) {
                $hostContact = new HostContactEntity();
                $hostContact->setContact($contact);
                $hostContact->setHost($host);
                $hostContact->setOrganization($organization);
                $hostContact->setFirstMeeting(new \DateTime());
                $hostContact->setLastMeeting(new \DateTime());
                $hostContact->setMeetingCount(1);
                
                $this->entityManager->persist($hostContact);
            } else {
                if ($existingHostContact->isDeleted()) {
                    $existingHostContact->setDeleted(false);
                    $existingHostContact->setOrganization($organization);
                }
                $existingHostContact->setLastMeeting(new \DateTime());
                $existingHostContact->setMeetingCount($existingHostContact->getMeetingCount() + 1);
                
                $this->entityManager->persist($existingHostContact);
            }
        }
        
        if (empty($assignees)) {
            $creator = $event->getCreatedBy();
            
            $existingHostContact = $this->entityManager->getRepository('App\Plugins\Contacts\Entity\HostContactEntity')
                ->findOneBy([
                    'contact' => $contact,
                    'host' => $creator
                ]);
            
            if (!$existingHostContact) {
                $hostContact = new HostContactEntity();
                $hostContact->setContact($contact);
                $hostContact->setHost($creator);
                $hostContact->setOrganization($organization);
                $hostContact->setFirstMeeting(new \DateTime());
                $hostContact->setLastMeeting(new \DateTime());
                $hostContact->setMeetingCount(1);
                
                $this->entityManager->persist($hostContact);
            } else {
                if ($existingHostContact->isDeleted()) {
                    $existingHostContact->setDeleted(false);
                    $existingHostContact->setOrganization($organization);
                }
                $existingHostContact->setLastMeeting(new \DateTime());
                $existingHostContact->setMeetingCount($existingHostContact->getMeetingCount() + 1);
                
                $this->entityManager->persist($existingHostContact);
            }
        }
        
        $this->entityManager->flush();
    }


    private function createContactBooking(ContactEntity $contact, EventBookingEntity $booking): void
    {
        // Create contact_bookings record
        $contactBooking = new ContactBookingEntity();
        $contactBooking->setContact($contact);
        $contactBooking->setBooking($booking);
        $contactBooking->setOrganization($booking->getEvent()->getOrganization());
        $contactBooking->setEvent($booking->getEvent());
        
        // Get the host (first assignee or event creator)
        $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
            ->findBy(['event' => $booking->getEvent()]);
        
        if (!empty($assignees)) {
            $contactBooking->setHost($assignees[0]->getUser());
        } else {
            $contactBooking->setHost($booking->getEvent()->getCreatedBy());
        }
        
        $this->entityManager->persist($contactBooking);
        $this->entityManager->flush();
    }

    /**
     * Toggle favorite status for host contact
     * Note: HostContactEntity doesn't have favorites, so we use OrganizationContactEntity
     */
    public function toggleHostContactFavorite(UserEntity $host, ContactEntity $contact, OrganizationEntity $organization): bool
    {
        // Since HostContactEntity doesn't have favorites, we'll use OrganizationContactEntity
        $orgContact = $this->organizationContactRepository->findOneBy([
            'contact' => $contact,
            'organization' => $organization,
            'deleted' => false
        ]);
        
        if (!$orgContact) {
            // Create organization contact if it doesn't exist
            $orgContact = new OrganizationContactEntity();
            $orgContact->setContact($contact);
            $orgContact->setOrganization($organization);
            $this->entityManager->persist($orgContact);
        }
        
        $orgContact->setIsFavorite(!$orgContact->isFavorite());
        $this->entityManager->flush();
        
        return $orgContact->isFavorite();
    }
}