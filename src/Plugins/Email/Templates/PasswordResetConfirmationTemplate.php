<?php

namespace App\Plugins\Email\Templates;

class PasswordResetConfirmationTemplate
{
    public static function render(array $data): string
    {
        $userName = $data['user_name'] ?? 'User';
        $appName = $data['app_name'] ?? 'Skedi';
        $appUrl = $data['app_url'] ?? 'https://skedi.com';
        $currentYear = $data['current_year'] ?? date('Y');
        $loginUrl = $appUrl . '/account/login';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table cellpadding="0" cellspacing="0" border="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="padding: 40px 30px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <h1 style="color: #ffffff; margin: 0; font-size: 28px;">{$appName}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="color: #333333; margin: 0 0 20px 0; font-size: 24px;">Password Reset Successful</h2>
                            <p style="color: #666666; font-size: 16px; line-height: 1.5; margin: 0 0 20px 0;">
                                Hi {$userName},
                            </p>
                            <p style="color: #666666; font-size: 16px; line-height: 1.5; margin: 0 0 30px 0;">
                                Your password has been successfully reset. You can now log in with your new password.
                            </p>
                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td align="center" style="padding: 20px 0;">
                                        <a href="{$loginUrl}" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; padding: 14px 30px; border-radius: 5px; font-size: 16px; font-weight: bold; display: inline-block;">
                                            Login to Your Account
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="color: #999999; font-size: 14px; line-height: 1.5; margin: 20px 0;">
                                If you didn't make this change, please contact our support team immediately.
                            </p>
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