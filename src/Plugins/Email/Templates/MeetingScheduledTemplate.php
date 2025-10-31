<?php
// Path: src/Plugins/Email/Templates/MeetingScheduledTemplate.php

namespace App\Plugins\Email\Templates;

class MeetingScheduledTemplate
{
    public static function render(array $data): string
    {
        // Extract variables with defaults
        $guestName = $data['guest_name'] ?? 'Guest';
        $meetingName = $data['meeting_name'] ?? 'Meeting';
        $meetingDate = $data['meeting_date'] ?? $data['date'] ?? 'TBD';
        $meetingTime = $data['meeting_time'] ?? $data['time'] ?? 'TBD';
        $meetingDuration = $data['meeting_duration'] ?? $data['duration'] ?? '30 minutes';
        $meetingLocation = $data['meeting_location'] ?? $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $organizerName = $data['host_name'] ?? 'Host';
        $companyName = $data['company_name'] ?? '';
        $rescheduleLink = $data['reschedule_link'] ?? '#';
        $calendarLink = $data['calendar_link'] ?? $rescheduleLink;
        
        // Check if booking is pending approval
        $meetingStatus = $data['meeting_status'] ?? 'confirmed';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Notification</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">';

        // Different header based on status
        if ($meetingStatus === 'pending') {
            $html .= EmailStyles::getHeader('Booking Request Sent');
        } else {
            $html .= EmailStyles::getHeader('Meeting Scheduled');
        }

        $html .= '
        <div class="content">
            <p class="greeting">Hi ' . htmlspecialchars($guestName) . ',</p>
            
            <p class="message">Your meeting with <strong>' . htmlspecialchars($organizerName) . '</strong>';
            
        // Add company name if provided
        if ($companyName) {
            $html .= ' from <strong>' . htmlspecialchars($companyName) . '</strong>';
        }
        
        // Different message based on status
        if ($meetingStatus === 'pending') {
            $html .= ' is pending approval.</p>
            
            <div class="alert">
                <div class="alert-title">Awaiting Approval</div>
                Your booking request has been sent to ' . htmlspecialchars($organizerName) . '. You\'ll receive a confirmation email once approved.
            </div>';
        } else {
            $html .= ' has been successfully scheduled.</p>
            
            <div class="success">
                <div class="success-title">Meeting Confirmed</div>
                We\'ve sent calendar invitations to all participants.
            </div>';
        }
        
        $html .= '
        <div class="details">'
            . EmailStyles::renderDetailRow('Meeting', $meetingName)
            . EmailStyles::renderDetailRow('Date', $meetingDate)
            . EmailStyles::renderDetailRow('Time', $meetingTime)
            . EmailStyles::renderDetailRow('Duration', $meetingDuration)
            . EmailStyles::renderDetailRow('Location', $meetingLocation);
                
        // Add meeting link if provided
        if ($meetingLink) {
            $html .= EmailStyles::renderDetailRow('Meeting Link', $meetingLink, true);
        }
        
        $html .= '
        </div>';
        
        // Different action buttons based on status
        if ($meetingStatus === 'pending') {
            $html .= '
            <div class="center">
                <p class="message">We\'ll notify you as soon as your booking is approved or if any changes are needed.</p>
            </div>';
        } else {
            $html .= '
            <div class="center">
                <a href="' . htmlspecialchars($calendarLink) . '" class="btn btn-primary">Add to Calendar</a>';
            
            if ($rescheduleLink !== '#') {
                $html .= '
                <br><br>
                <a href="' . htmlspecialchars($rescheduleLink) . '" class="btn btn-secondary">Reschedule</a>';
            }
            
            $html .= '
            </div>';
        }
        
        // Footer note based on status
        if ($meetingStatus === 'pending') {
            $html .= '
            <div class="footer-note">
                <div class="footer-note-title">What happens next:</div>
                • ' . htmlspecialchars($organizerName) . ' will review your booking request<br>
                • You\'ll receive an email confirmation once approved<br>
                • If approved, calendar invitations will be sent automatically
            </div>';
        } else {
            $html .= '
            <div class="footer-note">
                <div class="footer-note-title">What\'s next:</div>
                • You\'ll receive a reminder before the meeting<br>
                • Join using the meeting link above<br>
                • Contact ' . htmlspecialchars($organizerName) . ' if you need to reschedule
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