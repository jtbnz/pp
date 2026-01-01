<?php
declare(strict_types=1);

/**
 * Login Page Template
 *
 * Mobile-first email entry form for magic link authentication.
 */

global $config;

$pageTitle = 'Sign In';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Start output buffering for content
ob_start();
?>

<div class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-logo">
                <?php if (!empty($config['theme']['logo_url'] ?? '')): ?>
                    <img src="<?= e($config['theme']['logo_url']) ?>" alt="<?= e($appName) ?>" class="auth-logo-img">
                <?php else: ?>
                    <div class="auth-logo-placeholder">
                        <span class="fire-icon">&#128293;</span>
                    </div>
                <?php endif; ?>
            </div>
            <h1 class="auth-title"><?= e($appName) ?></h1>
            <p class="auth-subtitle">Fire brigade member portal</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert">
                <span class="alert-icon">&#9888;</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" role="alert">
                <span class="alert-icon">&#10003;</span>
                <span><?= e($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($expired ?? false): ?>
            <div class="alert alert-warning" role="alert">
                <span class="alert-icon">&#8987;</span>
                <span>Your access has expired. Please contact your administrator.</span>
            </div>
        <?php endif; ?>

        <form action="/auth/login" method="POST" class="auth-form">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= e($email ?? '') ?>"
                    class="form-input"
                    placeholder="you@example.com"
                    autocomplete="email"
                    inputmode="email"
                    required
                    autofocus
                >
                <p class="form-hint">We'll send you a magic link to sign in</p>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block">
                Send Magic Link
            </button>
        </form>

        <div class="auth-footer">
            <p class="auth-footer-text">
                Don't have an account? Contact your brigade administrator.
            </p>
        </div>
    </div>
</div>

<style>
.auth-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark, #B71C1C) 100%);
}

.auth-container {
    width: 100%;
    max-width: 400px;
    background: white;
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
}

.auth-header {
    text-align: center;
    margin-bottom: 2rem;
}

.auth-logo {
    margin-bottom: 1rem;
}

.auth-logo-img {
    width: 80px;
    height: 80px;
    object-fit: contain;
}

.auth-logo-placeholder {
    width: 80px;
    height: 80px;
    margin: 0 auto;
    background: var(--color-primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.fire-icon {
    font-size: 2.5rem;
}

.auth-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text);
    margin: 0 0 0.25rem;
}

.auth-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin: 0;
}

.auth-form {
    margin-bottom: 1.5rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text);
    margin-bottom: 0.5rem;
}

.form-input {
    width: 100%;
    padding: 0.875rem 1rem;
    font-size: 1rem;
    border: 2px solid var(--color-border, #e0e0e0);
    border-radius: 0.5rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.form-input::placeholder {
    color: var(--color-text-secondary);
    opacity: 0.7;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    margin: 0.5rem 0 0;
}

.btn-block {
    display: block;
    width: 100%;
}

.btn-lg {
    padding: 1rem 1.5rem;
    font-size: 1rem;
}

.alert {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
}

.alert-icon {
    flex-shrink: 0;
    font-size: 1.25rem;
    line-height: 1;
}

.alert-error {
    background: #FFEBEE;
    color: #C62828;
    border: 1px solid #FFCDD2;
}

.alert-success {
    background: #E8F5E9;
    color: #2E7D32;
    border: 1px solid #C8E6C9;
}

.alert-warning {
    background: #FFF3E0;
    color: #E65100;
    border: 1px solid #FFE0B2;
}

.auth-footer {
    text-align: center;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border, #e0e0e0);
}

.auth-footer-text {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin: 0;
}

/* Mobile optimizations */
@media (max-width: 480px) {
    .auth-page {
        padding: 0;
        align-items: flex-start;
    }

    .auth-container {
        min-height: 100vh;
        border-radius: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .auth-container {
        background: var(--color-surface-dark, #1e1e1e);
    }

    .auth-title {
        color: var(--color-text-dark, #ffffff);
    }

    .form-input {
        background: var(--color-surface-dark, #2d2d2d);
        border-color: var(--color-border-dark, #404040);
        color: var(--color-text-dark, #ffffff);
    }
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
