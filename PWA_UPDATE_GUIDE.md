# PWA Update Guide for Puke Portal

## Overview

This guide explains when and how to update the Service Worker cache version to ensure PWA users receive updates.

## Understanding PWA Updates

### Service Worker Cache Mechanism

The Puke Portal uses a Service Worker (`portal/public/sw.js`) to cache assets for offline functionality. The cache version is defined as:

```javascript
const CACHE_NAME = 'puke-portal-v5';
```

### Precached Assets

The following assets are precached during Service Worker installation:

```javascript
const PRECACHE_ASSETS = [
    '/',
    '/offline.html',
    '/assets/css/app.css',
    '/assets/js/app.js',
    '/assets/js/offline-storage.js',
    '/assets/js/push.js',
    '/manifest.json',
    '/assets/icons/icon-192.svg',
    '/assets/icons/icon-512.svg',
    '/assets/icons/badge-72.svg'
];
```

## When to Bump Cache Version

**ALWAYS bump the cache version when:**

1. Any file in `PRECACHE_ASSETS` is modified
2. The Service Worker code itself changes
3. Critical bug fixes need to reach users immediately

**Examples requiring version bump:**
- ✅ Modifying `push.js` (as in PR #24)
- ✅ Updating `app.css` or `app.js`
- ✅ Changing Service Worker fetch/cache strategies
- ✅ Security patches to precached files

**Examples NOT requiring version bump:**
- ❌ Server-side PHP changes only
- ❌ Database schema changes
- ❌ API endpoint modifications (unless they affect precached JS)
- ❌ Template changes that don't affect precached assets

## How to Update

1. **Increment the version number** in `portal/public/sw.js`:
   ```javascript
   const CACHE_NAME = 'puke-portal-v6'; // Increment from v5
   ```

2. **Test the update**:
   - Open the app in a browser
   - Check DevTools → Application → Service Workers
   - Verify the new version is registered
   - Confirm old caches are deleted

3. **Document in commit message**:
   ```
   Bump Service Worker cache version to v6

   Reason: Updated app.js with new feature X
   ```

## Update Behavior

### What Happens When Cache Version Changes

1. **Browser detects change** in `sw.js` file
2. **New Service Worker installs** in the background
3. **Old caches are deleted** during activation
4. **New assets are precached**
5. **Users see the update** (may require page refresh or tab close/reopen)

### User Experience

- **On next visit**: Browser checks for Service Worker updates
- **Update detected**: New SW installs in background
- **Activation**: Happens when all tabs are closed or on refresh
- **No manual action**: Updates are automatic but may require closing all app tabs

### Force Immediate Update

Users can force an immediate update by:
1. Opening DevTools → Application → Service Workers
2. Clicking "Update" or "Skip waiting"
3. Refreshing the page

## PR #24 Example

**Issue**: Add self-service test notifications for iOS debugging

**Changes**:
- Modified `portal/public/assets/js/push.js` (precached asset)
- Modified `portal/src/Controllers/Api/PushApiController.php` (server-side)
- Modified `portal/templates/pages/members/show.php` (server-side)

**Action Required**: YES - bump cache version

**Reason**: `push.js` is in the precache list. Without bumping the version, users would continue using the old cached version and the new test notification button would not work properly.

**Solution**: Bumped `CACHE_NAME` from `'puke-portal-v4'` to `'puke-portal-v5'`

## Best Practices

1. **Always check precache list** before merging changes
2. **Test updates locally** before deploying
3. **Document version bumps** in commit messages
4. **Monitor rollout** after deployment
5. **Keep version numbers sequential** for clarity

## Troubleshooting

### Users Not Seeing Updates

1. Check if cache version was bumped
2. Verify `sw.js` is not cached by server
3. Ask users to close all tabs and reopen
4. Check browser console for Service Worker errors

### Old Cache Persisting

1. Clear site data in DevTools
2. Unregister Service Worker manually
3. Hard refresh (Ctrl+Shift+R / Cmd+Shift+R)
4. Check server isn't caching `sw.js`

## Version History

- **v5** (2026-01-26): Test notification feature (PR #24)
- **v4** (Previous): Base PWA implementation
- **v1-v3**: Early development versions

## References

- [Service Worker Lifecycle](https://web.dev/service-worker-lifecycle/)
- [Workbox Strategies](https://developers.google.com/web/tools/workbox/modules/workbox-strategies)
- [PWA Update Best Practices](https://web.dev/service-worker-updates/)
