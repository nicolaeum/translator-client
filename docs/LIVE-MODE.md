# Live Mode - Instant Translation Updates

> **TL;DR:** Live Mode provides instant translation updates without redeployments. Works everywhere: Vapor, Cloud, Forge, VPS, localhost. Just add your API key - webhooks auto-configure.

## What is Live Mode?

Live Mode is a cache-based translation system that delivers instant updates via CDN + webhooks. Unlike traditional file-based translations that require git commits and deployments, Live Mode updates your translations in <5 seconds.

**Traditional Flow (Static Mode):**
```
Edit translation → Commit to git → Deploy → App restarts → Translation available
(15-30 minutes typical)
```

**Live Mode Flow:**
```
Edit translation → Webhook sent → Cache cleared → Pre-warmed → Translation available
(<5 seconds typical)
```

## Key Features

- ✅ **Instant Updates:** Changes live in <5 seconds
- ✅ **Universal:** Works on Vapor, Cloud, Forge, VPS, localhost
- ✅ **Zero Config:** Webhooks auto-configure from API key
- ✅ **Automatic Fallback:** TTL-based cache expiry if webhooks fail
- ✅ **Deploy-Time Warmup:** Pre-cache translations during deployment
- ✅ **Free Tier Included:** Not a premium feature

## How It Works

### Architecture

```
┌─────────────────────────────────────────────────────────┐
│                  Your Laravel App                        │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │  translator-client Package (Live Mode)           │  │
│  │                                                   │  │
│  │  1. Load translation from cache (Redis)          │  │
│  │  2. If miss: Fetch from CDN                      │  │
│  │  3. Cache with TTL (5 min default)               │  │
│  │                                                   │  │
│  │  Webhook Route: /api/translator/webhook          │  │
│  │  - Auto-registered                                │  │
│  │  - Signature verified                             │  │
│  │  - Cache invalidated                              │  │
│  │  - Pre-warmed automatically                       │  │
│  └──────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
                         ▲                    ▲
                         │                    │
                    Webhook               CDN Fetch
                (cache invalidation)    (translation data)
                         │                    │
┌────────────────────────┴────────────────────┴───────────┐
│            LangSyncer (Server)                           │
│                                                          │
│  • User edits translation in dashboard                  │
│  • Webhook dispatched with HMAC signature               │
│  • CDN files updated                                    │
└─────────────────────────────────────────────────────────┘
```

### API Key Format

Live Mode uses an enhanced API key format that embeds the webhook secret:

**Format:** `lh_proj_{project_id}_{base64_webhook_secret}`

**Example:** `lh_proj_abc123_d2ViaG9va19zZWNyZXQ0NTY=`

**What's inside:**
- `lh_proj_` - Prefix identifying new format
- `abc123` - Your project ID
- `d2ViaG9va19zZWNyZXQ4NTY=` - Base64-encoded webhook secret (64-char hex)

The package automatically extracts the webhook secret and uses it to verify incoming webhooks. No manual configuration needed!

## Setup

### 1. Installation

```bash
composer require headwires/translator-client
```

### 2. Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=translator-client-config
```

### 3. Environment Variables

Add to your `.env`:

```env
# Required
TRANSLATOR_API_KEY=lh_proj_your_project_id_your_webhook_secret

# Optional - defaults shown
TRANSLATOR_CLIENT_MODE=auto                    # auto, live, or static
TRANSLATOR_CDN_URL=https://cdn.headwires.com   # Your CDN URL
TRANSLATOR_CLIENT_CACHE_DRIVER=redis           # redis, dynamodb, memcached
TRANSLATOR_CLIENT_CACHE_TTL=300                # 5 minutes (fallback)
TRANSLATOR_CLIENT_WEBHOOK_ENABLED=true         # Enable webhooks
TRANSLATOR_CLIENT_WEBHOOK_PREWARM=true         # Pre-warm after invalidation
```

### 4. Verify Mode Detection

```bash
php artisan tinker
```

```php
use Headwires\TranslatorClient\Support\ModeDetector;

