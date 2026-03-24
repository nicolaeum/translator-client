# Translator Client for Laravel

Laravel package for [LangSyncer](https://langsyncer.com) - live translation management without deployments.

## Features

- **Live Updates**: Translation changes appear in your app within seconds
- **Static Mode**: Traditional file-based translations for full control
- **Code Scanner**: Find hardcoded strings in your codebase
- **Multi-Project**: Manage multiple translation projects in one app
- **Webhooks**: Auto-sync when translations change in LangSyncer

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require headwires/translator-client
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=translator-client-config
```

## Quick Start

Add to your `.env`:

```env
CLI_TRANSLATOR_API_KEY=your-api-key-from-langsyncer
```

Sync translations:

```bash
php artisan translator:sync
```

Done! Use Laravel's translation helpers as usual:

```php
{{ __('messages.welcome') }}
{{ __('messages.greeting', ['name' => $user->name]) }}
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `CLI_TRANSLATOR_API_KEY` | Your project API key from LangSyncer | required |
| `CLI_TRANSLATOR_CLIENT_MODE` | `static`, `live`, or `auto` | `static` |
| `CLI_TRANSLATOR_SYNC_STRATEGY` | `overwrite` or `merge` | `overwrite` |
| `CLI_TRANSLATOR_CLIENT_CACHE_TTL` | Cache TTL in seconds (live mode) | `3600` |
| `CLI_TRANSLATOR_CLIENT_WEBHOOK_ENABLED` | Enable webhook endpoint | `true` |

### Client Modes

#### Static Mode (Default)

Translations saved as PHP files in `resources/lang/`. Best for production.

```env
CLI_TRANSLATOR_CLIENT_MODE=static
```

#### Live Mode

Translations loaded from cache, updated instantly via webhooks. Uses your project's configured cache driver.

```env
CLI_TRANSLATOR_CLIENT_MODE=live
```

#### Auto Mode

Automatically selects live mode for serverless (Vapor) or static mode otherwise.

```env
CLI_TRANSLATOR_CLIENT_MODE=auto
```

### Sync Strategies

#### Overwrite (Default)

Replaces local files completely. Use when LangSyncer is your source of truth.

```env
CLI_TRANSLATOR_SYNC_STRATEGY=overwrite
```

#### Merge

Preserves local-only keys while updating from LangSyncer. CDN values take precedence for existing keys.

```env
CLI_TRANSLATOR_SYNC_STRATEGY=merge
```

## Commands

### translator:sync

Download translations from LangSyncer:

```bash
php artisan translator:sync

# Sync specific project (if using multi-project)
php artisan translator:sync --project=main

# Force sync (ignore checksums)
php artisan translator:sync --force
```

### translator:warmup

Pre-cache translations for live mode. Run during deployment:

```bash
php artisan translator:warmup
```

### translator:status

Check configuration and connection status:

```bash
php artisan translator:status
```

Output:
```
Project Name
   API Key: 019bd1c2-... (configured)
   Path: /var/www/app/resources/lang
   Mode: live
   Cache TTL: 3600s
   Webhook: configured
```

### translator:scan

Scan your codebase for hardcoded strings:

```bash
php artisan translator:scan
```

Scans `.blade.php` and `.php` files for text that should be translated.

### translator:apply

Apply approved scanner suggestions and activate translations:

```bash
php artisan translator:apply
```

## Scanner Architecture

### Server-Side Intelligence (v1.5+)

The scanner uses a **server-side intelligence** architecture. The client extracts raw string candidates from your codebase and sends them to the LangSyncer server, which applies intelligent key generation, skip pattern filtering, and confidence scoring.

**Why this design?** Key generation heuristics (common action labels, UI element patterns, skip rules) are maintained server-side. This ensures all clients benefit from the latest improvements without requiring package updates, and keeps proprietary detection logic secure.

### How It Works

1. `translator:scan` extracts raw text candidates (string, file path, line number, element type)
2. Candidates are sent to the server via `POST /api/scanner/process-raw`
3. The server returns processed keys with confidence scores and filtering results

### API Endpoint: `POST /api/scanner/process-raw`

**Request:**

```json
{
  "project_id": "your-api-key",
  "candidates": [
    {
      "text": "Save Changes",
      "file": "resources/views/posts/edit.blade.php",
      "line": 15,
      "element_type": "button",
      "file_type": "blade"
    }
  ]
}
```

**Response:**

```json
{
  "processed": [
    {
      "text": "Save Changes",
      "key": "posts-edit.buttons.save",
      "value": "Save Changes",
      "confidence": 85,
      "params": [],
      "file": "resources/views/posts/edit.blade.php",
      "line": 15
    }
  ],
  "skipped": [],
  "stats": {
    "total": 1,
    "processed": 1,
    "skipped": 0,
    "high_confidence": 1,
    "medium_confidence": 0,
    "low_confidence": 0
  }
}
```

### Deprecation Notice

The client-side `KeyGenerator` class is **deprecated** and will be removed in v2.0. Key generation is now handled server-side. If you have code that depends on `KeyGenerator`, migrate to the server endpoint.

## Multi-Project Setup

Configure multiple projects in `config/translator-client.php`:

```php
'projects' => [
    [
        'name' => 'main',
        'api_key' => env('CLI_TRANSLATOR_API_KEY'),
        'path' => resource_path('lang'),
        'scan_paths' => ['resources/views', 'app'],
    ],
    [
        'name' => 'package',
        'api_key' => env('CLI_TRANSLATOR_PACKAGE_API_KEY'),
        'path' => base_path('vendor/your-package/resources/lang'),
        'scan_paths' => ['vendor/your-package/resources/views'],
    ],
],
```

Sync specific project:

```bash
php artisan translator:sync --project=package
```

## Webhooks

The package automatically registers a webhook endpoint at `/api/translator/webhook`. Configure this URL in your LangSyncer project settings.

When translations are published in LangSyncer, the webhook triggers an automatic sync.

Customize the route:

```env
CLI_TRANSLATOR_CLIENT_WEBHOOK_ROUTE=/custom/webhook/path
```

Disable webhooks:

```env
CLI_TRANSLATOR_CLIENT_WEBHOOK_ENABLED=false
```

## Deployment

Add to your deployment script:

```bash
php artisan translator:sync
php artisan translator:warmup  # if using live mode
```

### Laravel Scheduler

Auto-sync translations:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('translator:sync')
        ->hourly()
        ->withoutOverlapping();
}
```

## File Structure

After syncing (static mode):

```
resources/lang/
├── en/
│   ├── messages.php      # Managed by LangSyncer
│   ├── auth.php          # Managed by LangSyncer
│   └── custom.php        # Your local translations (safe)
└── es/
    ├── messages.php
    ├── auth.php
    └── custom.php
```

> **Note**: Synced files include a warning header. Create separate files for local-only translations.

## License

MIT

## Support

- Documentation: [langsyncer.com/documentation](https://langsyncer.com/documentation)
- Issues: [GitHub Issues](https://github.com/headwires/translator-client/issues)
