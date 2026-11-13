<?php

/**
 * StockSense MailClient.
 * 
 * Thin, secure wrapper around PHPMailer.
 * Loads SMTP credentials from C:\xampp\StockSense_private\mail.json.
 * Strict timeout: 15 seconds.
 * Zero hardcoded secrets, zero secrets in log outputs.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class MailClient {

    const DEFAULT_CONFIG_PATH = 'C:\\xampp\\StockSense_private\\mail.json';
    const TIMEOUT_SECONDS = 15;

    /**
     * Optional mock handler for tests.
     * signature: function(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): array
     * @var callable|null
     */
    private static $mockSender = null;

    /**
     * Recorded sent emails during mock testing.
     * @var array
     */
    public static $sentHistory = [];

    /**
     * Path to config file, can be overridden in tests.
     * @var string|null
     */
    private static $configPathOverride = null;

    /**
     * Set a mock sender for unit / integration tests.
     */
    public static function setMockSender(?callable $mockSender): void {
        self::$mockSender = $mockSender;
    }

    /**
     * Override configuration path (useful for testing missing config).
     */
    public static function setConfigPathOverride(?string $path): void {
        self::$configPathOverride = $path;
    }

    /**
     * Clear recorded sent emails.
     */
    public static function clearSentHistory(): void {
        self::$sentHistory = [];
    }

    /**
     * Send an email with HTML and plain-text fallback.
     *
     * @param string $toEmail Recipient email address
     * @param string $toName Recipient display name
     * @param string $subject Email subject line
     * @param string $htmlBody Responsive HTML body
     * @param string $textBody Plain-text fallback
     * @return array ['status' => bool, 'message' => string, 'error' => string|null]
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): array {
        // 1. Validate email address format
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $safeError = "Invalid recipient email address format.";
            error_log("MailClient error: " . $safeError);
            return [
                'status' => false,
                'message' => $safeError,
                'error' => $safeError
            ];
        }

        // 2. Derive plain text fallback if empty
        if (trim($textBody) === '') {
            $textBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));
        }

        // 3. Check for mock sender (used during automated test runs)
        if (self::$mockSender !== null) {
            $record = [
                'to_email' => $toEmail,
                'to_name' => $toName,
                'subject' => $subject,
                'html_body' => $htmlBody,
                'text_body' => $textBody,
                'sent_at' => date('Y-m-d H:i:s')
            ];
            self::$sentHistory[] = $record;

            $mockResult = call_user_func(self::$mockSender, $toEmail, $toName, $subject, $htmlBody, $textBody);
            if (is_array($mockResult)) {
                return $mockResult;
            }
            return [
                'status' => true,
                'message' => 'Email sent via mock mailer.',
                'error' => null
            ];
        }

        // 4. Load SMTP credentials from private file
        $configPath = self::$configPathOverride ?: self::DEFAULT_CONFIG_PATH;
        if (!file_exists($configPath) || !is_readable($configPath)) {
            $safeError = "SMTP configuration file missing or unreadable.";
            error_log("MailClient error: SMTP configuration file not accessible at " . $configPath);
            return [
                'status' => false,
                'message' => $safeError,
                'error' => $safeError
            ];
        }

        $rawJson = @file_get_contents($configPath);
        $config = json_decode($rawJson, true);
        if (!is_array($config)) {
            $safeError = "SMTP configuration file contains invalid JSON.";
            error_log("MailClient error: Failed to parse JSON in SMTP configuration file.");
            return [
                'status' => false,
                'message' => $safeError,
                'error' => $safeError
            ];
        }

        $host = trim($config['host'] ?? '');
        $port = (int)($config['port'] ?? 587);
        $username = trim($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        $fromName = trim($config['from_name'] ?? 'StockSense');
        $fromEmail = !empty($config['from_email']) ? trim($config['from_email']) : $username;

        if ($host === '' || $username === '' || $password === '') {
            $safeError = "Incomplete SMTP configuration settings.";
            error_log("MailClient error: " . $safeError);
            return [
                'status' => false,
                'message' => $safeError,
                'error' => $safeError
            ];
        }

        // 5. Instantiate and configure PHPMailer
        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->CharSet = 'UTF-8';

            // Encryption negotiation
            $enc = strtolower($config['encryption'] ?? $config['secure'] ?? '');
            if ($port === 465 || $enc === 'ssl' || $enc === 'smtps') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Sender and Recipient
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();

            return [
                'status' => true,
                'message' => 'Email sent successfully.',
                'error' => null
            ];

        } catch (Throwable $e) {
            // Scrub any potential passwords or secret substrings from error logs
            $rawMsg = $e->getMessage();
            $sanitized = preg_replace('/(password|pass|pwd|key)[:=][^\s,;]+/i', '$1=***', $rawMsg);
            if ($password !== '') {
                $sanitized = str_replace($password, '***', $sanitized);
            }

            $maskedRecipient = substr($toEmail, 0, 3) . '***@' . (explode('@', $toEmail)[1] ?? 'domain');
            error_log("MailClient error sending to {$maskedRecipient}: " . $sanitized);

            return [
                'status' => false,
                'message' => 'Failed to deliver email.',
                'error' => $sanitized
            ];
        }
    }
}

