# Headwires Translator Client

Laravel package for managing translations with **instant updates** - no deployments needed.

## ✨ Live Mode - Instant Translation Updates

**New!** Get translation changes live in your app within <5 seconds, without redeployments. Works everywhere: Vapor, Cloud, Forge, VPS, localhost.

```bash
# Edit translation in dashboard → Live in <5 seconds ⚡
```

**Key Features:**
- ⚡ **Instant Updates:** Changes propagate in <5 seconds via webhooks
- 🌍 **Universal:** Works on serverless (Vapor) and traditional servers
- 🔧 **Zero Config:** Webhooks auto-configure from API key
- 💰 **Free Tier:** Included in all pricing tiers

**Quick Start (Live Mode):**

```env
TRANSLATOR_API_KEY=lh_proj_your_project_id_your_webhook_secret
TRANSLATOR_CLIENT_MODE=live
CACHE_STORE=redis
```

```bash
php artisan translator:warmup  # Pre-cache translations
```

**That's it!** Your translations now update instantly. 🎉

📖 **[Read the complete Live Mode guide →](./docs/LIVE-MODE.md)**

---

## Installation

```bash
composer require headwires/translator-client
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=translator-client-config
```

## Configuration

Add to your `.env` file:

```env
TRANSLATOR_API_KEY=your-40-char-api-key-here
TRANSLATOR_CDN_URL=https://cdn.headwires-translator.com
TRANSLATOR_LOCALES=en,es,fr
TRANSLATOR_STORAGE_MODE=file
TRANSLATOR_CACHE_TTL=3600
TRANSLATOR_VERIFY_CHECKSUMS=true
```

### Configuration Options

- **TRANSLATOR_API_KEY** (required): Your 40-character API key from Headwires Translator
- **TRANSLATOR_CDN_URL**: CDN endpoint URL (default: https://cdn.headwires-translator.com)
- **TRANSLATOR_LOCALES**: Comma-separated list of locale codes to sync (default: en)
- **TRANSLATOR_STORAGE_MODE**: Storage mode - `file` or `cache` (default: file)
- **TRANSLATOR_CACHE_TTL**: Cache TTL in seconds when using cache mode (default: 3600)
- **TRANSLATOR_VERIFY_CHECKSUMS**: Verify checksums after download (default: true)
- **TRANSLATOR_SYNC_STRATEGY**: How to handle existing translations - `overwrite` or `merge` (default: overwrite)

## Usage

### Sync Translations

Download all translations from CDN:

```bash
php artisan translator:sync
```

Options:

```bash
# Sync specific locale
php artisan translator:sync --locale=es

# Force refresh (ignore checksums)
php artisan translator:sync --force

# Verify checksums after download
php artisan translator:sync --verify
```

### Check Status

View sync status and statistics:

```bash
php artisan translator:status
```

This command displays:
- Masked API key
- Last sync timestamp (human-readable)
- Current version from CDN
- List of synced locales with status indicators
- Helpful message if never synced

### Use in Application

After syncing, use Laravel's translation helpers normally:

```php
// In Blade templates
{{ __('messages.welcome') }}
{{ __('messages.user_greeting', ['name' => $user->name]) }}

// In controllers
$message = __('validation.required', ['attribute' => 'email']);

// In validation
$rules = ['email' => 'required|email'];
$messages = [
    'email.required' => __('validation.required'),
];

// With parameters
__('messages.items_count', ['count' => 5])
// If translation is: "You have :count items"
// Output: "You have 5 items"
```

### Programmatic Usage

You can also use the service directly in your code:

```php
use Headwires\TranslatorClient\TranslatorClientService;

// Inject the service
public function __construct(
    protected TranslatorClientService $translator
) {}

// Sync all translations
$this->translator->sync();

// Sync specific locale
$this->translator->sync(locale: 'es');

// Force sync
$this->translator->sync(force: true);

// Get last sync info
$lastSync = $this->translator->getLastSync();
// Returns: ['synced_at' => '...', 'version' => '1.2.3', 'locales' => ['en', 'es']]

// Get local checksum for a locale
$checksum = $this->translator->getLocalChecksum('en');
```

## Deployment

### Manual Deployment

Add to your deployment script:

```bash
# After composer install
php artisan translator:sync
php artisan cache:clear
```

### Automated Sync with Cron

Set up a cron job to sync translations regularly:

```bash
# Sync every hour
0 * * * * cd /path/to/app && php artisan translator:sync

# Sync once per day at 3 AM
0 3 * * * cd /path/to/app && php artisan translator:sync

# Sync every 15 minutes (for high-frequency updates)
*/15 * * * * cd /path/to/app && php artisan translator:sync
```

### Laravel Scheduler

Alternatively, add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Sync translations hourly
    $schedule->command('translator:sync')
        ->hourly()
        ->withoutOverlapping();
}
```

### CI/CD Integration

For continuous deployment pipelines:

```yaml
# Example: GitHub Actions
- name: Sync Translations
  run: |
    php artisan translator:sync --verify
  env:
    TRANSLATOR_API_KEY: ${{ secrets.TRANSLATOR_API_KEY }}
