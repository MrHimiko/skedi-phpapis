<?php


namespace App\Plugins\Email\Templates;

class BookingConfirmedTemplate
{
    public static function render(array $data): string
    {
        // Extract variables with defaults
        $guestName = $data['guest_name'] ?? 'Guest';
        $hostName = $data['host_name'] ?? 'Host';
        $eventName = $data['event_name'] ?? 'Event';
        $eventDate = $data['event_date'] ?? 'TBD';
        $eventTime = $data['event_time'] ?? 'TBD';
        $duration = $data['duration'] ?? '30 minutes';
        $location = $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $manageLink = $data['manage_link'] ?? '#';
        $companyName = $data['company_name'] ?? '';
        $bookingId = $data['booking_id'] ?? '';
        $organizationId = $data['organization_id'] ?? '';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('Booking Confirmed!') . '
        
        <p class="greeting">Hi ' . htmlspecialchars($guestName) . ',</p>
        
        <p class="message">Great news! Your meeting with <strong>' . htmlspecialchars($hostName) . '</strong>';
        
        // Add company name if provided
        if ($companyName) {
            $html .= ' from <strong>' . htmlspecialchars($companyName) . '</strong>';
        }
        
        $html .= ' has been confirmed.</p>
        
        <div class="success">
            <div class="success-title">✅ Meeting Confirmed</div>
            Your booking has been approved and calendar invitations have been sent.
        </div>
        
        <div class="details">'
            . EmailStyles::renderDetailRow('Meeting', $eventName)
            . EmailStyles::renderDetailRow('Host', $hostName)
            . EmailStyles::renderDetailRow('Date', $eventDate)
            . EmailStyles::renderDetailRow('Time', $eventTime)
            . EmailStyles::renderDetailRow('Duration', $duration)
            . EmailStyles::renderDetailRow('Location', $location);
        
        // Add meeting link if provided
        if ($meetingLink) {
            $html .= EmailStyles::renderDetailRow('Meeting Link', $meetingLink, true);
        }
        
        $html .= '
        </div>';
        
        // Action buttons
        if ($manageLink !== '#') {
            $html .= '
            <div class="center">
                <a href="' . htmlspecialchars($manageLink) . '"
                class="button">Manage Booking</a>
            </div>
            
            <p class="small center" style="margin-top: 16px; color: #666;">
                Need to reschedule or cancel? Use the link above.
            </p>';
        }
        
        // Secondary actions
        $html .= '
        <div class="center" style="margin-top: 15px;">';
        
        if ($rescheduleLink !== '#') {
            $html .= '
            <a href="' . htmlspecialchars($rescheduleLink) . '" class="btn btn-secondary" style="margin-right: 10px;">
                Reschedule
            </a>';
        }
        
        if ($cancelLink !== '#') {
            $html .= '
            <a href="' . htmlspecialchars($cancelLink) . '" class="btn btn-cancel">
                Cancel
            </a>';
        }
        
        $html .= '
        </div>';
        
        // Footer note
        $html .= '
        <div class="footer-note">
            <div class="footer-note-title">What\'s next:</div>
            • You\'ll receive a reminder before the meeting<br>
            • Calendar invitation has been sent to your email<br>';
        
        if ($meetingLink) {
            $html .= '• Use the meeting link above to join<br>';
        }
        
        $html .= '• Contact ' . htmlspecialchars($hostName) . ' if you need to make changes
        </div>';
        
        $html .= EmailStyles::getFooter() . '
    </div>
</body>
</html>';

        return $html;
    }
}