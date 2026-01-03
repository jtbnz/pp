<?php
declare(strict_types=1);

/**
 * Poll Detail Page
 *
 * Shows a single poll with voting form and results.
 *
 * Variables:
 * - $poll: array - Poll data with options, voters, and results
 * - $userVotes: array - IDs of options the current user voted for
 * - $hasVoted: bool - Whether user has voted
 * - $canEdit: bool - Whether user can edit (officer+)
 */

global $config;

$pageTitle = $pageTitle ?? $poll['title'];
$appName = $config['app_name'] ?? 'Puke Portal';
$user = currentUser();

// Get flash message if any
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$isOpen = $poll['status'] === 'active';
$inputType = $poll['type'] === 'multi' ? 'checkbox' : 'radio';

// Start output buffering for content
ob_start();
?>

<div class="page-poll-detail">
    <header class="page-header">
        <div class="page-header-content">
            <div>
                <a href="<?= url('/polls') ?>" class="back-link">&larr; Back to Polls</a>
                <h1><?= e($poll['title']) ?></h1>
            </div>
            <?php if ($canEdit): ?>
                <a href="<?= url('/polls/' . $poll['id'] . '/edit') ?>" class="btn btn-secondary">Edit</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Poll info -->
    <div class="poll-info card mb-4">
        <div class="card-body">
            <div class="poll-meta-row">
                <span class="poll-type"><?= $poll['type'] === 'multi' ? 'Multi-choice' : 'Single choice' ?></span>
                <?php if (!$isOpen): ?>
                    <span class="badge badge-secondary">Closed</span>
                <?php elseif ($poll['closes_at']): ?>
                    <?php
                    $closesAt = strtotime($poll['closes_at']);
                    $nowUtc = strtotime(nowUtc());
                    $remaining = $closesAt - $nowUtc;
                    if ($remaining > 0):
                    ?>
                        <span class="poll-expires">Closes in <?= formatPollTimeRemaining($remaining) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($poll['description']): ?>
                <p class="poll-description"><?= nl2br(e($poll['description'])) ?></p>
            <?php endif; ?>

            <div class="poll-stats">
                <span><?= $poll['total_votes'] ?> vote<?= $poll['total_votes'] !== 1 ? 's' : '' ?></span>
                <span>Created by <?= e($poll['created_by_name']) ?></span>
                <span><?= timeAgo($poll['created_at']) ?></span>
            </div>
        </div>
    </div>

    <!-- Voting form / Results -->
    <div class="poll-content card">
        <div class="card-body">
            <?php if ($isOpen): ?>
                <form method="POST" action="<?= url('/polls/' . $poll['id'] . '/vote') ?>" class="poll-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                    <div class="poll-options">
                        <?php foreach ($poll['options'] as $option): ?>
                            <?php
                            $isSelected = in_array($option['id'], $userVotes);
                            $percentage = $option['percentage'] ?? 0;
                            ?>
                            <label class="poll-option <?= $isSelected ? 'selected' : '' ?>">
                                <input type="<?= $inputType ?>"
                                       name="options[]"
                                       value="<?= $option['id'] ?>"
                                       <?= $isSelected ? 'checked' : '' ?>>
                                <div class="option-content">
                                    <div class="option-text"><?= e($option['text']) ?></div>
                                    <div class="option-bar" style="width: <?= $percentage ?>%"></div>
                                    <div class="option-stats">
                                        <span class="option-count"><?= $option['vote_count'] ?></span>
                                        <span class="option-percent"><?= $percentage ?>%</span>
                                    </div>
                                </div>
                            </label>

                            <?php if (!empty($option['voters'])): ?>
                                <div class="option-voters">
                                    <?php foreach ($option['voters'] as $voter): ?>
                                        <span class="voter"><?= e($voter['name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="poll-actions">
                        <button type="submit" class="btn btn-primary">
                            <?= $hasVoted ? 'Update Vote' : 'Submit Vote' ?>
                        </button>
                        <?php if ($poll['type'] === 'multi'): ?>
                            <span class="help-text">Select all that apply</span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php else: ?>
                <!-- Results only (poll is closed) -->
                <div class="poll-results">
                    <?php foreach ($poll['options'] as $option): ?>
                        <?php
                        $isSelected = in_array($option['id'], $userVotes);
                        $percentage = $option['percentage'] ?? 0;
                        ?>
                        <div class="result-option <?= $isSelected ? 'your-vote' : '' ?>">
                            <div class="option-content">
                                <div class="option-text">
                                    <?= e($option['text']) ?>
                                    <?php if ($isSelected): ?>
                                        <span class="your-vote-badge">Your vote</span>
                                    <?php endif; ?>
                                </div>
                                <div class="option-bar" style="width: <?= $percentage ?>%"></div>
                                <div class="option-stats">
                                    <span class="option-count"><?= $option['vote_count'] ?></span>
                                    <span class="option-percent"><?= $percentage ?>%</span>
                                </div>
                            </div>

                            <?php if (!empty($option['voters'])): ?>
                                <div class="option-voters">
                                    <?php foreach ($option['voters'] as $voter): ?>
                                        <span class="voter"><?= e($voter['name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.back-link {
    display: inline-block;
    color: var(--text-secondary);
    text-decoration: none;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.back-link:hover {
    color: var(--primary);
}

.poll-meta-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.poll-type {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.poll-expires {
    font-size: 0.875rem;
    color: var(--warning, #ff9800);
}

.poll-description {
    margin: 1rem 0;
    line-height: 1.6;
}

.poll-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.poll-options {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.poll-option {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    overflow: hidden;
}

.poll-option:hover {
    border-color: var(--primary);
}

.poll-option.selected {
    border-color: var(--primary);
    background: rgba(211, 47, 47, 0.05);
}

.poll-option input {
    margin-top: 0.25rem;
    flex-shrink: 0;
}

.option-content {
    flex: 1;
    position: relative;
    z-index: 1;
}

.option-text {
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.option-bar {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    background: rgba(211, 47, 47, 0.1);
    border-radius: 4px;
    z-index: 0;
    transition: width 0.3s ease;
}

.option-stats {
    display: flex;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.option-voters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.5rem 0 0 2.5rem;
    margin-bottom: 0.5rem;
}

.voter {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    background: var(--bg-secondary);
    border-radius: 4px;
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.poll-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

.help-text {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

/* Results view */
.poll-results {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.result-option {
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    position: relative;
    overflow: hidden;
}

.result-option.your-vote {
    border-color: var(--primary);
    background: rgba(211, 47, 47, 0.05);
}

.your-vote-badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    background: var(--primary);
    color: white;
    border-radius: 4px;
    font-size: 0.75rem;
    margin-left: 0.5rem;
}

.badge-secondary {
    background: var(--text-secondary);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}
</style>

<?php
// Helper function
function formatPollTimeRemaining(int $seconds): string
{
    if ($seconds < 3600) {
        $mins = (int)floor($seconds / 60);
        return $mins . ' min' . ($mins !== 1 ? 's' : '');
    }

    if ($seconds < 86400) {
        $hours = (int)floor($seconds / 3600);
        return $hours . ' hour' . ($hours !== 1 ? 's' : '');
    }

    $days = (int)floor($seconds / 86400);
    return $days . ' day' . ($days !== 1 ? 's' : '');
}

$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
?>
