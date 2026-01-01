<?php
declare(strict_types=1);

/**
 * Account Activation Page Template
 *
 * Allows new members to set their name and optional PIN.
 */

global $config;

$pageTitle = 'Complete Your Registration';
$appName = $config['app_name'] ?? 'Puke Portal';
$pinLength = $config['auth']['pin_length'] ?? 6;

// Start output buffering for content
ob_start();
?>

<div class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-logo">
                <div class="auth-logo-placeholder">
                    <span class="check-icon">&#10003;</span>
                </div>
            </div>
            <h1 class="auth-title">Welcome!</h1>
            <p class="auth-subtitle">Complete your registration for <?= e($brigadeName ?? 'the portal') ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert">
                <span class="alert-icon">&#9888;</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="auth-info">
            <p><strong>Email:</strong> <?= e($email ?? '') ?></p>
        </div>

        <form action="<?= url('/auth/activate') ?>" method="POST" class="auth-form">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label for="name" class="form-label">Your Full Name <span class="required">*</span></label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= e($name ?? '') ?>"
                    class="form-input"
                    placeholder="John Smith"
                    autocomplete="name"
                    required
                    autofocus
                >
                <p class="form-hint">This is how your name will appear to other members</p>
            </div>

            <div class="form-section">
                <h3 class="form-section-title">Quick Login PIN (Optional)</h3>
                <p class="form-section-desc">
                    Set a <?= $pinLength ?>-digit PIN for faster login next time.
                    You can always use a magic link instead.
                </p>

                <div class="form-group">
                    <label for="pin" class="form-label">PIN</label>
                    <input
                        type="password"
                        id="pin"
                        name="pin"
                        class="form-input form-input-pin"
                        placeholder="<?= str_repeat('*', $pinLength) ?>"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="<?= $pinLength ?>"
                        autocomplete="new-password"
                    >
                </div>

                <div class="form-group">
                    <label for="pin_confirm" class="form-label">Confirm PIN</label>
                    <input
                        type="password"
                        id="pin_confirm"
                        name="pin_confirm"
                        class="form-input form-input-pin"
                        placeholder="<?= str_repeat('*', $pinLength) ?>"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="<?= $pinLength ?>"
                        autocomplete="new-password"
                    >
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block">
                Complete Registration
            </button>
        </form>
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
    margin-bottom: 1.5rem;
}

.auth-logo-placeholder {
    width: 80px;
    height: 80px;
    margin: 0 auto;
    background: #4CAF50;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.check-icon {
    font-size: 2.5rem;
    color: white;
}

.auth-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text);
    margin: 1rem 0 0.25rem;
}

.auth-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin: 0;
}

.auth-info {
    background: #F5F5F5;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
}

.auth-info p {
    margin: 0;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.auth-form {
    margin-bottom: 1rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text);
    margin-bottom: 0.5rem;
}

.required {
    color: var(--color-error, #D32F2F);
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

.form-input-pin {
    text-align: center;
    letter-spacing: 0.5em;
    font-size: 1.25rem;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    margin: 0.5rem 0 0;
}

.form-section {
    background: #FAFAFA;
    border: 1px solid var(--color-border, #e0e0e0);
    border-radius: 0.5rem;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}

.form-section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text);
    margin: 0 0 0.5rem;
}

.form-section-desc {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    margin: 0 0 1rem;
    line-height: 1.5;
}

.form-section .form-group:last-child {
    margin-bottom: 0;
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
</style>

<script>
// Auto-advance PIN fields and validate
document.addEventListener('DOMContentLoaded', function() {
    const pinInput = document.getElementById('pin');
    const pinConfirmInput = document.getElementById('pin_confirm');
    const pinLength = <?= $pinLength ?>;

    [pinInput, pinConfirmInput].forEach(input => {
        input.addEventListener('input', function(e) {
            // Allow only digits
            this.value = this.value.replace(/\D/g, '').slice(0, pinLength);
        });
    });

    // Auto-focus confirm when PIN is complete
    pinInput.addEventListener('input', function() {
        if (this.value.length === pinLength) {
            pinConfirmInput.focus();
        }
    });
});
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
