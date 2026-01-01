# Puke Fire Portal

A mobile-first Progressive Web App (PWA) for the Puke Volunteer Fire Brigade providing calendar management, notices, and leave request functionality with integration to the DLB attendance system.

## Features

- **Calendar** - Day/week/month views, recurring events, ICS export
- **Training Nights** - Auto-generated Monday trainings (shifted to Tuesday for Auckland public holidays)
- **Notices** - Standard, sticky, timed, and urgent notice types with push notifications
- **Leave Requests** - Request leave for up to 3 upcoming trainings with officer approval workflow
- **DLB Integration** - Syncs attendance with the external DLB system
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

1. **Clone the repository**
   ```bash
   git clone https://github.com/jtbnz/pp.git
   cd pp/portal
   ```

2. **Create configuration file**
   ```bash
   cp config/config.example.php config/config.php
   ```

3. **Edit configuration**
   Update `config/config.php` with your settings:
   - `app_url` - Your full application URL (e.g., `https://kiaora.tech/pp`)
   - `base_path` - Subdirectory path if applicable (e.g., `/pp`)
   - `database_path` - SQLite database file path
   - `email` - SMTP settings for sending emails
   - `push` - VAPID keys for push notifications
   - `dlb` - DLB API integration settings

4. **Run setup**
   ```bash
   php setup.php
   ```

5. **Configure web server**

   For Apache, ensure mod_rewrite is enabled. The `.htaccess` file handles URL rewriting.

   For Nginx, add appropriate rewrite rules to your server block.

6. **Set permissions**
   ```bash
   chmod 755 data/
   chmod 644 data/portal.db
   ```

## Directory Structure

```
portal/
├── public/           # Web root
│   ├── index.php     # Main entry point and router
│   ├── sw.js         # Service Worker
│   ├── manifest.json # PWA manifest
│   └── assets/       # CSS, JS, and icons
├── src/
│   ├── Controllers/  # Request handlers
│   ├── Models/       # Database models
│   ├── Services/     # Business logic (Email, Push, DLB sync)
│   ├── Middleware/   # Auth, CSRF, rate limiting
│   └── Helpers/      # Utility functions
├── templates/
│   ├── layouts/      # Main layout template
│   ├── pages/        # Page templates
│   ├── partials/     # Reusable components
│   └── emails/       # Email templates
├── config/           # Configuration (git-ignored)
└── data/             # SQLite database (git-ignored)
```

## User Roles

| Role | Permissions |
|------|-------------|
| **Superadmin** | System-wide management, all brigade access |
| **Admin** | Brigade management, invite members, manage events/notices, extended leave |
| **Officer** | Approve/deny leave requests |
| **Firefighter** | View calendar/notices, request leave for up to 3 trainings |

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

### Push Notifications
- `GET /api/push/key` - Get VAPID public key
- `POST /api/push/subscribe` - Register push subscription
- `POST /api/push/unsubscribe` - Remove push subscription

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

For push notifications, generate VAPID keys:

```bash
npx web-push generate-vapid-keys
```

Add the generated keys to your `config.php`.

## Timezone

All dates/times use `Pacific/Auckland` (NZST/NZDT) timezone.

## DLB Integration

The portal integrates with the DLB attendance system at `https://kiaora.tech/dlb/puke`. Configure the API token in `config.php`:

```php
'dlb' => [
    'enabled' => true,
    'base_url' => 'https://kiaora.tech/dlb/puke',
    'api_token' => 'your-api-token',
],
```

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
