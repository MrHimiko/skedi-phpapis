<?php
// Path: src/Plugins/Email/Templates/EmailStyles.php

namespace App\Plugins\Email\Templates;

class EmailStyles
{
    public static function getStyles(): string
    {
        return '
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Roboto", "Helvetica Neue", Arial, sans-serif;
                background-color: #f5f5f5;
                color: #333;
                line-height: 1.6;
            }
            .container {
                max-width: 600px;
                margin: 40px auto;
                background-color: #ffffff;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
            .header {
                background-color: #2b88ef;
                padding: 30px;
                text-align: center;
            }
            .logo {
                color: #ffffff;
                font-size: 24px;
                font-weight: 700;
                margin: 0;
            }
            .content {
                padding: 40px 30px;
            }
            .title {
                font-size: 24px;
                font-weight: 600;
                margin: 0 0 20px 0;
                color: #000000;
            }
            .message {
                font-size: 16px;
                margin: 0 0 30px 0;
                color: #666;
            }
            .alert {
                background-color: #ffde0e;
                border-radius: 6px;
                padding: 20px;
                margin: 30px 0;
                color: #000;
            }
            .alert-title {
                font-weight: 600;
                margin: 0 0 8px 0;
            }
            .success {
                background-color: #f0f9ff;
                border-left: 4px solid #2b88ef;
                border-radius: 6px;
                padding: 20px;
                margin: 30px 0;
            }
            .success-title {
                font-weight: 600;
                margin: 0 0 8px 0;
                color: #2b88ef;
            }
            .details {
                background-color: #f9f9f9;
                border-radius: 6px;
                padding: 25px;
                margin: 30px 0;
            }
            .detail-row {
                display: flex;
                margin: 12px 0;
                align-items: flex-start;
            }
            .detail-label {
                font-weight: 600;
                min-width: 120px;
                color: #000;
                margin-right: 15px;
            }
            .detail-value {
                color: #666;
                flex: 1;
            }
            .detail-value a {
                color: #2b88ef;
                text-decoration: none;
            }
            .buttons {
                text-align: center;
                margin: 40px 0;
            }
            .btn {
                display: inline-block;
                padding: 14px 28px;
                margin: 8px 10px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                font-size: 16px;
                transition: all 0.2s ease;
            }
            .btn-approve {
                background-color: #000000;
                color: #ffffff;
            }
            .btn-decline {
                background-color: #ffffff;
                color: #000000;
                border: 2px solid #000000;
            }
            .btn-primary {
                background-color: #2b88ef;
                color: #ffffff;
            }
            .btn-secondary {
                background-color: #ffffff;
                color: #000000;
                border: 2px solid #000000;
            }
            .btn-view {
                background-color: #f5f5f5;
                color: #000000;
                font-size: 14px;
                padding: 12px 20px;
                margin-top: 15px;
            }
            .footer-note {
                background-color: #f9f9f9;
                border-radius: 6px;
                padding: 20px;
                margin: 30px 0 0 0;
                font-size: 14px;
                color: #666;
            }
            .footer-note-title {
                font-weight: 600;
                color: #000;
                margin: 0 0 10px 0;
            }
            .greeting {
                font-size: 18px;
                margin: 0 0 20px 0;
                color: #000;
            }
            @media (max-width: 600px) {
                .container {
                    margin: 20px;
                    border-radius: 0;
                }
                .content {
                    padding: 30px 20px;
                }
                .btn {
                    display: block;
                    margin: 10px 0;
                }
                .detail-row {
                    flex-direction: column;
                }
                .detail-label {
                    min-width: auto;
                    margin: 0 0 5px 0;
                }
            }
        </style>';
    }
    
    public static function getHeader(string $title): string
    {
        return '
        <div class="header">
            <h1 class="logo">Skedi</h1>
        </div>
        <div class="content">
            <h2 class="title">' . htmlspecialchars($title) . '</h2>';
    }
    
    public static function getFooter(): string
    {
        return '
        </div>'; // Close content div from header
    }
    
    public static function renderDetailRow(string $label, string $value, bool $isLink = false): string
    {
        $valueHtml = $isLink ? '<a href="' . htmlspecialchars($value) . '">' . htmlspecialchars($value) . '</a>' : htmlspecialchars($value);
        
        return '
        <div class="detail-row">
            <div class="detail-label">' . htmlspecialchars($label) . ':</div>
            <div class="detail-value">' . $valueHtml . '</div>
        </div>';
    }
}