```

## Storage Modes

### File Mode (Recommended)

Translations saved as PHP files in `resources/lang/`:

```
resources/lang/
├── en/
│   ├── messages.php
│   ├── auth.php
│   └── validation.php
└── es/
    ├── messages.php
    ├── auth.php
    └── validation.php
```

**Advantages:**
- Persistent across deployments
- Fast loading (compiled PHP)
- Version control friendly
- Works with Laravel's native translation system

**Use when:**
- Deploying to production
- Need version control
- Want persistent translations

Configuration:
```env
TRANSLATOR_STORAGE_MODE=file
```

#### ⚠️ Auto-Generated Files Warning

All synced translation files include a warning header to prevent manual editing:

```php
<?php

/**
 * ⚠️  AUTO-GENERATED FILE - DO NOT EDIT MANUALLY
 *
 * This translation file is automatically generated and managed by
 * Headwires Translator Client (https://translator.headwires.com)
 *
 * Any manual changes to this file will be OVERWRITTEN on the next sync.
 *
 * To add custom translations:
 *   - Create a separate file (e.g., custom.php) for your local translations
 *   - Or add translations through the Headwires Translator dashboard
 *
 * Sync Information:
 *   - Locale: es
 *   - File: auth.php
 *   - Project: abc12345...
 *   - Last Sync: 2025-11-24 14:30:00
 */

return [
    // Your synced translations...
];
```

**Important:**
- ❌ **DO NOT** manually edit synced files (`auth.php`, `form.php`, etc.)
- ✅ **DO** create separate files for custom translations (`custom.php`, `app.php`, etc.)
- ✅ **DO** add new translations through the Headwires Translator dashboard
- 💡 Local custom files will coexist with synced files without conflicts

**Example structure:**
```
resources/lang/es/
├── auth.php        ← Managed by Headwires Translator (auto-generated)
├── form.php        ← Managed by Headwires Translator (auto-generated)
├── validation.php  ← Managed by Headwires Translator (auto-generated)
└── custom.php      ← Your custom translations (safe to edit)
```

### Sync Strategies

Control how the sync handles existing local translation files.

#### Overwrite Mode (Default)

```env
TRANSLATOR_SYNC_STRATEGY=overwrite
```

Completely replaces local files with CDN versions. Use when:
- Headwires Translator is your single source of truth
- You don't have local-only translations
- You want a clean sync every time

#### Merge Mode

```env
TRANSLATOR_SYNC_STRATEGY=merge
```

Intelligently merges CDN translations with existing local files:
- **CDN values take precedence** for keys that exist in both
- **Local-only keys are preserved** (not deleted)
- **Recursive merge** for nested arrays

**Example:**
```php
// Local file before sync
return [
    'welcome' => 'Bienvenido',      // exists in both
    'local_only' => 'Solo local',   // only exists locally
];

// CDN version
return [
    'welcome' => 'Welcome!',        // will overwrite local
    'new_key' => 'From CDN',        // will be added
];

// Result after merge sync
return [
    'welcome' => 'Welcome!',        // from CDN (takes precedence)
    'local_only' => 'Solo local',   // preserved from local
    'new_key' => 'From CDN',        // added from CDN
];
```

**Use merge mode when:**
- You have environment-specific translations
- You want to add local keys without losing them on sync
- You're gradually migrating to Headwires Translator

### Global Translations

Global translations from Headwires Translator are automatically saved to a single `global.php` file, keeping them separate from project-specific translations.

**File structure after sync:**
```
resources/lang/es/
├── auth.php        ← Project translations
├── messages.php    ← Project translations
└── global.php      ← All global translations (grouped)
```

**Using global translations in your app:**
```php
// Global translations are accessed with 'global.' prefix
{{ __('global.actions.save') }}      // "Guardar"
{{ __('global.actions.cancel') }}    // "Cancelar"
{{ __('global.buttons.submit') }}    // "Enviar"

// Project translations work as usual
{{ __('auth.login') }}               // "Iniciar sesión"
```

**Structure of global.php:**
```php
<?php

return [
    'actions' => [
        'save' => 'Guardar',
        'cancel' => 'Cancelar',
        'delete' => 'Eliminar',
    ],
    'buttons' => [
        'submit' => 'Enviar',
        'reset' => 'Restablecer',
    ],
];
```

### Cache Mode

Translations stored in Laravel cache (faster but volatile):

```env
TRANSLATOR_STORAGE_MODE=cache
TRANSLATOR_CACHE_TTL=3600
```

**Advantages:**
- Extremely fast access
- No file I/O overhead
- Good for temporary environments

**Disadvantages:**
- Lost on cache clear
- Lost on server restart (if using array/file cache)
- Requires re-sync after deployment

**Use when:**
- Testing environments
- Development mode
- Using Redis/Memcached with persistence

## How It Works

### Sync Process

1. **Fetch Manifest**: Downloads `manifest.json` from CDN containing available locales and versions
2. **Compare Checksums**: Compares local checksums with CDN to detect changes
3. **Download Translations**: Fetches updated locale JSON files
4. **Verify Integrity**: Validates MD5 checksums (if enabled)
5. **Save Locally**: Stores as PHP files or in cache based on configuration
6. **Update Metadata**: Records sync timestamp and checksums

### Checksum Verification

The package uses MD5 checksums to:
- Skip downloading unchanged translations
- Verify file integrity after download
- Detect corruption or incomplete downloads

Force sync with `--force` to bypass checksum checks.

### Metadata Storage

Sync metadata stored in `storage/app/translator/`:
```
storage/app/translator/
├── last_sync.json    # Last sync info
├── en.meta           # English locale metadata
└── es.meta           # Spanish locale metadata
```

## Testing

Run the package test suite:

```bash
cd packages/translator-client
composer test
```

Or run specific test files:

```bash
vendor/bin/pest tests/Feature/SyncCommandTest.php
vendor/bin/pest tests/Feature/StatusCommandTest.php
vendor/bin/pest tests/Unit/TranslatorClientServiceTest.php
```

### Testing in Your Application

Mock the service in your tests:

```php
use Headwires\TranslatorClient\TranslatorClientService;
use Illuminate\Support\Facades\Http;

