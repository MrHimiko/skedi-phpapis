<?php
// Path: src/Plugins/Email/Templates/MeetingCancelledTemplate.php

namespace App\Plugins\Email\Templates;

class MeetingCancelledTemplate
{
    public static function render(array $data): string
    {
        // Extract variables with defaults
        $guestName = $data['guest_name'] ?? 'Guest';
        $hostName = $data['host_name'] ?? 'Host';
        $meetingName = $data['meeting_name'] ?? 'Meeting';
        $meetingDate = $data['meeting_date'] ?? $data['date'] ?? 'TBD';
        $meetingTime = $data['meeting_time'] ?? $data['time'] ?? 'TBD';
        $meetingDuration = $data['meeting_duration'] ?? $data['duration'] ?? '30 minutes';
        $meetingLocation = $data['meeting_location'] ?? $data['location'] ?? 'Online';
        $companyName = $data['company_name'] ?? '';
        $cancellationReason = $data['cancellation_reason'] ?? '';
        $cancelledBy = $data['cancelled_by'] ?? 'the host';
        $rebookLink = $data['rebook_link'] ?? '';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Cancelled</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('Meeting Cancelled', '❌') . '
        <div class="content">
            <p class="greeting">Hi ' . htmlspecialchars($guestName) . ',</p>
            
            <p class="message">
                Unfortunately, your meeting with <strong>' . htmlspecialchars($hostName) . '</strong>';
            
        // Add company name if provided
        if ($companyName) {
            $html .= ' from <strong>' . htmlspecialchars($companyName) . '</strong>';
        }
        
        $html .= ' has been cancelled.</p>
            
            <div class="alert" style="background: #ffe6e6; border: 1px solid #ff4444; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <strong>❌ Meeting Cancelled</strong><br>
                This meeting has been removed from your calendar.
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <strong>Meeting: </strong> ' . htmlspecialchars($meetingName) . '
                </div>
                <div class="detail-row">
                    <strong>Original Date: </strong> ' . htmlspecialchars($meetingDate) . '
                </div>
                <div class="detail-row">
                    <strong>Original Time: </strong> ' . htmlspecialchars($meetingTime) . '
                </div>
                <div class="detail-row">
                    <strong>Duration: </strong> ' . htmlspecialchars($meetingDuration) . '
                </div>
                <div class="detail-row">
                    <strong>Location: </strong> ' . htmlspecialchars($meetingLocation) . '
                </div>';
        
        if ($cancellationReason) {
            $html .= '
                <div class="detail-row">
                    <strong>Reason: </strong> ' . htmlspecialchars($cancellationReason) . '
                </div>';
        }
        
        $html .= '
            </div>';
        
        if ($rebookLink) {
            $html .= '
            <div class="center">
                <a href="' . htmlspecialchars($rebookLink) . '" class="button">Book Another Meeting</a>
            </div>';
        }
        
        $html .= '
            <div class="footer-note">
                <div class="footer-note-title">What happens next:</div>
                • This meeting has been removed from your calendar<br>
                • You will not receive any reminders for this meeting<br>';
        
        if ($rebookLink) {
            $html .= '• You can book a new meeting using the button above<br>';
        }
        
        $html .= '• Contact ' . htmlspecialchars($hostName) . ' directly if you need to reschedule
            </div>';
        
        $html .= EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}