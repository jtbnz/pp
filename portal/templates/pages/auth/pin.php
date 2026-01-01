<?php
declare(strict_types=1);

/**
 * PIN Login Page Template
 *
 * Quick PIN entry for returning members.
 */

global $config;

$pageTitle = 'Enter PIN';
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
                    <span class="lock-icon">&#128274;</span>
                </div>
            </div>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle"><?= e($email ?? '') ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert">
                <span class="alert-icon">&#9888;</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="/auth/pin" method="POST" class="auth-form" id="pin-form">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label for="pin" class="form-label">Enter your <?= $pinLength ?>-digit PIN</label>
                <div class="pin-input-container">
                    <input
                        type="password"
                        id="pin"
                        name="pin"
                        class="form-input form-input-pin"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="<?= $pinLength ?>"
                        autocomplete="current-password"
                        required
                        autofocus
                    >
                </div>
                <div class="pin-dots" aria-hidden="true">
                    <?php for ($i = 0; $i < $pinLength; $i++): ?>
                        <span class="pin-dot" data-index="<?= $i ?>"></span>
                    <?php endfor; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block" id="submit-btn" disabled>
                Sign In
            </button>
        </form>

        <div class="auth-alternatives">
            <p>Forgot your PIN?</p>
            <a href="/auth/magic-link" class="btn btn-outline btn-block">
                Send Magic Link Instead
            </a>
            <a href="/auth/login" class="auth-link">
                Use a different email
            </a>
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

.lock-icon {
    font-size: 2rem;
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
    word-break: break-all;
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
    margin-bottom: 0.75rem;
    text-align: center;
}

.pin-input-container {
    position: relative;
}

.form-input-pin {
    width: 100%;
    padding: 1rem;
    font-size: 1.5rem;
    text-align: center;
    letter-spacing: 1em;
    border: 2px solid var(--color-border, #e0e0e0);
    border-radius: 0.5rem;
    background: #FAFAFA;
    color: transparent;
    caret-color: var(--color-primary);
    box-sizing: border-box;
}

.form-input-pin:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.pin-dots {
    display: flex;
    justify-content: center;
    gap: 0.75rem;
    margin-top: -2.5rem;
    padding-bottom: 1rem;
    pointer-events: none;
}

.pin-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid var(--color-border, #e0e0e0);
    background: white;
    transition: all 0.15s ease;
}

.pin-dot.filled {
    background: var(--color-primary);
    border-color: var(--color-primary);
    transform: scale(1.1);
}

.btn-block {
    display: block;
    width: 100%;
}

.btn-lg {
    padding: 1rem 1.5rem;
    font-size: 1rem;
}

.btn-outline {
    background: transparent;
    border: 2px solid var(--color-primary);
    color: var(--color-primary);
}

.btn-outline:hover {
    background: var(--color-primary);
    color: white;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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

.auth-alternatives {
    text-align: center;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border, #e0e0e0);
}

.auth-alternatives p {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin: 0 0 1rem;
}

.auth-alternatives .btn {
    margin-bottom: 1rem;
}

.auth-link {
    display: inline-block;
    font-size: 0.875rem;
    color: var(--color-primary);
    text-decoration: none;
}

.auth-link:hover {
    text-decoration: underline;
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
document.addEventListener('DOMContentLoaded', function() {
    const pinInput = document.getElementById('pin');
    const submitBtn = document.getElementById('submit-btn');
    const pinDots = document.querySelectorAll('.pin-dot');
    const pinLength = <?= $pinLength ?>;
    const form = document.getElementById('pin-form');

    // Update dots based on input
    function updateDots(length) {
        pinDots.forEach((dot, index) => {
            if (index < length) {
                dot.classList.add('filled');
            } else {
                dot.classList.remove('filled');
            }
        });

        // Enable/disable submit button
        submitBtn.disabled = length !== pinLength;
    }

    pinInput.addEventListener('input', function(e) {
        // Allow only digits
        this.value = this.value.replace(/\D/g, '').slice(0, pinLength);
        updateDots(this.value.length);

        // Auto-submit when PIN is complete
        if (this.value.length === pinLength) {
            // Small delay for visual feedback
            setTimeout(() => {
                form.submit();
            }, 200);
        }
    });

    // Handle paste
    pinInput.addEventListener('paste', function(e) {
        e.preventDefault();
        const pastedText = (e.clipboardData || window.clipboardData).getData('text');
        const digits = pastedText.replace(/\D/g, '').slice(0, pinLength);
        this.value = digits;
        updateDots(digits.length);

        if (digits.length === pinLength) {
            setTimeout(() => {
                form.submit();
            }, 200);
        }
    });

    // Shake animation on error
    <?php if (!empty($error)): ?>
    pinInput.classList.add('shake');
    setTimeout(() => pinInput.classList.remove('shake'), 500);
    <?php endif; ?>
});
</script>

<style>
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-10px); }
    75% { transform: translateX(10px); }
}

.shake {
    animation: shake 0.5s ease-in-out;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
