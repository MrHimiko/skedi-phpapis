<?php
// Path: src/Plugins/Email/Templates/MeetingReminderTemplate.php

namespace App\Plugins\Email\Templates;

class MeetingReminderTemplate
{
    public static function render(array $data): string
    {
        // Extract variables
        $name = $data['name'] ?? $data['guest_name'] ?? $data['host_name'] ?? 'there';
        $meetingName = $data['meeting_name'] ?? 'Meeting';
        $meetingDate = $data['meeting_date'] ?? $data['date'] ?? 'today';
        $meetingTime = $data['meeting_time'] ?? $data['time'] ?? 'soon';
        $meetingLocation = $data['meeting_location'] ?? $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $hoursUntil = $data['hours_until'] ?? $data['reminder_time'] ?? '24';
        $hostName = $data['host_name'] ?? '';
        $guestName = $data['guest_name'] ?? '';
        
        // Determine time message
        $timeMessage = '';
        if ($hoursUntil <= 1) {
            $timeMessage = 'Your meeting starts in less than an hour!';
        } elseif ($hoursUntil <= 24) {
            $timeMessage = 'Your meeting is in ' . $hoursUntil . ' hours.';
        } else {
            $timeMessage = 'Your meeting is tomorrow.';
        }
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Reminder</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('Meeting Reminder', '⏰') . '
        <div class="content">
            <p class="greeting">Hi ' . $name . ',</p>
            
            <p class="message">
                <strong>' . $timeMessage . '</strong><br>
                This is a friendly reminder about your upcoming meeting.
            </p>
            
            <div class="alert">
                <strong>⏰ ' . $meetingTime . ' - ' . $meetingDate . '</strong>
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <strong>Meeting:</strong> ' . $meetingName . '
                </div>';
        
        if ($hostName && $guestName) {
            $html .= '
                <div class="detail-row">
                    <strong>Participants:</strong> ' . $hostName . ' & ' . $guestName . '
                </div>';
        }
        
        $html .= '
                <div class="detail-row">
                    <strong>Date:</strong> ' . $meetingDate . '
                </div>
                <div class="detail-row">
                    <strong>Time:</strong> ' . $meetingTime . '
                </div>
                <div class="detail-row">
                    <strong>Location:</strong> ' . $meetingLocation . '
                </div>';
        
        if ($meetingLink) {
            $html .= '
                <div class="detail-row">
                    <strong>Meeting Link:</strong> <a href="' . $meetingLink . '" style="color: #667eea;">Join Meeting</a>
                </div>';
        }
        
        $html .= '
            </div>';
        
        if ($meetingLink) {
            $html .= '
            <div class="center">
                <a href="' . $meetingLink . '" class="button">Join Meeting</a>
            </div>';
        }
        
        $html .= EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}