<?php

namespace App\Plugins\Email\Templates;

class WelcomeTemplate
{
    public static function render(array $data): string
    {
        $userName = $data['user_name'] ?? 'User';
        $appName = $data['app_name'] ?? 'Skedi';
        $appUrl = $data['app_url'] ?? 'https://skedi.com';
        $currentYear = $data['current_year'] ?? date('Y');
        $dashboardUrl = $appUrl . '/dashboard';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {$appName}</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table cellpadding="0" cellspacing="0" border="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="padding: 40px 30px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <h1 style="color: #ffffff; margin: 0; font-size: 28px;">Welcome to {$appName}!</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="color: #333333; margin: 0 0 20px 0; font-size: 24px;">Your Email is Verified!</h2>
                            <p style="color: #666666; font-size: 16px; line-height: 1.5; margin: 0 0 20px 0;">
                                Hi {$userName},
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 1.5; margin: 0 0 30px 0;">
                                Thank you for verifying your email address! Your account is now fully activated and you can access all features of {$appName}.
                            </p>
                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td align="center" style="padding: 20px 0;">
                                        <a href="{$dashboardUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; padding: 14px 30px; border-radius: 5px; font-size: 16px; font-weight: bold; display: inline-block;">
                                            Go to Dashboard
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <div style="border-top: 1px solid #eeeeee; margin: 30px 0; padding-top: 20px;">
                                <h3 style="color: #333333; margin: 0 0 15px 0; font-size: 18px;">Getting Started</h3>
                                <ul style="color: #666666; font-size: 14px; line-height: 1.8; margin: 0; padding-left: 20px;">
                                    <li>Set up your availability schedule</li>
                                    <li>Create your first event type</li>
                                    <li>Share your booking link</li>
                                    <li>Integrate your calendar</li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 30px; background-color: #f8f8f8; text-align: center;">
                            <p style="color: #999999; font-size: 12px; margin: 0;">
                                © {$currentYear} {$appName}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}