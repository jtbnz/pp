<?php
declare(strict_types=1);

/**
 * Email Service
 *
 * Handles email sending for notifications, invitations, and alerts.
 * Supports smtp, sendmail, and mail drivers.
 */
class EmailService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Send an email
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $html HTML content
     * @return bool Success
     */
    public function send(string $to, string $subject, string $html): bool
    {
        $emailConfig = $this->config['email'] ?? [];
        $driver = $emailConfig['driver'] ?? 'mail';

        switch ($driver) {
            case 'smtp':
                return $this->sendViaSMTP($to, $subject, $html);
            case 'sendmail':
                return $this->sendViaSendmail($to, $subject, $html);
            case 'mail':
            default:
                return $this->sendViaMail($to, $subject, $html);
        }
    }

    /**
     * Send an invitation email with magic link
     *
     * @param string $email Recipient email
     * @param string $token Magic link token
     * @param string $brigadeName Name of the brigade
     * @return bool Success
     */
    public function sendInvite(string $email, string $token, string $brigadeName): bool
    {
        $appUrl = $this->config['app_url'] ?? '';
        $appName = $this->config['app_name'] ?? 'Puke Portal';

        $verifyUrl = $appUrl . '/auth/verify/' . $token;

        $data = [
            'brigadeName' => $brigadeName,
            'verifyUrl' => $verifyUrl,
            'appName' => $appName,
            'appUrl' => $appUrl,
            'expiryDays' => $this->config['auth']['invite_expiry_days'] ?? 7,
        ];

        $subject = "You're invited to join {$brigadeName}";
        $html = $this->renderTemplate('invite', $data);

        return $this->send($email, $subject, $html);
    }

    /**
     * Send a magic login link to a member
     *
     * @param string $email Recipient email
     * @param string $name Member name
     * @param string $magicLinkUrl Full URL for the magic link
     * @return bool Success
     */
    public function sendMagicLink(string $email, string $name, string $magicLinkUrl): bool
    {
        $appUrl = $this->config['app_url'] ?? '';
        $appName = $this->config['app_name'] ?? 'Puke Portal';

        $data = [
            'memberName' => $name,
            'magicLinkUrl' => $magicLinkUrl,
            'appName' => $appName,
            'appUrl' => $appUrl,
            'expiryDays' => $this->config['auth']['invite_expiry_days'] ?? 7,
        ];

        $subject = "Sign in to {$appName}";
        $html = $this->renderTemplate('magic-link', $data);

        return $this->send($email, $subject, $html);
    }

    /**
     * Send leave request notification to officers
     *
     * @param array $officers Array of officer data (each with 'email', 'name')
     * @param array $request Leave request data
     * @return bool Success (true if at least one email sent)
     */
    public function sendLeaveNotification(array $officers, array $request): bool
    {
        $appUrl = $this->config['app_url'] ?? '';
        $appName = $this->config['app_name'] ?? 'Puke Portal';

        $data = [
            'memberName' => $request['member_name'] ?? 'Unknown',
            'trainingDate' => $request['training_date'] ?? '',
            'reason' => $request['reason'] ?? '',
            'reviewUrl' => $appUrl . '/leave/pending',
            'appName' => $appName,
            'appUrl' => $appUrl,
        ];

        $subject = "Leave Request: {$data['memberName']} - " . $this->formatDate($data['trainingDate']);
        $html = $this->renderTemplate('leave-request', $data);

        $success = false;
        foreach ($officers as $officer) {
            if (!empty($officer['email'])) {
                if ($this->send($officer['email'], $subject, $html)) {
                    $success = true;
                }
            }
        }

        return $success;
    }

    /**
     * Send leave decision notification to member
     *
     * @param array $member Member data (with 'email', 'name')
     * @param array $request Leave request data
     * @param string $decision 'approved' or 'denied'
     * @return bool Success
     */
    public function sendLeaveDecision(array $member, array $request, string $decision): bool
    {
        $appUrl = $this->config['app_url'] ?? '';
        $appName = $this->config['app_name'] ?? 'Puke Portal';

        $data = [
            'memberName' => $member['name'] ?? 'Firefighter',
            'trainingDate' => $request['training_date'] ?? '',
            'decision' => $decision,
            'decisionText' => $decision === 'approved' ? 'Approved' : 'Denied',
            'decidedBy' => $request['decided_by_name'] ?? 'An officer',
            'reason' => $request['reason'] ?? '',
            'leaveUrl' => $appUrl . '/leave',
            'appName' => $appName,
            'appUrl' => $appUrl,
        ];

        $subject = "Leave Request {$data['decisionText']}: " . $this->formatDate($data['trainingDate']);
        $html = $this->renderTemplate('leave-decision', $data);

        return $this->send($member['email'], $subject, $html);
    }

    /**
     * Send urgent notice to all members
     *
     * @param array $members Array of member data (each with 'email', 'name')
     * @param array $notice Notice data
     * @return bool Success (true if at least one email sent)
     */
    public function sendUrgentNotice(array $members, array $notice): bool
    {
        $appUrl = $this->config['app_url'] ?? '';
        $appName = $this->config['app_name'] ?? 'Puke Portal';

        $data = [
            'title' => $notice['title'] ?? 'Urgent Notice',
            'content' => $notice['content'] ?? '',
            'authorName' => $notice['author_name'] ?? 'Admin',
            'noticeUrl' => $appUrl . '/notices/' . ($notice['id'] ?? ''),
            'appName' => $appName,
            'appUrl' => $appUrl,
        ];

        $subject = "[URGENT] {$data['title']}";
        $html = $this->renderTemplate('urgent-notice', $data);

        $success = false;
        foreach ($members as $member) {
            if (!empty($member['email'])) {
                if ($this->send($member['email'], $subject, $html)) {
                    $success = true;
                }
            }
        }

        return $success;
    }

    /**
     * Send access expiring notification
     *
     * @param array $member Member data (with 'email', 'name')
     * @param int $daysRemaining Days until access expires
     * @return bool Success
     */
    public function sendAccessExpiringNotice(array $member, int $daysRemaining): bool
    {
        $appUrl = $this->config['app_url'] ?? '';
        $appName = $this->config['app_name'] ?? 'Puke Portal';

        $data = [
            'memberName' => $member['name'] ?? 'Firefighter',
            'daysRemaining' => $daysRemaining,
            'profileUrl' => $appUrl . '/profile',
            'appName' => $appName,
            'appUrl' => $appUrl,
        ];

        $subject = "Your {$appName} access expires in {$daysRemaining} days";
        $html = $this->renderTemplate('access-expiring', $data);

        return $this->send($member['email'], $subject, $html);
    }

    /**
     * Get email headers for sending
     *
     * @return string Headers string
     */
    private function getHeaders(): string
    {
        $emailConfig = $this->config['email'] ?? [];
        $fromAddress = $emailConfig['from_address'] ?? 'noreply@example.com';
        $fromName = $emailConfig['from_name'] ?? 'Puke Portal';
        $replyTo = $emailConfig['reply_to'] ?? null;

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromAddress}>",
        ];

        if ($replyTo) {
            $headers[] = "Reply-To: {$replyTo}";
        }

        return implode("\r\n", $headers);
    }

    /**
     * Render an email template
     *
     * @param string $template Template name (without .php)
     * @param array $data Data to pass to template
     * @return string Rendered HTML
     */
    private function renderTemplate(string $template, array $data): string
    {
        $templatePath = __DIR__ . '/../../templates/emails/' . $template . '.php';

        if (!file_exists($templatePath)) {
            // Fallback to basic HTML if template doesn't exist
            return $this->renderBasicTemplate($template, $data);
        }

        // Extract data to variables
        extract($data);

        // Capture output
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Render a basic template when template file doesn't exist
     *
     * @param string $template Template name
     * @param array $data Data for template
     * @return string Basic HTML
     */
    private function renderBasicTemplate(string $template, array $data): string
    {
        $appName = $data['appName'] ?? 'Puke Portal';
        $content = '';

        switch ($template) {
            case 'invite':
                $content = "
                    <h2>You're Invited!</h2>
                    <p>You've been invited to join {$data['brigadeName']}.</p>
                    <p><a href=\"{$data['verifyUrl']}\" style=\"display:inline-block;padding:12px 24px;background:#D32F2F;color:white;text-decoration:none;border-radius:4px;\">Accept Invitation</a></p>
                    <p>This link expires in {$data['expiryDays']} days.</p>
                ";
                break;

            case 'leave-request':
                $content = "
                    <h2>New Leave Request</h2>
                    <p><strong>{$data['memberName']}</strong> has requested leave for training on <strong>" . $this->formatDate($data['trainingDate']) . "</strong>.</p>
                    " . ($data['reason'] ? "<p>Reason: {$data['reason']}</p>" : "") . "
                    <p><a href=\"{$data['reviewUrl']}\" style=\"display:inline-block;padding:12px 24px;background:#D32F2F;color:white;text-decoration:none;border-radius:4px;\">Review Request</a></p>
                ";
                break;

            case 'leave-decision':
                $statusColor = $data['decision'] === 'approved' ? '#4CAF50' : '#F44336';
                $content = "
                    <h2>Leave Request {$data['decisionText']}</h2>
                    <p>Your leave request for <strong>" . $this->formatDate($data['trainingDate']) . "</strong> has been <span style=\"color:{$statusColor};font-weight:bold;\">{$data['decisionText']}</span> by {$data['decidedBy']}.</p>
                    <p><a href=\"{$data['leaveUrl']}\" style=\"display:inline-block;padding:12px 24px;background:#D32F2F;color:white;text-decoration:none;border-radius:4px;\">View My Leave</a></p>
                ";
                break;

            case 'urgent-notice':
                $content = "
                    <h2 style=\"color:#D32F2F;\">[URGENT] {$data['title']}</h2>
                    <div style=\"background:#FFF3E0;padding:16px;border-left:4px solid #FF9800;margin:16px 0;\">
                        " . nl2br(htmlspecialchars($data['content'])) . "
                    </div>
                    <p>Posted by: {$data['authorName']}</p>
                    <p><a href=\"{$data['noticeUrl']}\" style=\"display:inline-block;padding:12px 24px;background:#D32F2F;color:white;text-decoration:none;border-radius:4px;\">View Notice</a></p>
                ";
                break;

            case 'access-expiring':
                $content = "
                    <h2>Access Expiring Soon</h2>
                    <p>Hi {$data['memberName']},</p>
                    <p>Your access to {$appName} will expire in <strong>{$data['daysRemaining']} days</strong>.</p>
                    <p>Please contact your brigade administrator to renew your access.</p>
                    <p><a href=\"{$data['profileUrl']}\" style=\"display:inline-block;padding:12px 24px;background:#D32F2F;color:white;text-decoration:none;border-radius:4px;\">View Profile</a></p>
                ";
                break;

            case 'magic-link':
                $content = "
                    <h2>Sign In</h2>
                    <p>Hi {$data['memberName']},</p>
                    <p>Click the button below to sign in to {$appName}:</p>
                    <p><a href=\"{$data['magicLinkUrl']}\" style=\"display:inline-block;padding:12px 24px;background:#D32F2F;color:white;text-decoration:none;border-radius:4px;\">Sign In</a></p>
                    <p>This link expires in {$data['expiryDays']} days.</p>
                    <p style=\"color:#888;font-size:12px;\">If you didn't request this link, you can safely ignore this email.</p>
                ";
                break;

            default:
                $content = "<p>No template found for: {$template}</p>";
        }

        return $this->wrapInLayout($content, $appName, $data['appUrl'] ?? '');
    }

    /**
     * Wrap content in email layout
     *
     * @param string $content Email body content
     * @param string $appName Application name
     * @param string $appUrl Application URL
     * @return string Full HTML email
     */
    private function wrapInLayout(string $content, string $appName, string $appUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f5f5;">
        <tr>
            <td align="center" style="padding:40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:#D32F2F;padding:24px;text-align:center;border-radius:8px 8px 0 0;">
                            <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:600;">{$appName}</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:32px 24px;color:#333333;line-height:1.6;">
                            {$content}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px;text-align:center;border-top:1px solid #eeeeee;color:#888888;font-size:12px;">
                            <p style="margin:0 0 8px 0;">This email was sent by {$appName}</p>
                            <p style="margin:0;"><a href="{$appUrl}" style="color:#D32F2F;text-decoration:none;">{$appUrl}</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Format a date for display in emails
     *
     * @param string $date Date string
     * @return string Formatted date
     */
    private function formatDate(string $date): string
    {
        if (empty($date)) {
            return 'Unknown date';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        return date('l, j F Y', $timestamp);
    }

    /**
     * Send email via PHP mail() function
     *
     * @param string $to Recipient
     * @param string $subject Subject
     * @param string $html HTML content
     * @return bool Success
     */
    private function sendViaMail(string $to, string $subject, string $html): bool
    {
        $headers = $this->getHeaders();
        return mail($to, $subject, $html, $headers);
    }

    /**
     * Send email via sendmail
     *
     * @param string $to Recipient
     * @param string $subject Subject
     * @param string $html HTML content
     * @return bool Success
     */
    private function sendViaSendmail(string $to, string $subject, string $html): bool
    {
        $emailConfig = $this->config['email'] ?? [];
        $fromAddress = $emailConfig['from_address'] ?? 'noreply@example.com';
        $sendmailPath = $emailConfig['sendmail_path'] ?? '/usr/sbin/sendmail -t -i';

        $message = "To: {$to}\r\n";
        $message .= "Subject: {$subject}\r\n";
        $message .= $this->getHeaders() . "\r\n\r\n";
        $message .= $html;

        $process = popen($sendmailPath, 'w');
        if ($process === false) {
            return false;
        }

        $result = fwrite($process, $message);
        $returnCode = pclose($process);

        return $result !== false && $returnCode === 0;
    }

    /**
     * Send email via SMTP
     *
     * @param string $to Recipient
     * @param string $subject Subject
     * @param string $html HTML content
     * @return bool Success
     */
    private function sendViaSMTP(string $to, string $subject, string $html): bool
    {
        $emailConfig = $this->config['email'] ?? [];
        $host = $emailConfig['host'] ?? 'localhost';
        $port = $emailConfig['port'] ?? 587;
        $encryption = $emailConfig['encryption'] ?? 'tls';
        $username = $emailConfig['username'] ?? '';
        $password = $emailConfig['password'] ?? '';
        $fromAddress = $emailConfig['from_address'] ?? 'noreply@example.com';
        $fromName = $emailConfig['from_name'] ?? 'Puke Portal';

        // Build the protocol prefix
        $protocol = '';
        if ($encryption === 'ssl') {
            $protocol = 'ssl://';
        } elseif ($encryption === 'tls') {
            $protocol = 'tls://';
        }

        // Connect to SMTP server
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $socket = @stream_socket_client(
            $protocol . $host . ':' . $port,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            error_log("SMTP connection failed: {$errno} - {$errstr}");
            return false;
        }

        try {
            // Read greeting
            $this->smtpReadResponse($socket);

            // EHLO
            $this->smtpCommand($socket, 'EHLO ' . gethostname());

            // Start TLS if using tls encryption (and not already ssl)
            if ($encryption === 'tls' && !str_starts_with($protocol, 'ssl')) {
                $this->smtpCommand($socket, 'STARTTLS');
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpCommand($socket, 'EHLO ' . gethostname());
            }

            // Authentication
            if ($username && $password) {
                $this->smtpCommand($socket, 'AUTH LOGIN');
                $this->smtpCommand($socket, base64_encode($username));
                $this->smtpCommand($socket, base64_encode($password));
            }

            // Send email
            $this->smtpCommand($socket, 'MAIL FROM:<' . $fromAddress . '>');
            $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>');
            $this->smtpCommand($socket, 'DATA');

            // Email content
            $emailContent = "To: {$to}\r\n";
            $emailContent .= "From: {$fromName} <{$fromAddress}>\r\n";
            $emailContent .= "Subject: {$subject}\r\n";
            $emailContent .= "MIME-Version: 1.0\r\n";
            $emailContent .= "Content-Type: text/html; charset=UTF-8\r\n";
            $emailContent .= "\r\n";
            $emailContent .= $html;
            $emailContent .= "\r\n.\r\n";

            fwrite($socket, $emailContent);
            $this->smtpReadResponse($socket);

            // Quit
            $this->smtpCommand($socket, 'QUIT');
            fclose($socket);

            return true;
        } catch (\Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            if (is_resource($socket)) {
                fclose($socket);
            }
            return false;
        }
    }

    /**
     * Send SMTP command and read response
     *
     * @param resource $socket Socket resource
     * @param string $command Command to send
     * @return string Response
     */
    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpReadResponse($socket);
    }

    /**
     * Read SMTP response
     *
     * @param resource $socket Socket resource
     * @return string Response
     * @throws \Exception On error response
     */
    private function smtpReadResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // Check if this is the last line of the response
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        // Check for error
        $code = (int) substr($response, 0, 3);
        if ($code >= 400) {
            throw new \Exception("SMTP error: {$response}");
        }

        return $response;
    }
}
