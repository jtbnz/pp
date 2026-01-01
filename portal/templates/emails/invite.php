<?php
declare(strict_types=1);

/**
 * Invite Email Template
 *
 * Sent to new members when they're invited to join the portal.
 *
 * Variables:
 * - $brigadeName: Name of the brigade
 * - $verifyUrl: Magic link verification URL
 * - $appName: Application name
 * - $appUrl: Application URL
 * - $expiryDays: Number of days until link expires
 */

$primaryColor = '#D32F2F';
// Use verifyUrl (from EmailService) but fall back to magicLinkUrl for backwards compatibility
$magicLinkUrl = $verifyUrl ?? $magicLinkUrl ?? '#';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're Invited to <?= e($brigadeName ?? 'the Portal') ?></title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f5f5f5;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color: <?= e($primaryColor) ?>; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="color: white; margin: 0; font-size: 24px; font-weight: 700;">
                                <?= e($appName) ?>
                            </h1>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="background-color: white; padding: 40px 30px;">
                            <h2 style="margin: 0 0 20px; color: #333; font-size: 22px; font-weight: 600;">
                                You're Invited!
                            </h2>

                            <p style="margin: 0 0 20px; color: #555; font-size: 16px; line-height: 1.6;">
                                <?php if (!empty($invitedByName)): ?>
                                    <?= e($invitedByName) ?> has invited you to join
                                <?php else: ?>
                                    You've been invited to join
                                <?php endif; ?>
                                <strong><?= e($brigadeName ?? 'the portal') ?></strong>'s member portal.
                            </p>

                            <p style="margin: 0 0 30px; color: #555; font-size: 16px; line-height: 1.6;">
                                Click the button below to set up your account and get access to the brigade calendar, notices, and leave management.
                            </p>

                            <!-- CTA Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="text-align: center; padding: 20px 0;">
                                        <a href="<?= e($magicLinkUrl ?? '#') ?>"
                                           style="display: inline-block; padding: 16px 40px; background-color: <?= e($primaryColor) ?>; color: white; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 6px;">
                                            Accept Invitation
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 30px 0 0; color: #888; font-size: 14px; line-height: 1.6;">
                                This link will expire in <strong><?= (int)($expiryDays ?? 7) ?> days</strong>.
                                If you didn't expect this invitation, you can safely ignore this email.
                            </p>

                            <!-- Fallback Link -->
                            <p style="margin: 20px 0 0; color: #888; font-size: 12px; line-height: 1.6; word-break: break-all;">
                                If the button doesn't work, copy and paste this link into your browser:<br>
                                <a href="<?= e($magicLinkUrl ?? '#') ?>" style="color: <?= e($primaryColor) ?>;">
                                    <?= e($magicLinkUrl ?? '') ?>
                                </a>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #fafafa; padding: 20px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #eee;">
                            <p style="margin: 0; color: #999; font-size: 12px; text-align: center; line-height: 1.6;">
                                <?= e($brigadeName ?? 'Puke Volunteer Fire Brigade') ?><br>
                                Sent via <?= e($appName) ?>
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
