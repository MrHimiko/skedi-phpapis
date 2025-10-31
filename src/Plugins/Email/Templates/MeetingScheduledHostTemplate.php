<?php
// src/Plugins/Email/Templates/MeetingScheduledHostTemplate.php
// Replace the render method with this debug version

namespace App\Plugins\Email\Templates;

class MeetingScheduledHostTemplate
{
    public static function render(array $data): string
    {

        
        // Extract variables with defaults
        $hostName = $data['host_name'] ?? 'Host';
        $guestName = $data['guest_name'] ?? 'Guest';
        $guestEmail = $data['guest_email'] ?? '';
        $guestPhone = $data['guest_phone'] ?? '';
        $meetingName = $data['meeting_name'] ?? 'Meeting';
        $meetingDate = $data['meeting_date'] ?? $data['date'] ?? 'TBD';
        $meetingTime = $data['meeting_time'] ?? $data['time'] ?? 'TBD';
        $meetingDuration = $data['meeting_duration'] ?? $data['duration'] ?? '30 minutes';
        $meetingLocation = $data['meeting_location'] ?? $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $guestMessage = $data['guest_message'] ?? '';
        
        // Check if booking is pending approval
        $meetingStatus = $data['meeting_status'] ?? 'confirmed';
        $bookingId = $data['booking_id'] ?? '';
        $organizationId = $data['organization_id'] ?? '';
        

        
        // Custom fields from booking form
        $customFields = $data['custom_fields'] ?? [];
        
        // Determine title based on status
        $title = $meetingStatus === 'pending' ? 'Approval Required' : 'New Meeting Booked';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Notification</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader($title);


        $html .= '<p class="greeting">Hi ' . htmlspecialchars($hostName) . ',</p>';
            
        // Different message based on status  
        if ($meetingStatus === 'pending') {
            $html .= '
            <p class="message">
                <strong>' . htmlspecialchars($guestName) . '</strong> has requested a meeting with you and needs your approval.
            </p>
            
            <div class="alert">
                <div class="alert-title">Action Required</div>
                Please approve or decline this booking request using the buttons below.
            </div>';
        } else {
            $html .= '
            <p class="message">
                <strong>' . htmlspecialchars($guestName) . '</strong> has booked a meeting with you.
            </p>
            
            <div class="success">
                <div class="success-title">New Booking Confirmed</div>
                The meeting has been added to your calendar.
            </div>';
        }
            
        $html .= '
        <div class="details">'
            . EmailStyles::renderDetailRow('Meeting', $meetingName)
            . EmailStyles::renderDetailRow('Guest Name', $guestName);
        
        if ($guestEmail) {
            $html .= EmailStyles::renderDetailRow('Guest Email', $guestEmail, true);
        }
        
        if ($guestPhone) {
            $html .= EmailStyles::renderDetailRow('Guest Phone', $guestPhone);
        }
        
        $html .= EmailStyles::renderDetailRow('Date', $meetingDate)
            . EmailStyles::renderDetailRow('Time', $meetingTime)
            . EmailStyles::renderDetailRow('Duration', $meetingDuration)
            . EmailStyles::renderDetailRow('Location', $meetingLocation);
        
        if ($meetingLink) {
            $html .= EmailStyles::renderDetailRow('Meeting Link', $meetingLink, true);
        }
        
        // Add custom fields if any
        foreach ($customFields as $fieldName => $fieldValue) {
            if ($fieldValue && is_string($fieldValue)) {
                $html .= EmailStyles::renderDetailRow(
                    ucfirst(str_replace('_', ' ', $fieldName)), 
                    $fieldValue
                );
            }
        }
        
        $html .= '
        </div>';
        
        // Show guest message if provided
        if ($guestMessage) {
            $html .= '
            <div class="alert">
                <div class="alert-title">Message from ' . htmlspecialchars($guestName) . '</div>
                ' . nl2br(htmlspecialchars($guestMessage)) . '
            </div>';
        }
        
        // Action buttons for pending bookings
        if ($meetingStatus === 'pending' && $bookingId && $organizationId) {
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            
            $html .= '
            <div class="buttons">
                <a href="' . htmlspecialchars($frontendUrl . '/booking/' . $bookingId . '?action=approve&organization_id=' . $organizationId) . '" 
                   class="btn btn-approve">
                    Approve Booking
                </a>
                <a href="' . htmlspecialchars($frontendUrl . '/booking/' . $bookingId . '?action=decline&organization_id=' . $organizationId) . '" 
                   class="btn btn-decline">
                    Decline Booking
                </a>
            </div>
            
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($frontendUrl . '/booking/' . $bookingId . '?organization_id=' . $organizationId) . '" 
                   class="btn btn-view">
                    View Full Details
                </a>
            </div>';
        }
        
        // Footer note based on status
        if ($meetingStatus === 'pending') {
            $html .= '
            <div class="footer-note">
                <div class="footer-note-title">What happens next:</div>
                • Click "Approve" to confirm this booking and send calendar invitations<br>
                • Click "Decline" to reject this request<br>
                • ' . htmlspecialchars($guestName) . ' will be notified of your decision via email
            </div>';
        } else {
            $html .= '
            <div class="footer-note">
                <div class="footer-note-title">Quick Actions:</div>
                • Reply to this email to contact ' . htmlspecialchars($guestName) . '<br>
                • Calendar invitation has been sent automatically<br>
                • All meeting details are included above
            </div>';
        }
        
        $html .= EmailStyles::getFooter() . '
    </div>
</body>
</html>';

        return $html;
    }
}