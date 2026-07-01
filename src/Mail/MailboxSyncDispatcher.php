<?php

declare(strict_types=1);

namespace App\Mail;

use App\Gmail\GmailApiSyncService;
use App\Imap\ImapSyncService;
use App\Settings\SettingsService;

/**
 * Eldönti, honnan szinkronizáljuk egy iroda beérkező leveleit: ha van Gmail
 * OAuth-kapcsolat → Gmail API; különben ha van IMAP-host → IMAP; egyébként semmi.
 */
final class MailboxSyncDispatcher
{
    public function __construct(
        private SettingsService $settings,
        private ImapSyncService $imap,
        private GmailApiSyncService $gmail,
    ) {
    }

    public function isConfigured(int $officeId): bool
    {
        return $this->hasGmail($officeId)
            || ((string) $this->settings->get($officeId, 'imap_host', '')) !== '';
    }

    /** @return array{count:int, error:?string, via:string} */
    public function sync(int $officeId, int $limit = 25): array
    {
        if ($this->hasGmail($officeId)) {
            return $this->gmail->syncOffice($officeId, $limit) + ['via' => 'gmail'];
        }
        if (((string) $this->settings->get($officeId, 'imap_host', '')) !== '') {
            return $this->imap->syncOffice($officeId, $limit) + ['via' => 'imap'];
        }

        return ['count' => 0, 'error' => 'Nincs postafiók beállítva.', 'via' => 'none'];
    }

    private function hasGmail(int $officeId): bool
    {
        return ((string) $this->settings->get($officeId, 'google_refresh_token', '')) !== '';
    }
}
