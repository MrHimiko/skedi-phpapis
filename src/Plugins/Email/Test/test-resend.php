<?php
// Path: src/Plugins/Email/Test/test-resend.php
// Run this file from your project root: php src/Plugins/Email/Test/test-resend.php

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../../../../.env');

// Test 1: Basic API connection
echo "Testing Resend API connection...\n";

try {
    $resend = \Resend::client($_ENV['RESEND_API_KEY']);
    
    // Test 2: Send a simple test email
    echo "Sending test email...\n";
    
    $result = $resend->emails->send([
        'from' => $_ENV['DEFAULT_FROM_NAME'] . ' <' . $_ENV['DEFAULT_FROM_EMAIL'] . '>',
        'to' => ['your-test-email@example.com'], // CHANGE THIS TO YOUR EMAIL
        'subject' => 'Test Email from Skedi (Resend Integration)',
        'html' => '<h1>Test Email</h1><p>If you see this, Resend is working!</p>',
        'text' => 'Test Email - If you see this, Resend is working!',
    ]);
    
    echo "✅ Email sent successfully!\n";
    echo "Message ID: " . $result->id . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// Test 3: Test with your EmailService
echo "\nTesting with EmailService wrapper...\n";

try {
    // This would be injected normally, but for testing:
    $logger = new \Psr\Log\NullLogger();
    
    $resendProvider = new \App\Plugins\Email\Service\Providers\ResendProvider(
        $_ENV['RESEND_API_KEY'],
        $logger,
        []
    );
    
    // Send using the provider
    $result = $resendProvider->send(
        'your-test-email@example.com', // CHANGE THIS
        'blank',
        [
            'subject' => 'Test from Skedi EmailService',
            'content' => '<h2>This is a test</h2><p>Testing the EmailService with ResendProvider.</p>',
            'app_name' => 'Skedi',
            'app_url' => 'https://skedi.com',
            'current_year' => date('Y')
        ]
    );
    
    echo "✅ EmailService test successful!\n";
    echo "Message ID: " . $result['message_id'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// Test 4: Test WYSIWYG content
echo "\nTesting WYSIWYG content email...\n";

try {
    $wysiwygContent = '
        <h1 style="color: #333;">Newsletter Title</h1>
        <p>This is content from a WYSIWYG editor.</p>
        <ul>
            <li>Feature 1</li>
            <li>Feature 2</li>
            <li>Feature 3</li>
        </ul>
        <p><a href="https://skedi.com" style="background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Call to Action</a></p>
    ';
    
    $result = $resendProvider->send(
        'your-test-email@example.com', // CHANGE THIS
        'blank',
        [
            'subject' => 'Newsletter from Skedi',
            'content' => $wysiwygContent,
            'app_name' => 'Skedi',
            'app_url' => 'https://skedi.com',
            'current_year' => date('Y')
        ]
    );
    
    echo "✅ WYSIWYG email sent successfully!\n";
    echo "Message ID: " . $result['message_id'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n🎉 All tests completed!\n";