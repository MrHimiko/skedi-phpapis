<?php
// Path: src/Plugins/Email/Templates/BookingCreatedTemplate.php

namespace App\Plugins\Email\Templates;

class BookingCreatedTemplate
{
    public static function render(array $data): string
    {
        // Extract variables
        $hostName = $data['host_name'] ?? 'Host';
        $guestName = $data['guest_name'] ?? 'Guest';
        $guestEmail = $data['guest_email'] ?? '';
        $eventName = $data['event_name'] ?? 'Event';
        $eventDate = $data['event_date'] ?? 'TBD';
        $eventTime = $data['event_time'] ?? 'TBD';
        $duration = $data['duration'] ?? '30 minutes';
        $location = $data['location'] ?? 'Online';
        $bookingUrl = $data['booking_url'] ?? '#';
        $guestMessage = $data['guest_message'] ?? '';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking Created</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('New Booking Created!', '🎉') . '
        <div class="content">
            <p class="greeting">Hi ' . $hostName . ',</p>
            
            <p class="message">Great news! You have a new booking.</p>
            
            <div class="details">
                <div class="detail-row">
                    <strong>Guest:</strong> ' . $guestName . ($guestEmail ? ' (' . $guestEmail . ')' : '') . '
                </div>
                <div class="detail-row">
                    <strong>Event:</strong> ' . $eventName . '
                </div>
                <div class="detail-row">
                    <strong>Date:</strong> ' . $eventDate . '
                </div>
                <div class="detail-row">
                    <strong>Time:</strong> ' . $eventTime . '
                </div>
                <div class="detail-row">
                    <strong>Duration:</strong> ' . $duration . '
                </div>
                <div class="detail-row">
                    <strong>Location:</strong> ' . $location . '
                </div>';
        
        if ($guestMessage) {
            $html .= '
                <div class="detail-row">
                    <strong>Message from guest:</strong><br>
                    <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px;">
                        ' . nl2br(htmlspecialchars($guestMessage)) . '
                    </div>
                </div>';
        }
        
        $html .= '
            </div>
            
            <div class="center">
                <a href="' . $bookingUrl . '" class="button">View Booking Details</a>
            </div>'
            . EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}