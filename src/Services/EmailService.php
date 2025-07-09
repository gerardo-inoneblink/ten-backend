<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;
use Resend\Exceptions\ResendException;

class EmailService
{
    private \Resend\Client $resend;
    private Logger $logger;
    private AppConfig $config;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->config = AppConfig::getInstance();

        $apiKey = $this->config->get('RESEND_API_KEY');
        if (!$apiKey) {
            throw new \Exception('RESEND_API_KEY is not configured');
        }

        $this->resend = \Resend::client($apiKey);
    }

    public function sendOtpEmail(string $email, string $otp, array $client = []): bool
    {
        try {
            $clientName = trim(($client['FirstName'] ?? '') . ' ' . ($client['LastName'] ?? ''));
            $subject = 'FlexKit Verification Code';

            $htmlContent = $this->loadEmailTemplate('otp_email.html');
            $htmlContent = str_replace('{{OTP_CODE}}', $otp, $htmlContent);

            $textContent = $this->createPlainTextVersion($clientName, $otp);

            $fromEmail = $this->config->get('RESEND_FROM_EMAIL');
            $fromName = $this->config->get('RESEND_FROM_NAME');

            $params = [
                'from' => $fromName . ' <' . $fromEmail . '>',
                'to' => [$email],
                'subject' => $subject,
                'html' => $htmlContent,
                'text' => $textContent
            ];

            $environment = $this->config->get('APP_ENV', 'production');

            $response = $this->resend->emails->send($params);

            $this->logger->logOtpOperation("OTP email sent successfully via Resend", [
                'email' => $email,
                'resend_id' => $response->id ?? 'unknown',
                'subject' => $subject
            ]);

            return true;

        } catch (ResendException $e) {
            $this->logger->error("Resend email sending failed", [
                'email' => $email,
                'error' => $e->getMessage(),
                'resend_error' => $e->getMessage()
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Email sending failed", [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function loadEmailTemplate(string $templateName): string
    {
        $templatePath = __DIR__ . '/../../templates/' . $templateName;

        if (!file_exists($templatePath)) {
            throw new \Exception("Email template not found: {$templatePath}");
        }

        return file_get_contents($templatePath);
    }

    private function createPlainTextVersion(string $clientName, string $otp): string
    {
        $name = $clientName ?: 'there';

        $text = "Hi {$name},\n\n";
        $text .= "Please use the following code to authenticate your account. ";
        $text .= "If you did not request this code, please disregard this email. ";
        $text .= "Never give this code to anyone, including FlexKit employees.\n\n";
        $text .= "Code: {$otp}\n\n";
        $text .= "This code will expire in 10 minutes.\n\n";
        $text .= "Powered by FlexKit\n\n";
        $text .= "Find out more about the services we offer at Ten including ";
        $text .= "Reformer Pilates, Physio, Personal Training, Massage and Clinical Exercise.\n\n";
        $text .= "© Ten Health & Fitness";

        return $text;
    }
}
