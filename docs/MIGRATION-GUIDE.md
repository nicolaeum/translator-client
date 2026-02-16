# Migration Guide: Static Mode → Live Mode

This guide helps you migrate from file-based translations (Static Mode) to cache-based instant updates (Live Mode).

## Why Migrate?

**Static Mode:**
- ❌ Changes require deployment (15-30 min)
- ❌ Not compatible with Vapor/serverless
- ❌ Manual sync process
- ❌ Risk of merge conflicts

**Live Mode:**
- ✅ Changes live in <5 seconds
- ✅ Works everywhere (Vapor, Cloud, Forge, VPS)
- ✅ Automatic via webhooks
- ✅ No git conflicts

## Prerequisites

- [ ] Redis or compatible cache (Memcached, DynamoDB)
- [ ] LangSyncer API key (new format with webhook secret)
- [ ] Access to update environment variables
- [ ] Ability to run `composer dump-autoload`

## Migration Steps

### Step 1: Verify Your API Key Format

Live Mode requires the new API key format with embedded webhook secret.

**Check your key:**

```bash
php artisan tinker
```

```php
$apiKey = config('translator-client.api_key');
$parts = explode('_', $apiKey);

count($parts); // Should be 4 for new format
// Old format: 40 random characters
// New format: lh_proj_{id}_{secret}
```

**If you have old format, regenerate:**

1. Go to your project settings in LangSyncer
2. Click "Regenerate API Key"
3. Copy the new key (format: `lh_proj_...`)
4. Update `.env` (see Step 2)

### Step 2: Update Environment Variables

```env
# Change mode from static to live (or auto)
TRANSLATOR_CLIENT_MODE=live

# Update API key (if regenerated)
TRANSLATOR_API_KEY=lh_proj_your_project_id_your_webhook_secret

# Ensure cache is configured
CACHE_STORE=redis
TRANSLATOR_CLIENT_CACHE_DRIVER=redis

# Optional: Configure TTL (default: 300 seconds = 5 min)
TRANSLATOR_CLIENT_CACHE_TTL=300
```

### Step 3: Enable Webhooks (Optional)

Webhooks are enabled by default, but verify:

```env
TRANSLATOR_CLIENT_WEBHOOK_ENABLED=true
TRANSLATOR_CLIENT_WEBHOOK_PREWARM=true
```

### Step 4: Clear Old Files (Optional)

Static mode files are no longer needed:

```bash
# Backup first (optional)
mv resources/lang resources/lang.backup

# Or remove completely
rm -rf resources/lang/*

# Keep directory structure if you want
mkdir -p resources/lang/en
mkdir -p resources/lang/es
```

**Important:** Don't delete `resources/lang/` entirely - Laravel needs the directory to exist.

### Step 5: Regenerate Autoloader

```bash
composer dump-autoload
```

### Step 6: Clear Caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### Step 7: Warmup Cache (First Time)

Pre-cache all translations:

```bash
php artisan translator:warmup

# Output:
# Starting translation cache warmup...
# Mode: live
#
# 🔥 Warming cache for locale: en
#   ✓ Cached 5 groups
# 🔥 Warming cache for locale: es
#   ✓ Cached 5 groups
#
# 🎉 Cache warmup complete!
```

### Step 8: Test Translation Loading

```bash
php artisan tinker
```

```php
// Test mode detection
use Headwires\TranslatorClient\Support\ModeDetector;
ModeDetector::getMode(); // Should return "live"

// Test translation loading
__('messages.welcome'); // Should return translation

// Check metadata
$service = app(\Headwires\TranslatorClient\TranslatorClientService::class);
$service->getMetadata();
// Should show:
// [
//   'mode' => 'live',
//   'webhook_enabled' => true,
//   'webhook_configured' => true,
//   ...
// ]
```

### Step 9: Test Webhook (Optional)

Make a translation change in the hub and verify it appears within 5 seconds:

```bash
# Watch logs
tail -f storage/logs/laravel.log | grep "webhook"

# Change a translation in the hub
# You should see:
# "Translator webhook received"
# "Translation cache invalidated and pre-warmed"
```

### Step 10: Update Deploy Scripts