ModeDetector::getMode(); // Should return "live"
ModeDetector::isServerless(); // true on Vapor, false elsewhere
```

### 5. Cache Warmup (Optional but Recommended)

Pre-cache all translations during deployment:

**For Vapor (`vapor.yml`):**
```yaml
build:
  - 'composer install --no-dev'
  - 'php artisan translator:warmup'
```

**For Forge/Envoyer:**
```bash
cd /home/forge/your-site.com
php artisan translator:warmup
```

**Manual:**
```bash
# Warm all locales
php artisan translator:warmup

# Warm specific locales
php artisan translator:warmup --locales=en --locales=es

# Warm specific groups
php artisan translator:warmup --groups=messages --groups=validation
```

## Configuration Options

### Mode Selection

```php
// config/translator-client.php

'mode' => env('TRANSLATOR_CLIENT_MODE', 'auto'),
```

**Options:**
- `auto` (default) - Detects best mode:
  - **Serverless (Vapor):** Forces `live` mode (required)
  - **Traditional:** Checks for lang files, defaults to `live` if none found
- `live` - Force live mode everywhere
- `static` - Force file-based mode (legacy)

### Cache Configuration

```php
'cache' => [
    'driver' => env('TRANSLATOR_CLIENT_CACHE_DRIVER', 'redis'),
    'prefix' => env('TRANSLATOR_CLIENT_CACHE_PREFIX', 'translations'),
    'ttl' => env('TRANSLATOR_CLIENT_CACHE_TTL', 300), // 5 minutes
],
```

**TTL Behavior:**
- `300` (default) - Cache expires after 5 minutes (fallback if webhooks fail)
- `0` - Cache forever, rely only on webhooks (not recommended)
- `3600` - 1 hour fallback

### Webhook Configuration

```php
'webhook' => [
    'enabled' => env('TRANSLATOR_CLIENT_WEBHOOK_ENABLED', true),
    'route' => env('TRANSLATOR_CLIENT_WEBHOOK_ROUTE', '/api/translator/webhook'),
    'prewarm' => env('TRANSLATOR_CLIENT_WEBHOOK_PREWARM', true),
],
```

**What gets auto-configured:**
- ✅ Route registration at `/api/translator/webhook`
- ✅ Signature verification using secret from API key
- ✅ Cache invalidation on webhook receipt
- ✅ Optional pre-warming (fetches fresh data immediately)

### Live Mode Specific

```php
'live' => [
    'warmup_on_deploy' => env('TRANSLATOR_CLIENT_LIVE_WARMUP', true),
    'aggressive_prewarm' => env('TRANSLATOR_CLIENT_LIVE_AGGRESSIVE_PREWARM', false),
],
```

## Using Translations

Live Mode is **100% compatible** with Laravel's translation system. Use translations exactly as you normally would:

```php
// In controllers
__('messages.welcome')
trans('validation.required')

// In Blade templates
{{ __('messages.hello', ['name' => $user->name]) }}
@lang('messages.welcome')

// Pluralization
trans_choice('messages.apples', 10)

// JSON translations
__('Welcome to our application')
```

No changes needed! The package integrates seamlessly with Laravel's translation loader.

## Webhook Events

The webhook controller handles these events automatically:

### 1. translation.updated

Sent when a single translation is updated.

**Payload:**
```json
{
  "event": "translation.updated",
  "locale": "en",
  "group": "messages",
  "key": "welcome",
  "value": "Welcome!",
  "old_value": "Hello!",
  "timestamp": "2025-12-09T10:30:00Z"
}
```

**Action:** Clears cache for `{locale}:{group}`, optionally pre-warms.

### 2. translation.deleted

Sent when a translation is deleted.

**Payload:**
```json
{
  "event": "translation.deleted",
  "locale": "en",
  "group": "messages",
  "key": "old_message",
  "timestamp": "2025-12-09T10:30:00Z"
}
```

**Action:** Clears cache for `{locale}:{group}`.

### 3. translations.bulk_updated

Sent when multiple translations are updated at once.

**Payload:**
```json
{
  "event": "translations.bulk_updated",
  "locales": ["en", "es", "fr"],
  "groups": ["messages", "validation"],
  "count": 150,
  "timestamp": "2025-12-09T10:30:00Z"
}
```

**Action:** Clears cache for all `{locale}:{group}` combinations, optionally pre-warms.

### 4. project.languages_changed

Sent when languages are added or removed from the project.

**Payload:**
```json
{
  "event": "project.languages_changed",
  "added": ["de", "it"],
  "removed": ["pt"],
  "current": ["en", "es", "fr", "de", "it"],
  "timestamp": "2025-12-09T10:30:00Z"
}
```

**Action:** Flushes entire translation cache.

## Monitoring & Debugging

### Check Current Mode

```bash
php artisan tinker
```

```php
$service = app(\Headwires\TranslatorClient\TranslatorClientService::class);
$service->getMetadata();

