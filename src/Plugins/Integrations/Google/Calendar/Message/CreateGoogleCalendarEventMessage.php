<?php


namespace App\Plugins\Integrations\Google\Calendar\Message;

class CreateGoogleCalendarEventMessage
{
    private int $integrationId;
    private string $title;
    private string $startTime;
    private string $endTime;
    private array $options;
    
    public function __construct(
        int $integrationId,
        string $title,
        string $startTime,
        string $endTime,
        array $options = []
    ) {
        $this->integrationId = $integrationId;
        $this->title = $title;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
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
    
    public function getOptions(): array
    {
        return $this->options;
    }
}