# Puke Fire Portal

A mobile-first Progressive Web App (PWA) for the Puke Volunteer Fire Brigade providing calendar management, notices, and leave request functionality with integration to the DLB attendance system.

## Features

- **Calendar** - Day/week/month views, recurring events, ICS export
- **Training Nights** - Auto-generated Monday trainings (shifted to Tuesday for Auckland public holidays)
- **Notices** - Standard, sticky, timed, and urgent notice types with push notifications
- **Leave Requests** - Request leave for up to 3 upcoming trainings with officer approval workflow
- **Notification Center** - In-app notifications with badge, preferences, and mark-as-read functionality
- **Attendance Statistics** - Training and callout attendance gauges with rolling 12-month stats
- **DLB Integration** - Syncs attendance with the external DLB system via API and webhook
- **PWA Support** - Offline functionality, push notifications, installable

## Technology Stack

- **Backend:** PHP 8.x with strict types
- **Database:** SQLite3
- **Frontend:** Vanilla JavaScript (ES6+), CSS3 with custom properties
- **Architecture:** PWA with Service Worker, IndexedDB for offline storage

No build tools required.

## Requirements

- PHP 8.0 or higher
- SQLite3 extension
- OpenSSL extension (for push notifications)
- cURL extension (for DLB API integration)
- Web server with URL rewriting (Apache/Nginx)

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/jtbnz/pp.git
cd pp/portal
```

### 2. Create configuration file

```bash
cp config/config.example.php config/config.php
```

### 3. Edit configuration

Update `config/config.php` with your settings:

| Setting | Description | Example |
|---------|-------------|---------|
| `app_url` | Full application URL | `https://kiaora.tech/pp` |
| `base_path` | Subdirectory path (if applicable) | `/pp` or empty string |
| `database_path` | SQLite database file path | `__DIR__ . '/../data/portal.db'` |
| `email` | SMTP settings for sending emails | See config.example.php |
| `push` | VAPID keys for push notifications | See [Generating VAPID Keys](#generating-vapid-keys) |
| `dlb` | DLB API integration settings | See [DLB Integration](#dlb-integration) |

**Important subdirectory configuration:** If deploying to a subdirectory like `https://kiaora.tech/pp/`, you MUST set:
```php
'app_url' => 'https://kiaora.tech/pp',
'base_path' => '/pp',
```

### 4. Create data directories

```bash
mkdir -p data/logs
chmod 755 data/
chmod 755 data/logs/
```

### 5. Run setup script

```bash
php setup.php
```

This will:
- Create the SQLite database with all required tables
- Set up the default brigade
- Create the initial superadmin user

**First-time setup:** The script will prompt you for superadmin details or you can set environment variables:
```bash
ADMIN_EMAIL=admin@example.com ADMIN_NAME="Admin User" php setup.php
```

### 6. Configure web server

**For Apache:**
- Ensure `mod_rewrite` is enabled: `a2enmod rewrite`
- The `.htaccess` file in `public/` handles URL rewriting
- Set `AllowOverride All` for the document root

**For Nginx:**
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/pp/portal/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(ht|git) {
        deny all;
    }
}
```

### 7. Set file permissions

```bash
chmod 755 data/
chmod 644 data/portal.db
chmod 755 data/logs/
```

### 8. Verify installation

Visit your app URL. You should see the login page. Use the magic link sent to your admin email to log in.

### Troubleshooting

| Issue | Solution |
|-------|----------|
| 500 Internal Server Error | Check `data/logs/app.log` and PHP error logs |
| Redirects to wrong URL | Verify `base_path` matches your subdirectory |
| Database errors | Ensure `data/` directory is writable |
| Push notifications not working | Verify VAPID keys are correctly configured |
| Emails not sending | Check SMTP credentials and test with debug mode |

## Directory Structure

```
portal/
├── public/           # Web root
│   ├── index.php     # Main entry point, router, and dynamic manifest.json
│   ├── sw.js         # Service Worker
│   └── assets/       # CSS, JS, and icons
│       ├── css/      # Stylesheets (app.css, notifications.css, etc.)
│       ├── js/       # JavaScript (app.js, calendar.js, attendance.js, etc.)
│       └── icons/    # PWA icons
├── src/
│   ├── Controllers/  # Request handlers
│   │   └── Api/      # API controllers (Member, Notification, Push, etc.)
│   ├── Models/       # Database models
│   ├── Services/     # Business logic (Email, Push, Notification, Attendance, DLB)
│   ├── Middleware/   # Auth, CSRF, rate limiting
│   └── Helpers/      # Utility functions
├── templates/
│   ├── layouts/      # Main layout template
│   ├── pages/        # Page templates
│   ├── partials/     # Reusable components
│   └── emails/       # Email templates
├── config/           # Configuration (git-ignored)
└── data/             # SQLite database and logs (git-ignored)
```

**Note:** The PWA `manifest.json` is dynamically generated by the router using `app_name` from config.

## User Roles

The system uses a **dual-role architecture** separating operational duties from administrative access:

### Operational Role (`operational_role`)

| Role | Permissions |
|------|-------------|
| **Firefighter** | View calendar/notices, request leave for up to 3 trainings, view own attendance stats |
| **Officer** | All firefighter permissions + approve/deny leave requests |

### Admin Access (`is_admin`)

A separate boolean flag grants administrative capabilities:
- Manage members (invite, edit, deactivate)
- Manage events and notices
- Manage extended leave
- Access brigade settings
- View all member attendance

### System Role

| Role | Permissions |
|------|-------------|
| **Superadmin** | System-wide management, all brigade access |

### Examples

| Member | Operational Role | Admin Access | Result |
|--------|-----------------|:------------:|--------|
| Station Officer | officer | No | Can approve leave, no admin panel |
| Admin Secretary | firefighter | Yes | Has admin panel, cannot approve leave |
| Chief Fire Officer | officer | Yes | Full access: approve leave + admin panel |

### Leave Request Notifications

When a firefighter submits a leave request, notifications are sent to **officers only** (not admins unless they are also officers). This includes:
- Push notifications (if enabled and subscribed)
- In-app notifications in the notification center
- Email notifications (if email is configured)

## Authentication

- Magic link email invitations for new members
- Optional PIN for quick re-authentication
- Session-based authentication with 24-hour timeout
- Access valid for 5 years per invitation

## API Endpoints

### Calendar
- `GET /api/events` - List events
- `GET /api/events/{id}` - Get event details
- `POST /api/events` - Create event (admin)
- `PUT /api/events/{id}` - Update event (admin)
- `DELETE /api/events/{id}` - Delete event (admin)

### Leave Requests
- `GET /api/leave` - List leave requests
- `POST /api/leave` - Submit leave request
- `PUT /api/leave/{id}/approve` - Approve request (officer)
- `PUT /api/leave/{id}/deny` - Deny request (officer)
- `DELETE /api/leave/{id}` - Cancel request

### Notices
- `GET /api/notices` - List active notices
- `POST /api/notices` - Create notice (admin)
- `PUT /api/notices/{id}` - Update notice (admin)
- `DELETE /api/notices/{id}` - Delete notice (admin)

### Notifications (In-App)
- `GET /api/notifications` - List notifications (paginated)
- `GET /api/notifications/unread-count` - Get unread badge count
- `PATCH /api/notifications/{id}/read` - Mark as read
- `POST /api/notifications/mark-all-read` - Mark all as read
- `DELETE /api/notifications/{id}` - Delete notification
- `DELETE /api/notifications/clear` - Clear all notifications
- `GET /api/notifications/preferences` - Get preferences
- `PUT /api/notifications/preferences` - Update preferences

### Push Notifications
- `GET /api/push/key` - Get VAPID public key
- `POST /api/push/subscribe` - Register push subscription
- `POST /api/push/unsubscribe` - Remove push subscription
- `POST /api/push/test` - Send test notification (push + in-app)
- `GET /api/push/status` - Get subscription status

### Attendance
- `GET /api/members/{id}/attendance` - Get attendance statistics
- `GET /api/members/{id}/attendance/recent` - Get recent events
- `POST /api/attendance/sync` - Trigger DLB sync (admin)

### Webhooks
- `POST /api/webhook/dlb/attendance` - Receive attendance data from DLB

## Configuration Options

Key configuration settings in `config/config.php`:

```php
return [
    'app_name' => 'Puke Fire Portal',
    'app_url' => 'https://kiaora.tech/pp',
    'base_path' => '/pp',
    'debug' => false,
    'timezone' => 'Pacific/Auckland',

    'training' => [
        'default_day' => 1,           // Monday
        'default_time' => '19:00',
        'duration_hours' => 2,
    ],

    'leave' => [
        'max_pending' => 3,           // Max concurrent requests
    ],

    'email' => [
        'driver' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        // ...
    ],

    'push' => [
        'enabled' => true,
        'public_key' => '...',        // VAPID public key
        'private_key' => '...',       // VAPID private key
    ],
];
```

## Generating VAPID Keys

For push notifications, you need to generate VAPID (Voluntary Application Server Identification) keys. These can be generated on any machine and copied to your server.

### Option 1: Using Node.js (if available)

```bash
npx web-push generate-vapid-keys
```

### Option 2: Using OpenSSL

```bash
# Step 1: Generate the EC private key
openssl ecparam -genkey -name prime256v1 -noout -out vapid_private.pem

# Step 2: Create DER files (suppresses spurious output)
openssl ec -in vapid_private.pem -outform DER -out vapid_private.der 2>/dev/null
openssl ec -in vapid_private.pem -pubout -outform DER -out vapid_public.der 2>/dev/null

# Step 3: Extract private key (Base64 URL-safe format)
tail -c +8 vapid_private.der | head -c 32 | base64 -w 0 | tr -d '=' | tr '/+' '_-' && echo

# Step 4: Extract public key (Base64 URL-safe format)
tail -c 65 vapid_public.der | base64 -w 0 | tr -d '=' | tr '/+' '_-' && echo

# Step 5: Clean up temporary files
rm vapid_private.der vapid_public.der
```

Steps 3 and 4 each output a single Base64 URL-safe encoded string. Copy each to your config.

### Option 3: Online Generator

Use a web-based VAPID key generator (search for "VAPID key generator online").

### Option 4: Using PHP

Create a temporary PHP script and run it once:

```php
<?php
// generate-vapid-keys.php
$keyPair = sodium_crypto_box_keypair();
$publicKey = sodium_crypto_box_publickey($keyPair);
$privateKey = sodium_crypto_box_secretkey($keyPair);

echo "Public Key: " . rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=') . "\n";
echo "Private Key: " . rtrim(strtr(base64_encode($privateKey), '+/', '-_'), '=') . "\n";
```

```bash
php generate-vapid-keys.php
```

### Adding Keys to Configuration

Add the generated keys to your `config.php`:

```php
'push' => [
    'enabled' => true,
    'subject' => 'mailto:admin@yourdomain.com',
    'public_key' => 'YOUR_PUBLIC_KEY_HERE',
    'private_key' => 'YOUR_PRIVATE_KEY_HERE',
],
```

**Important:** Keep your private key secret. Never commit it to version control.

### Testing VAPID Keys

To verify your VAPID keys are correctly configured, download and run the test script:

```bash
# Download the test script (not included in deployment)
curl -O https://raw.githubusercontent.com/jtbnz/pp/main/portal/test-vapid.php

# Run the test
php test-vapid.php

# Delete after testing
rm test-vapid.php
```

The script validates key format, length, and base64url encoding.

### Enabling Push Notifications for Users

Once VAPID keys are configured:

1. Users must grant browser permission for notifications
2. The browser must support the Push API (most modern browsers do)
3. Users can subscribe/unsubscribe from their profile settings

Push notifications are sent for:
- New leave requests (to Officers only)
- Leave request approvals/denials (to the requesting member)
- Urgent notices (to all brigade members)
- Test notifications (creates both push notification and in-app notification)

## Email Configuration

Configure email settings in `config.php` for:
- Magic link invitations
- Leave request notifications
- Password reset (if implemented)

```php
'email' => [
    'driver' => 'smtp',           // smtp, sendmail, or mail
    'host' => 'smtp.example.com',
    'port' => 587,
    'encryption' => 'tls',        // tls, ssl, or null
    'username' => 'your-smtp-user',
    'password' => 'your-smtp-password',
    'from_address' => 'portal@yourdomain.com',
    'from_name' => 'Puke Fire Portal',
],
```

**Testing email:** Set `'debug' => true` in config to see email output in logs instead of sending.

## Timezone

All dates/times use `Pacific/Auckland` (NZST/NZDT) timezone.

## DLB Integration

The portal integrates with the DLB attendance system at `https://kiaora.tech/dlb/puke`. Configure in `config.php`:

```php
'dlb' => [
    'enabled' => true,
    'base_url' => 'https://kiaora.tech/dlb/puke',
    'api_token' => 'your-api-token',
    'webhook_secret' => 'your-webhook-secret',  // For receiving data from DLB
],
```

### Integration Features

- **Attendance Sync** - Pull attendance history from DLB on demand
- **Webhook Support** - Receive real-time attendance updates from DLB
- **Member Mapping** - Link portal members to DLB member IDs
- **Statistics** - Rolling 12-month attendance calculations for training and callouts

## Development

### Running Locally

```bash
php -S localhost:8000 -t public
```

### Checking PHP Syntax

```bash
find src -name "*.php" -exec php -l {} \;
```

## Security Considerations

- CSRF protection on all forms
- Rate limiting on authentication endpoints
- Session cookies with secure, httponly, and samesite flags
- Input validation and output escaping
- Prepared statements for all database queries

## License

Private project for Puke Volunteer Fire Brigade.
