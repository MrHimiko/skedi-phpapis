<?php

namespace App\Plugins\Integrations\Microsoft\Outlook\Message;

class SyncOutlookCalendarMessage
{
    private int $integrationId;
    private string $startDate;
    private string $endDate;
    
    public function __construct(int $integrationId, string $startDate = 'today', string $endDate = '+30 days')
    {
        $this->integrationId = $integrationId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }
    
    public function getIntegrationId(): int
    {
        return $this->integrationId;
    }
    
    public function getStartDate(): string
    {
        return $this->startDate;
    }
    
    public function getEndDate(): string
    {
        return $this->endDate;
    }
}