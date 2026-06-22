<?php

declare(strict_types=1);

namespace App\Mail;

use App\Settings\SettingsService;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * E-mail küldés az iroda saját SMTP-beállításaival (titkosítva tárolva).
 */
final class MailService
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function isConfigured(int $officeId): bool
    {
        return (string) $this->settings->get($officeId, 'smtp_host', '') !== '';
    }

    /**
     * @param string|list<string> $to
     * @param list<string> $attachmentPaths
     */
    public function send(int $officeId, string|array $to, string $subject, string $bodyHtml, array $attachmentPaths = []): void
    {
        $host = (string) $this->settings->get($officeId, 'smtp_host', '');
        if ($host === '') {
            throw new RuntimeException('Az iroda SMTP-beállításai hiányoznak (Beállítások).');
        }
        $port = (int) ($this->settings->get($officeId, 'smtp_port', '587') ?? '587');
        $user = (string) $this->settings->get($officeId, 'smtp_user', '');
        $pass = (string) $this->settings->get($officeId, 'smtp_password', '');
        $fromEmail = (string) $this->settings->get($officeId, 'smtp_from_email', $user);
        $fromName = (string) $this->settings->get($officeId, 'smtp_from_name', 'AegisCRM');

        $transport = new EsmtpTransport($host, $port, $port === 465);
        if ($user !== '') {
            $transport->setUsername($user);
        }
        if ($pass !== '') {
            $transport->setPassword($pass);
        }
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail ?: 'no-reply@example.com'))
            ->subject($subject)
            ->html($bodyHtml)
            ->text(strip_tags($bodyHtml));

        foreach ((array) $to as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $email->addTo($recipient);
            }
        }
        foreach ($attachmentPaths as $path) {
            if (is_file($path)) {
                $email->attachFromPath($path);
            }
        }

        $mailer->send($email);
    }
}