// Returns:
[
  'mode' => 'live',
  'cache_driver' => 'redis',
  'cache_prefix' => 'translations',
  'cache_ttl' => 300,
  'cdn_url' => 'https://cdn.headwires.com',
  'webhook_enabled' => true,
  'webhook_configured' => true,
  'project_id' => 'abc123',
]
```

### Test Webhook Endpoint

```bash
# Generate test signature
php artisan tinker
```

```php
$apiKey = config('translator-client.api_key');
$secret = \Headwires\TranslatorClient\Support\ApiKeyParser::getWebhookSecret($apiKey);
$payload = '{"event":"translation.updated","locale":"en","group":"messages"}';
$signature = hash_hmac('sha256', $payload, $secret);
echo "Signature: {$signature}\n";
```

```bash
# Test webhook
curl -X POST https://your-app.test/api/translator/webhook \
  -H "Content-Type: application/json" \
  -H "X-Translator-Signature: YOUR_SIGNATURE_HERE" \
  -d '{"event":"translation.updated","locale":"en","group":"messages","key":"test","value":"Test"}'

# Should return: {"status":"ok"}
```

### View Logs

```bash
# Watch webhook receipts
tail -f storage/logs/laravel.log | grep "Translator webhook"

# See cache invalidation
tail -f storage/logs/laravel.log | grep "Translation cache"
```

### Check Cache Contents

```bash
php artisan tinker
```

```php
use Illuminate\Support\Facades\Cache;

// Check if translation is cached
Cache::has('translations:en:messages'); // true/false

// View cached translation
Cache::get('translations:en:messages');

// Manually clear cache
Cache::forget('translations:en:messages');

// Clear all translations
Cache::tags(['translations'])->flush(); // If using taggable cache
```

## Troubleshooting

### Webhooks Not Working

**Symptoms:** Translations don't update immediately after changes.

**Checks:**
1. Verify API key format contains webhook secret:
   ```php
   $apiKey = config('translator-client.api_key');
   $parts = explode('_', $apiKey);
   count($parts); // Should be 4 for new format
   ```

2. Check webhook route is registered:
   ```bash
   php artisan route:list | grep translator/webhook
   ```

3. Verify mode is `live`:
   ```php
   \Headwires\TranslatorClient\Support\ModeDetector::getMode(); // Should be 'live'
   ```

4. Check webhook logs on server (LangSyncer):
   ```bash
   tail -f storage/logs/laravel.log | grep "Webhook"
   ```

5. Verify your app is reachable from the hub server (firewall, VPN).

**Fallback:** Even if webhooks fail, cache expires after TTL (default: 5 minutes) and refetches.

### Cache Not Expiring

**Symptoms:** Old translations persist beyond expected time.

**Checks:**
1. Verify TTL is set correctly:
   ```php
   config('translator-client.cache.ttl'); // Should be 300 (5 min)
   ```

2. Check cache driver is working:
   ```bash
   php artisan tinker
   Cache::put('test_key', 'test_value', 60);
   Cache::get('test_key'); // Should return 'test_value'
   ```

3. Verify Redis/cache is running:
   ```bash
   redis-cli ping # Should return PONG
   ```

**Solution:** Manually clear cache:
```bash
php artisan cache:clear
# Or specifically:
php artisan tinker
app(\Headwires\TranslatorClient\TranslatorClientService::class)->flush();
```

### Mode Detection Wrong

**Symptoms:** App uses static mode when you expect live mode.

**Checks:**
1. Check explicit mode setting:
   ```env
   TRANSLATOR_CLIENT_MODE=live
   ```

2. Verify no conflicting lang files exist:
   ```bash
   ls -la resources/lang/
   ```

3. Force live mode in config:
   ```php
   // config/translator-client.php
   'mode' => 'live',
   ```

### Signature Verification Fails

**Symptoms:** Webhook returns 401 Unauthorized.

**Checks:**
1. Verify API key on both sides matches exactly
2. Check webhook secret extraction:
   ```php
   $secret = \Iworking\TranslatorClient\Support\ApiKeyParser::getWebhookSecret(
       config('translator-client.api_key')
   );
   var_dump($secret); // Should not be null
   ```

3. Regenerate API key in hub if corrupted

## Performance Optimization

### 1. Use Redis for Cache

Redis is significantly faster than file or database cache:

```env
CACHE_STORE=redis
TRANSLATOR_CLIENT_CACHE_DRIVER=redis
```

### 2. Aggressive Pre-warming (Production)

Pre-fetch all translations on first request:

```env
TRANSLATOR_CLIENT_LIVE_AGGRESSIVE_PREWARM=true
```

**Trade-off:** Slower first request, faster subsequent requests.

### 3. Deploy-Time Warmup

Always warmup cache during deployment:

```bash
php artisan translator:warmup
```

### 4. Optimize TTL

Balance between freshness and cache hit rate:

- **High traffic, frequent updates:** TTL = 60-300 seconds (1-5 min)
- **Medium traffic, occasional updates:** TTL = 300-900 seconds (5-15 min)
- **Low traffic, rare updates:** TTL = 0 (rely on webhooks only)

### 5. CDN Optimization

Ensure your CDN URL is geographically close:

```env
# US region
TRANSLATOR_CDN_URL=https://cdn-us.headwires.com