test('translations are synced', function () {
    Http::fake([
        '*/manifest.json' => Http::response([
            'version' => '1.0.0',
            'locales' => [
                ['code' => 'en', 'checksum' => 'md5:abc123'],
            ],
        ]),
        '*/en.json' => Http::response([
            'messages.welcome' => 'Welcome!',
        ]),
    ]);

    $service = app(TranslatorClientService::class);
    $service->sync();

    expect(__('messages.welcome'))->toBe('Welcome!');
});
```

## Troubleshooting

### API Key Issues

**Problem:** "API key not configured"

**Solution:**
```bash
# Check .env file
grep TRANSLATOR_API_KEY .env

# Verify config
php artisan config:clear
php artisan translator:status
```

### Sync Failures

**Problem:** HTTP errors during sync

**Solution:**
```bash
# Check CDN connectivity
curl -I https://cdn.headwires-translator.com/manifest.json

# Verify API key format (should be 40 characters)
php artisan translator:status

# Try force sync
php artisan translator:sync --force
```

### Missing Translations

**Problem:** Translations not found after sync

**Solution:**
```bash
# Verify sync completed
php artisan translator:status

# Check locale files (file mode)
ls -la resources/lang/en/

# Check cache (cache mode)
php artisan cache:clear
php artisan translator:sync

# Verify locale is configured
grep TRANSLATOR_LOCALES .env
```

### Checksum Verification Failures

**Problem:** Checksum mismatch errors

**Solution:**
```bash
# Delete cached checksums
rm -rf storage/app/translator/*.meta

# Force re-sync
php artisan translator:sync --force --verify
```

## Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- Guzzle HTTP client (auto-installed)

## Security

- API keys are never logged or exposed in error messages (masked in output)
- All HTTP requests use HTTPS
- Checksums verify file integrity
- No eval() or dynamic code execution
- Files written with appropriate permissions (0755 for directories, 0644 for files)

## License

MIT

## Support

For issues, questions, or feature requests:

- GitHub Issues: [headwires/translator-client](https://github.com/headwires/translator-client/issues)
- Email: support@headwires.com
- Documentation: [https://docs.headwires-translator.com](https://docs.headwires-translator.com)

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Write tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## Documentation

- 📘 **[Live Mode Guide](./docs/LIVE-MODE.md)** - Instant translation updates (recommended)
- 📗 **[Static Mode Guide](./docs/STATIC-MODE.md)** - Traditional file-based translations
- 📙 **[Migration Guide](./docs/MIGRATION-GUIDE.md)** - Migrate from static to live mode

## Changelog

### Version 2.1.0 (2025-12-20)

- ✨ **NEW: Sync Strategies** - Choose between `overwrite` and `merge` modes
- 🔀 Merge mode preserves local-only translation keys during sync
- 🔄 Recursive merge for nested translation arrays
- 📝 CDN values take precedence in merge conflicts
- 🌐 **NEW: Global Translations Support** - Global translations saved to `global.php`
- 📦 Format v2: Structured JSON with project/global separation
- ⬇️ Backwards compatible with v1 format

### Version 2.0.0 (2025-12-09)

- 🎉 **NEW: Live Mode** - Instant translation updates via webhooks (<5 seconds)
- ✨ Auto-configured webhooks from API key (zero manual setup)
- 🚀 Works universally (Vapor, Cloud, Forge, VPS, localhost)
- 📦 New command: `translator:warmup` for deploy-time cache warming
- 🔑 Enhanced API key format with embedded webhook secret
- 🔄 Auto-registered webhook route `/api/translator/webhook`
- 🎯 Intelligent mode detection (auto, live, static)
- ⚡ TTL-based cache fallback if webhooks fail
- 🛡️ HMAC-SHA256 signature verification
- 📝 Comprehensive documentation (Live Mode, Migration Guide)
- 🔧 Backward compatible with static mode

### Version 1.0.0 (2025-11-24)

- Initial release
- Sync command with locale, force, and verify options
- Status command for viewing sync information
- File and cache storage modes
- Checksum verification
- Laravel 11 support
- Comprehensive test coverage
