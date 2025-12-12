# Static Mode - Traditional File-Based Translations

> **Note:** Static Mode is the legacy approach. We recommend using [Live Mode](./LIVE-MODE.md) for instant updates without deployments.

## What is Static Mode?

Static Mode stores translations as PHP files in `resources/lang/` directory, following Laravel's traditional approach. Changes require git commits and deployments to take effect.

## When to Use Static Mode

- ✅ **Offline-first applications:** No internet connection available
- ✅ **Legacy applications:** Already using file-based translations
- ✅ **Git-first workflow:** Prefer translations in version control
- ✅ **Simple projects:** Small projects with infrequent translation changes

## How It Works

```
1. Run: php artisan translator:sync
2. Package downloads translations from CDN
3. Writes PHP files to resources/lang/{locale}/{group}.php
4. Laravel loads translations from files
5. Changes require new sync + deployment
```

## Setup

### 1. Force Static Mode

```env
TRANSLATOR_CLIENT_MODE=static
```

### 2. Sync Translations

```bash
# Sync all locales
php artisan translator:sync

# Files created:
# resources/lang/en/messages.php
# resources/lang/en/validation.php
# resources/lang/es/messages.php
# ...
```

### 3. Commit to Git

```bash
git add resources/lang/
git commit -m "Update translations"
git push
```

### 4. Deploy

Deploy your application. New translations are now live.

## Using Translations

Exactly the same as standard Laravel:

```php
__('messages.welcome')
trans('validation.required')
@lang('messages.hello')
```

## Syncing Translations

### Manual Sync

```bash
php artisan translator:sync
```

### Automatic Sync (Not Recommended for Production)

```env
TRANSLATOR_AUTO_SYNC=true
```

**Warning:** This syncs on every app boot. Use only in development.

### Deploy Script

```bash
#!/bin/bash
git pull
composer install --no-dev
php artisan translator:sync  # Sync translations
php artisan migrate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## File Structure

```
resources/lang/
├── en/
│   ├── messages.php
│   ├── validation.php
│   └── auth.php
├── es/
│   ├── messages.php
│   ├── validation.php
│   └── auth.php
└── fr/
    ├── messages.php
    ├── validation.php
    └── auth.php
```

Each file returns an array:

```php
<?php
// resources/lang/en/messages.php

return [
    'welcome' => 'Welcome!',
    'hello' => 'Hello :name',
    'goodbye' => 'Goodbye',
];
```

## Limitations

- ❌ **No instant updates:** Requires deployment
- ❌ **Not Vapor-compatible:** Vapor filesystem is read-only
- ❌ **Manual process:** Need to remember to sync before deploy
- ❌ **Merge conflicts:** Multiple people editing translations can cause conflicts

## Metadata

The package stores sync metadata in `storage/translator-client/`:

```
storage/translator-client/
├── en.meta          # Checksum, last sync time for 'en'
├── es.meta          # Checksum, last sync time for 'es'
└── last_sync.json   # Global sync info
```

This prevents re-downloading unchanged files.

## Migrating to Live Mode

Ready to get instant updates? See [MIGRATION-GUIDE.md](./MIGRATION-GUIDE.md).

## FAQ

**Q: Can I mix static and live mode?**
A: No. Choose one mode per environment.

**Q: What if CDN is down during sync?**
A: The sync will fail. Existing files remain unchanged.

**Q: Can I edit files manually?**
A: Yes, but changes will be overwritten on next sync.

**Q: Should I commit translation files?**
A: Yes. They should be in git for deployment.

---

**Next:** [Live Mode (Recommended)](./LIVE-MODE.md) | [Migration Guide](./MIGRATION-GUIDE.md)