# EU region
TRANSLATOR_CDN_URL=https://cdn-eu.headwires.com
```

## Security Considerations

### Webhook Signature Verification

All webhooks are signed with HMAC-SHA256:

```
X-Translator-Signature: hash_hmac('sha256', $payload, $webhook_secret)
```

The package **automatically verifies** signatures. Invalid signatures are rejected with 401.

### API Key Protection

**Never commit API keys to git:**

```gitignore
.env
.env.*
!.env.example
```

**Rotate keys if compromised:**

1. Go to LangSyncer project settings
2. Click "Regenerate API Key"
3. Update `.env` on all servers
4. Deploy

### Rate Limiting Webhooks

Webhooks are sent from trusted servers, but you can add rate limiting:

```php
// app/Http/Kernel.php
'api' => [
    'throttle:60,1', // 60 requests per minute
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
],
```

The webhook route is registered as a POST route and can be protected with middleware if needed.

## Migration from Static Mode

See [MIGRATION-GUIDE.md](./MIGRATION-GUIDE.md) for detailed instructions on migrating from file-based to live mode.

## FAQ

**Q: Does live mode work on shared hosting?**
A: Yes, if you have Redis/Memcached available. File-based cache also works but is slower.

**Q: What if my server goes offline?**
A: Translations remain cached. Webhooks are retried 3 times. TTL ensures eventual consistency.

**Q: Can I use both live and static mode?**
A: Not simultaneously. Choose one mode per environment. Use static for dev, live for production if desired.

**Q: Do I need to change my translation files?**
A: No! Live mode is 100% compatible with Laravel's translation syntax.

**Q: What's the cost of live mode?**
A: Included in all tiers (Free, Pro, Enterprise). Not a premium feature.

**Q: How much Redis memory do I need?**
A: Approximately 1-5 MB per 1000 translation strings. Monitor with:
```bash
redis-cli --bigkeys
```

**Q: Can I test webhooks locally?**
A: Yes, use tools like ngrok or expose:
```bash
expose share http://localhost:8000
# Use the generated URL as your webhook URL in the hub
```

## Support

- **Documentation:** [https://docs.headwires.com/translator-client](https://docs.headwires.com/translator-client)
- **Issues:** [https://github.com/headwires/translator-client/issues](https://github.com/headwires/translator-client/issues)
- **Email:** support@headwires.com

---

**Next:** [Static Mode (Legacy)](./STATIC-MODE.md) | [Migration Guide](./MIGRATION-GUIDE.md)
