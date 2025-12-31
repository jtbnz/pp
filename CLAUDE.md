# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Puke Portal - A mobile-first PWA for the Puke Volunteer Fire Brigade providing calendar, notices, and leave management with integration to the dlb attendance system.

## Technology Stack

- **Backend:** PHP 8.x (strict types), SQLite3
- **Frontend:** Vanilla JavaScript (ES6+), CSS3 with custom properties
- **Architecture:** PWA with Service Worker, IndexedDB for offline, SSE for real-time updates
- **Deployment:** Same server as dlb (kiaora.tech)

This stack matches the dlb project for seamless integration. No build tools required.

## Key Files

- [spec.md](spec.md) - Comprehensive project specification
- [dlb-api-integration.md](dlb-api-integration.md) - API spec for dlb attendance system integration

## Directory Structure

```
portal/
├── public/           # Web root (index.php, sw.js, manifest.json, assets/)
├── src/              # PHP (Controllers/, Models/, Services/, Middleware/, Helpers/)
├── templates/        # Views (layouts/, pages/, partials/)
├── config/           # config.php (git-ignored)
├── data/             # SQLite database
└── tests/
```

## User Roles

- **Super Admin**: System-wide brigade management
- **Admin**: Brigade-level - invite users, manage events/notices, extended leave
- **Officer**: Approve leave requests from firefighters
- **Firefighter**: Request leave for up to 3 upcoming trainings

## Core Features

1. **Calendar** - Day/week/month views, recurring events, ICS export, auto-generated training nights (Mondays, shifted to Tuesday for Auckland public holidays)
2. **Notices** - Standard, sticky, timed, urgent types with push notifications
3. **Leave Requests** - Request up to 3 trainings, officer approval, syncs with dlb
4. **DLB Integration** - Pre-populate invisible musters, auto-reveal on training day

## Attendance Status Codes

- `I` - In Attendance
- `L` - Leave (approved)
- `A` - Absent (no leave requested)

## Authentication

Magic link email invites, optional PIN for quick re-auth. Access valid for 5 years.

## External Integration

- **DLB Attendance:** https://kiaora.tech/dlb/puke/attendance
- **DLB Repo:** https://github.com/jtbnz/dlb
- Token-based API (see dlb-api-integration.md)

## Timezone

Always Pacific/Auckland (NZST/NZDT)
