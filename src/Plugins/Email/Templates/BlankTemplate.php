<?php
// Path: src/Plugins/Email/Templates/BlankTemplate.php

namespace App\Plugins\Email\Templates;

class BlankTemplate
{
    public static function render(array $data): string
    {
        // Extract variables with defaults
        $content = $data['content'] ?? '';
        $subject = $data['subject'] ?? 'Newsletter';
        $appName = $data['app_name'] ?? 'Skedi';
        $appUrl = $data['app_url'] ?? 'https://skedi.com';
        $showHeader = $data['show_header'] ?? true;
        $showFooter = $data['show_footer'] ?? true;
        $headerTitle = $data['header_title'] ?? $subject;
        $headerEmoji = $data['header_emoji'] ?? '📧';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $subject . '</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">';
        
        // Optional header
        if ($showHeader) {
            $html .= EmailStyles::getHeader($headerTitle, $headerEmoji);
        }
        
        $html .= '
        <div class="content">
            ' . $content . '
        </div>';
        
        // Optional footer
        if ($showFooter) {
            $html .= EmailStyles::getFooter($appName, $appUrl);
        }
        
        $html .= '
    </div>
</body>
</html>';
        
        return $html;
    }
}