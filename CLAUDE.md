# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a mobile web portal app for a fire brigade. The app serves firefighters and officers with calendar, notices, and leave management features.

## User Roles

- **Admin**: Can invite users via one-time codes, regenerate codes, renew access (up to 5 years), and manage extended leave
- **Officer**: Can approve leave requests from firefighters
- **Firefighter**: Standard user who can request leave for training nights

## Core Features

### Calendar
- Shared calendar with day/week/month views
- One-off and recurring events
- Export events to mobile phone calendar
- Training nights are typically Mondays, moved to Tuesday if Monday is an Auckland public holiday
- Auto-populate training nights for 12 months ahead

### Notices
- Notice board with sticky notices
- Timed notices with display from/to date and time

### Leave Requests
- Users can request leave for up to 3 upcoming training nights
- Leave sits in pending state until officer approves
- Extended leave must be entered by an admin
- Integrates with external attendance system at https://kiaora.tech/dlb/puke/attendance (related repo: https://github.com/jtbnz/dlb)

### Landing Page
- Upcoming events with next training night first
- Notices display

## Technical Considerations

- Timezone: Always New Zealand time
- Mobile-first responsive design
- Attendance system integration requires token-based API access (see separate integration plan)
- Pre-populated musters remain invisible until muster day
