<?php
declare(strict_types=1);

/**
 * Magic Link Email Template
 *
 * Sent to existing members to sign in.
 *
 * Variables:
 * - $memberName: Recipient's name
 * - $brigadeName: Name of the brigade
 * - $magicLinkUrl: The magic link URL
 * - $expiryDays: Number of days until link expires
 */

global $config;

$appName = $config['app_name'] ?? 'Puke Portal';
$primaryColor = $config['theme']['primary_color'] ?? '#D32F2F';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In to <?= e($appName) ?></title>
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
                                Sign In
                            </h2>

                            <p style="margin: 0 0 20px; color: #555; font-size: 16px; line-height: 1.6;">
                                Hi<?php if (!empty($memberName)): ?> <?= e($memberName) ?><?php endif; ?>,
                            </p>

                            <p style="margin: 0 0 30px; color: #555; font-size: 16px; line-height: 1.6;">
                                Click the button below to sign in to the <?= e($brigadeName ?? 'Puke Fire Brigade') ?> portal.
                                No password needed!
                            </p>

                            <!-- CTA Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="text-align: center; padding: 20px 0;">
                                        <a href="<?= e($magicLinkUrl ?? '#') ?>"
                                           style="display: inline-block; padding: 16px 40px; background-color: <?= e($primaryColor) ?>; color: white; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 6px;">
                                            Sign In Now
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 30px 0 0; color: #888; font-size: 14px; line-height: 1.6;">
                                This link will expire in <strong><?= (int)($expiryDays ?? 7) ?> days</strong>.
                            </p>

                            <p style="margin: 15px 0 0; color: #888; font-size: 14px; line-height: 1.6;">
                                If you didn't request this email, you can safely ignore it.
                                Someone may have entered your email by mistake.
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