**Remove old sync command, add warmup:**

```bash
# OLD (Static Mode)
php artisan translator:sync

# NEW (Live Mode)
php artisan translator:warmup
```

**Example Forge deploy script:**

```bash
cd /home/forge/your-site.com

git pull origin main

composer install --no-interaction --prefer-dist --optimize-autoloader

php artisan translator:warmup  # ← New: Pre-warm cache
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan queue:restart
```

**Example Vapor build script (`vapor.yml`):**

```yaml
id: 12345
name: your-app
environments:
  production:
    build:
      - 'composer install --no-dev'
      - 'php artisan translator:warmup'  # ← Add this
    deploy:
      - 'php artisan migrate --force'
```

### Step 11: Git Cleanup (Optional)

Remove lang files from git:

```bash
# Add to .gitignore
echo "resources/lang/*" >> .gitignore
echo "!resources/lang/.gitkeep" >> .gitignore

# Keep directory
touch resources/lang/.gitkeep

# Remove tracked files
git rm -r --cached resources/lang/
git add .gitignore
git commit -m "chore: migrate to live mode, remove lang files from git"
```

## Rollback Plan

If you need to roll back to static mode:

### Quick Rollback

```env
TRANSLATOR_CLIENT_MODE=static
```

```bash
php artisan config:clear
php artisan translator:sync
git add resources/lang/
git commit -m "Rollback to static mode"
git push
# Deploy
```

### Restore from Backup

```bash
mv resources/lang.backup resources/lang
```

## Verification Checklist

After migration, verify:

- [  ] Mode is set to `live`:
  ```php
  ModeDetector::getMode() // Returns "live"
  ```

- [ ] Translations load correctly:
  ```php
  __('messages.welcome') // Returns expected value
  ```

- [ ] Webhook route is registered:
  ```bash
  php artisan route:list | grep translator/webhook
  # Should show: POST /api/translator/webhook
  ```

- [ ] Cache is being used:
  ```php
  use Illuminate\Support\Facades\Cache;
  Cache::has('translations:en:messages') // Returns true
  ```

- [ ] Webhook endpoint responds:
  ```bash
  curl -X POST https://your-app.test/api/translator/webhook \
    -H "Content-Type: application/json" \
    -d '{"event":"translation.updated","locale":"en","group":"messages"}'
  # Should return: {"status":"ok"} (might show 401 if signature missing - that's OK)
  ```

- [ ] Metadata shows correct configuration:
  ```php
  $service = app(\Headwires\TranslatorClient\TranslatorClientService::class);
  $metadata = $service->getMetadata();
  $metadata['mode'] === 'live' &&
  $metadata['webhook_configured'] === true
  ```

## Common Issues

### Issue: "Mode is still static"

**Cause:** Config cache not cleared.

**Fix:**
```bash
php artisan config:clear
composer dump-autoload
```

### Issue: "Webhook not working"

**Cause:** Old API key format doesn't have webhook secret.

**Fix:** Regenerate API key in hub (Step 1).

### Issue: "Cache not updating"

**Cause:** Redis not configured or not running.

**Fix:**
```bash
# Check Redis
redis-cli ping # Should return PONG

# Verify cache config
php artisan tinker
Cache::put('test', 'value', 60);
Cache::get('test'); // Should return 'value'
```

### Issue: "Translations not found"

**Cause:** Cache not warmed.

**Fix:**
```bash
php artisan translator:warmup
```

## FAQ

**Q: Can I run both modes simultaneously?**
A: No. Choose one mode per environment.

**Q: Will old API keys work in live mode?**
A: For loading translations: yes. For webhooks: no (need new format).

**Q: Do I lose anything by migrating?**
A: No. You gain instant updates and better DX.

**Q: Can I migrate gradually (dev → staging → prod)?**
A: Yes! Use different modes per environment:
```env
# dev: live mode
# staging: live mode
# production: static mode (until tested)
```

**Q: What about local development?**
A: Live mode works great locally with Redis.

## Support

Need help? Contact support@headwires.com

---

**Related:** [Live Mode Guide](./LIVE-MODE.md) | [Static Mode Guide](./STATIC-MODE.md)
