<?php
// src/Plugins/Integrations/Message/SyncGoogleCalendarMessage.php

namespace App\Plugins\Integrations\Google\Calendar\Message;

class SyncGoogleCalendarMessage
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