<?php

namespace Headwires\TranslatorClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Headwires\TranslatorClient\TranslatorClientService;

class StatusCommand extends Command
{
    protected $signature = 'translator:status';

    protected $description = 'Show translation sync status';

    public function handle(TranslatorClientService $service): int
    {
        $this->info('Translation Sync Status');
        $this->newLine();

        // API Key
        $apiKey = config('translator-client.api_key');
        $maskedKey = $this->maskApiKey($apiKey);
        $this->line("API Key: <fg=yellow>{$maskedKey}</>");

        // Last sync info
        $lastSync = $service->getLastSync();

        if ($lastSync) {
            $syncedAt = Carbon::parse($lastSync['synced_at']);
            $this->line("Last Sync: <fg=green>{$syncedAt->diffForHumans()}</>");
            $this->line("Version: <fg=cyan>{$lastSync['version']}</>");
            $this->line("Locales: <fg=cyan>" . implode(', ', $lastSync['locales']) . "</>");
        } else {
            $this->line("Last Sync: <fg=red>Never synced</>");
            $this->newLine();
            $this->warn('Run "php artisan translator:sync" to download translations.');
        }

        $this->newLine();

        // Locale details
        if ($lastSync) {
            $this->line('Locale Details:');

            foreach ($lastSync['locales'] as $locale) {
                $checksum = $service->getLocalChecksum($locale);
                $status = $checksum ? '✓' : '✗';
                $this->line("  {$status} {$locale}");
            }
        }

        return self::SUCCESS;
    }

    protected function maskApiKey(?string $key): string
    {
        if (empty($key)) {
            return '<fg=red>Not configured</>';
        }

        if (strlen($key) < 10) {
            return $key;
        }

        return substr($key, 0, 6) . '...' . substr($key, -6);
    }
}
