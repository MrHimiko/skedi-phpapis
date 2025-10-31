<?php

namespace App\Plugins\Integrations\Google\Meet\Message;

class CreateGoogleMeetLinkMessage
{
    private int $integrationId;
    private string $title;
    private string $startTime;
    private string $endTime;
    private ?int $eventId;
    private ?int $bookingId;
    private array $options;
    
    public function __construct(
        int $integrationId,
        string $title,
        string $startTime,
        string $endTime,
        ?int $eventId = null,
        ?int $bookingId = null,
        array $options = []
    ) {
        $this->integrationId = $integrationId;
        $this->title = $title;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->eventId = $eventId;
        $this->bookingId = $bookingId;
        $this->options = $options;
    }
    
    public function getIntegrationId(): int
    {
        return $this->integrationId;
    }
    
    public function getTitle(): string
    {
        return $this->title;
    }
    
    public function getStartTime(): string
    {
        return $this->startTime;
    }
    
    public function getEndTime(): string
    {
        return $this->endTime;
    }
    
    public function getEventId(): ?int
    {
        return $this->eventId;
    }
    
    public function getBookingId(): ?int
    {
        return $this->bookingId;
    }
    
    public function getOptions(): array
    {
        return $this->options;
    }
}