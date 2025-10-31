<?php
// Path: src/Plugins/Email/Templates/InvitationTemplate.php

namespace App\Plugins\Email\Templates;

class InvitationTemplate
{
    public static function render(array $data): string
    {
        // Extract variables
        $invitedByName = $data['invited_by_name'] ?? 'A team member';
        $invitedByEmail = $data['invited_by_email'] ?? '';
        $organizationName = $data['organization_name'] ?? 'Organization';
        $teamName = $data['team_name'] ?? '';
        $role = $data['role'] ?? 'member';
        $actionUrl = $data['action_url'] ?? '#';
        $message = $data['message'] ?? '';
        
        // Determine what they're being invited to
        $invitationTarget = $teamName 
            ? $teamName . ' team in ' . $organizationName
            : $organizationName;
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You\'re Invited!</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('You\'re Invited!', '🎊') . '
        <div class="content">
            <p class="greeting">Hello,</p>
            
            <p class="message">
                <strong>' . $invitedByName . '</strong>' . 
                ($invitedByEmail ? ' (' . $invitedByEmail . ')' : '') . 
                ' has invited you to join <strong>' . $invitationTarget . '</strong> as a <strong>' . $role . '</strong>.
            </p>';
        
        if ($message) {
            $html .= '
            <div class="alert">
                <strong>Personal message:</strong><br>
                <div style="margin-top: 10px;">
                    ' . nl2br(htmlspecialchars($message)) . '
                </div>
            </div>';
        }
        
        $html .= '
            <div class="details">
                <div class="detail-row">
                    <strong>Organization:</strong> ' . $organizationName . '
                </div>';
        
        if ($teamName) {
            $html .= '
                <div class="detail-row">
                    <strong>Team:</strong> ' . $teamName . '
                </div>';
        }
        
        $html .= '
                <div class="detail-row">
                    <strong>Your Role:</strong> ' . ucfirst($role) . '
                </div>
                <div class="detail-row">
                    <strong>Invited by:</strong> ' . $invitedByName . '
                </div>
            </div>
            
            <div class="center">
                <a href="' . $actionUrl . '" class="button">Accept Invitation</a>
                <p style="margin-top: 20px; color: #718096; font-size: 14px;">
                    This invitation will expire in 7 days.
                </p>
            </div>'
            . EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}