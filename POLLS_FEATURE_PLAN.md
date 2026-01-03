# Polls Feature Plan

This document outlines the implementation plan for adding a polls feature to Puke Portal.

## Overview

The polls feature allows officers and admins to create polls that brigade members can vote on. Results are visible to all members immediately, with named voting (non-anonymous).

## Requirements

Based on user specifications:

- **Expiry dates**: Polls can have optional end dates after which voting is closed
- **Changeable votes**: Members can change their vote until the poll closes
- **Choice types**: Support both single-choice and multi-choice polls
- **Visibility**: Results are always visible to all members (no hidden results)
- **Named voting**: Votes are not anonymous - members can see who voted for what
- **Permissions**: Officers and above can create polls
- **Notifications**: Push notifications when new polls are created

## Database Schema

### polls table

```sql
CREATE TABLE polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    type TEXT NOT NULL DEFAULT 'single', -- 'single' or 'multi'
    status TEXT NOT NULL DEFAULT 'active', -- 'active', 'closed'
    closes_at TEXT, -- UTC datetime, null = no expiry
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL, -- UTC
    updated_at TEXT, -- UTC
    FOREIGN KEY (brigade_id) REFERENCES brigades(id),
    FOREIGN KEY (created_by) REFERENCES members(id)
);
```

### poll_options table

```sql
CREATE TABLE poll_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);
```

### poll_votes table

```sql
CREATE TABLE poll_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    voted_at TEXT NOT NULL, -- UTC
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id),
    UNIQUE(poll_id, option_id, member_id) -- One vote per option per member
);

-- For single-choice polls, enforce one vote per poll via application logic
-- For multi-choice polls, members can vote for multiple options
```

## Implementation Steps

### Phase 1: Database & Model

1. Create migration to add polls tables to schema.sql
2. Create `Poll` model with methods:
   - `create(array $data): int`
   - `findById(int $id): ?array`
   - `findActive(int $brigadeId): array`
   - `findAll(int $brigadeId, array $filters = []): array`
   - `update(int $id, array $data): bool`
   - `close(int $id): bool`
   - `delete(int $id): bool`
   - `addOption(int $pollId, string $text, int $order): int`
   - `getOptions(int $pollId): array`
   - `vote(int $pollId, int $optionId, int $memberId): bool`
   - `removeVote(int $pollId, int $optionId, int $memberId): bool`
   - `clearVotes(int $pollId, int $memberId): bool` (for single-choice re-voting)
   - `getVotes(int $pollId): array`
   - `getResults(int $pollId): array`
   - `hasVoted(int $pollId, int $memberId): bool`
   - `getMemberVotes(int $pollId, int $memberId): array`

### Phase 2: Controllers

1. Create `PollController` for member-facing poll views:
   - `index()` - List active polls
   - `show(string $id)` - View poll with results
   - `vote(string $id)` - Submit vote (POST)

2. Create `PollApiController` for AJAX interactions:
   - `vote(string $id)` - Submit/change vote
   - `results(string $id)` - Get current results

3. Add admin poll management to `AdminController`:
   - `polls()` - List all polls
   - `createPollForm()` - Show create form
   - `createPoll()` - Create poll (POST)
   - `editPoll(string $id)` - Edit poll form
   - `updatePoll(string $id)` - Update poll (PUT)
   - `closePoll(string $id)` - Close poll (POST)
   - `deletePoll(string $id)` - Delete poll (DELETE)

### Phase 3: Routes

Add routes to Router.php:

```php
// Member poll routes
$router->get('/polls', [PollController::class, 'index']);
$router->get('/polls/{id}', [PollController::class, 'show']);
$router->post('/polls/{id}/vote', [PollController::class, 'vote']);

// API routes
$router->post('/api/polls/{id}/vote', [PollApiController::class, 'vote']);
$router->get('/api/polls/{id}/results', [PollApiController::class, 'results']);

// Admin routes (require officer role)
$router->get('/admin/polls', [AdminController::class, 'polls']);
$router->get('/admin/polls/create', [AdminController::class, 'createPollForm']);
$router->post('/admin/polls', [AdminController::class, 'createPoll']);
$router->get('/admin/polls/{id}', [AdminController::class, 'editPoll']);
$router->put('/admin/polls/{id}', [AdminController::class, 'updatePoll']);
$router->post('/admin/polls/{id}/close', [AdminController::class, 'closePoll']);
$router->delete('/admin/polls/{id}', [AdminController::class, 'deletePoll']);
```

### Phase 4: Templates

1. Member views:
   - `templates/pages/polls/index.php` - Poll list with voting status
   - `templates/pages/polls/show.php` - Poll detail with vote form and results

2. Admin views:
   - `templates/pages/admin/polls/index.php` - Manage polls list
   - `templates/pages/admin/polls/create.php` - Create poll form
   - `templates/pages/admin/polls/edit.php` - Edit poll form

3. Partials:
   - `templates/partials/poll-card.php` - Poll summary card for lists
   - `templates/partials/poll-results.php` - Results display with voter names

### Phase 5: UI/UX Features

1. **Poll List View**:
   - Show active polls with voting status (voted/not voted)
   - Show expiry countdown for time-limited polls
   - Sort by creation date (newest first)
   - Filter: active/closed/all

2. **Poll Detail View**:
   - Poll title and description
   - Options with radio buttons (single) or checkboxes (multi)
   - Submit vote button
   - "Change Vote" option if already voted
   - Real-time results bar chart
   - List of voters per option (with member names)
   - Expiry countdown or "Closed" badge

3. **Admin Create/Edit**:
   - Title and description fields
   - Type selector (single/multi choice)
   - Dynamic option fields (add/remove)
   - Optional closes_at date picker
   - Preview before publish

### Phase 6: Push Notifications

1. Send push notification when new poll is created
2. Optional: Reminder notification before poll closes
3. Use existing `NotificationService` pattern

### Phase 7: Audit Logging

Add audit log entries for:
- `poll.create` - Poll created
- `poll.update` - Poll updated
- `poll.close` - Poll closed
- `poll.delete` - Poll deleted
- `poll.vote` - Vote cast (optional, may be noisy)

## Navigation Integration

1. Add "Polls" link to main navigation menu
2. Add polls count badge to nav if there are unvoted active polls
3. Add "Manage Polls" to admin sidebar

## Auto-Close Logic

Implement a check in `Poll::findActive()` to auto-close expired polls:

```php
public function findActive(int $brigadeId): array
{
    // First, close any expired polls
    $this->closeExpired($brigadeId);

    // Then return active polls
    // ...
}

private function closeExpired(int $brigadeId): void
{
    $sql = "UPDATE polls SET status = 'closed'
            WHERE brigade_id = ?
            AND status = 'active'
            AND closes_at IS NOT NULL
            AND closes_at < ?";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$brigadeId, nowUtc()]);
}
```

## Mobile Considerations

- Touch-friendly option selection
- Clear visual feedback on vote submission
- Results chart responsive to screen width
- Voter names collapsible/expandable

## Future Enhancements (Not in initial scope)

- Poll templates for recurring questions
- Anonymous voting option (if requested)
- Poll categories/tags
- Export poll results
- Poll comments/discussion
