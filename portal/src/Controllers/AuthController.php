<?php
declare(strict_types=1);

/**
 * Authentication Controller
 *
 * Handles all authentication flows including:
 * - Magic link login (email-based)
 * - Token verification
 * - Account activation (set name, optional PIN)
 * - PIN quick login
 * - Logout
 */
class AuthController
{
    private PDO $db;
    private array $config;
    private AuthService $authService;
    private Member $memberModel;
    private InviteToken $inviteTokenModel;

    public function __construct()
    {
        global $db, $config;
        $this->db = $db;
        $this->config = $config;
        $this->authService = new AuthService($db, $config);
        $this->memberModel = new Member($db);
        $this->inviteTokenModel = new InviteToken($db);
    }

    /**
     * Display the login page
     * GET /auth/login
     */
    public function loginForm(): void
    {
        // If already logged in, redirect to home
        if ($this->isAuthenticated()) {
            header('Location: /');
            exit;
        }

        $data = [
            'error' => $_GET['error'] ?? null,
            'success' => $_GET['success'] ?? null,
            'expired' => isset($_GET['expired']),
            'email' => $_GET['email'] ?? ''
        ];

        render('pages/auth/login', $data);
    }

    /**
     * Process login form - send magic link
     * POST /auth/login
     */
    public function login(): void
    {
        // Verify CSRF token
        if (!$this->verifyCsrf()) {
            $this->redirectWithError('login', 'Invalid request. Please try again.');
            return;
        }

        $email = trim($_POST['email'] ?? '');

        // Validate email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWithError('login', 'Please enter a valid email address.', $email);
            return;
        }

        // Check rate limiting
        $rateLimitKey = 'login:' . strtolower($email);
        if (!$this->authService->checkRateLimit($rateLimitKey)) {
            $remaining = $this->authService->getRateLimitRemainingTime($rateLimitKey);
            $minutes = ceil($remaining / 60);
            $this->redirectWithError('login', "Too many attempts. Please try again in {$minutes} minutes.", $email);
            return;
        }

        // Find member by email
        $member = $this->memberModel->findByEmailGlobal($email);

        if (!$member) {
            // Don't reveal if email exists - show same message
            $this->authService->recordRateLimitAttempt($rateLimitKey);
            // Still show success to prevent email enumeration
            $this->redirectWithSuccess('login', 'If an account exists with this email, you will receive a magic link shortly.');
            return;
        }

        // Check if member is active
        if ($member['status'] !== 'active') {
            $this->authService->recordRateLimitAttempt($rateLimitKey);
            $this->redirectWithSuccess('login', 'If an account exists with this email, you will receive a magic link shortly.');
            return;
        }

        // Check if member has PIN set - offer PIN login option
        if ($member['pin_hash']) {
            $_SESSION['pin_login_member_id'] = $member['id'];
            $_SESSION['pin_login_email'] = $email;
            header('Location: /auth/pin');
            exit;
        }

        // Generate and send magic link
        $this->sendMagicLinkEmail($member);

        // Reset rate limit on successful request
        $this->authService->resetRateLimit($rateLimitKey);

        $this->redirectWithSuccess('login', 'Check your email for a magic link to sign in.');
    }

    /**
     * Verify magic link token
     * GET /auth/verify/{token}
     */
    public function verify(string $token): void
    {
        // Validate token format
        if (empty($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
            $this->redirectWithError('login', 'Invalid or expired link. Please request a new one.');
            return;
        }

        // Verify token
        $tokenData = $this->authService->verifyToken($token);

        if (!$tokenData) {
            $this->redirectWithError('login', 'This link has expired or already been used. Please request a new one.');
            return;
        }

        // Check if member already exists with this email
        $member = $this->memberModel->findByEmail($tokenData['email'], $tokenData['brigade_id']);

        if ($member) {
            // Existing member - log them in
            $this->loginMember($member);

            // Mark token as used
            $this->authService->markTokenUsed($tokenData['id']);

            // Log the login
            $this->authService->logAudit(
                'member.login',
                $member['brigade_id'],
                $member['id'],
                'member',
                $member['id'],
                ['method' => 'magic_link']
            );

            // Redirect to intended URL or home
            $intendedUrl = Auth::getIntendedUrl();
            header('Location: ' . $intendedUrl);
            exit;
        }

        // New member - need to activate account
        $_SESSION['activation_token_id'] = $tokenData['id'];
        $_SESSION['activation_email'] = $tokenData['email'];
        $_SESSION['activation_brigade_id'] = $tokenData['brigade_id'];
        $_SESSION['activation_role'] = $tokenData['role'];
        $_SESSION['activation_brigade_name'] = $tokenData['brigade_name'];

        header('Location: /auth/activate');
        exit;
    }

    /**
     * Display account activation form
     * GET /auth/activate
     */
    public function showActivate(): void
    {
        // Check if we have activation data in session
        if (!isset($_SESSION['activation_token_id'])) {
            $this->redirectWithError('login', 'Please use the magic link from your email to activate your account.');
            return;
        }

        $data = [
            'email' => $_SESSION['activation_email'],
            'brigadeName' => $_SESSION['activation_brigade_name'],
            'error' => $_GET['error'] ?? null,
            'name' => $_GET['name'] ?? ''
        ];

        render('pages/auth/activate', $data);
    }

    /**
     * Process account activation
     * POST /auth/activate
     */
    public function activate(): void
    {
        // Verify CSRF token
        if (!$this->verifyCsrf()) {
            $this->redirectWithError('activate', 'Invalid request. Please try again.');
            return;
        }

        // Check if we have activation data
        if (!isset($_SESSION['activation_token_id'])) {
            $this->redirectWithError('login', 'Session expired. Please request a new magic link.');
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $pin = $_POST['pin'] ?? '';
        $pinConfirm = $_POST['pin_confirm'] ?? '';

        // Validate name
        if (empty($name) || strlen($name) < 2) {
            $this->redirectToActivateWithError('Please enter your full name.', $name);
            return;
        }

        if (strlen($name) > 100) {
            $this->redirectToActivateWithError('Name is too long.', $name);
            return;
        }

        // Validate PIN if provided
        $pinHash = null;
        if (!empty($pin)) {
            $pinLength = $this->config['auth']['pin_length'] ?? 6;

            if (strlen($pin) !== $pinLength || !ctype_digit($pin)) {
                $this->redirectToActivateWithError("PIN must be exactly {$pinLength} digits.", $name);
                return;
            }

            if ($pin !== $pinConfirm) {
                $this->redirectToActivateWithError('PINs do not match.', $name);
                return;
            }

            $pinHash = $this->authService->hashPin($pin);
        }

        // Get token data to verify it's still valid
        $tokenData = $this->inviteTokenModel->findById($_SESSION['activation_token_id']);
        if (!$tokenData || $tokenData['used_at']) {
            $this->clearActivationSession();
            $this->redirectWithError('login', 'This invitation has expired. Please request a new one.');
            return;
        }

        // Generate access token (valid for 5 years)
        $accessToken = $this->authService->generateAccessToken();
        $accessYears = $this->config['auth']['access_duration_years'] ?? 5;
        $accessExpires = new DateTime();
        $accessExpires->modify("+{$accessYears} years");

        // Create the member
        $memberId = $this->memberModel->create([
            'brigade_id' => $_SESSION['activation_brigade_id'],
            'email' => $_SESSION['activation_email'],
            'name' => $name,
            'role' => $_SESSION['activation_role'],
            'status' => 'active',
            'access_token' => $accessToken,
            'access_expires' => $accessExpires->format('Y-m-d H:i:s'),
            'pin_hash' => $pinHash
        ]);

        // Mark token as used
        $this->inviteTokenModel->markUsed($tokenData['id']);

        // Get the created member
        $member = $this->memberModel->findById($memberId);

        // Log the member in
        $this->loginMember($member);

        // Log the activation
        $this->authService->logAudit(
            'member.activated',
            $member['brigade_id'],
            $member['id'],
            'member',
            $member['id'],
            ['invited_by' => $tokenData['created_by']]
        );

        // Clear activation session data
        $this->clearActivationSession();

        // Redirect to home with welcome message
        $brigadeName = $_SESSION['activation_brigade_name'] ?? 'the portal';
        $_SESSION['flash_message'] = "Welcome to {$brigadeName}, {$name}!";
        $_SESSION['flash_type'] = 'success';

        header('Location: /');
        exit;
    }

    /**
     * Display PIN login form
     * GET /auth/pin
     */
    public function showPinLogin(): void
    {
        // Check if we have PIN login data
        if (!isset($_SESSION['pin_login_member_id'])) {
            header('Location: /auth/login');
            exit;
        }

        $data = [
            'email' => $_SESSION['pin_login_email'] ?? '',
            'error' => $_GET['error'] ?? null
        ];

        render('pages/auth/pin', $data);
    }

    /**
     * Process PIN login
     * POST /auth/pin
     */
    public function pinLogin(): void
    {
        // Verify CSRF token
        if (!$this->verifyCsrf()) {
            header('Location: /auth/pin?error=' . urlencode('Invalid request. Please try again.'));
            exit;
        }

        // Check if we have PIN login data
        if (!isset($_SESSION['pin_login_member_id'])) {
            $this->redirectWithError('login', 'Session expired. Please try again.');
            return;
        }

        $pin = $_POST['pin'] ?? '';
        $memberId = $_SESSION['pin_login_member_id'];

        // Check rate limiting
        $rateLimitKey = 'pin:' . $memberId;
        if (!$this->authService->checkRateLimit($rateLimitKey)) {
            $remaining = $this->authService->getRateLimitRemainingTime($rateLimitKey);
            $minutes = ceil($remaining / 60);

            // Clear PIN login session for security
            unset($_SESSION['pin_login_member_id'], $_SESSION['pin_login_email']);

            $this->redirectWithError('login', "Too many failed attempts. Please try again in {$minutes} minutes.");
            return;
        }

        // Verify PIN
        if (!$this->authService->verifyPin($memberId, $pin)) {
            $this->authService->recordRateLimitAttempt($rateLimitKey);
            header('Location: /auth/pin?error=' . urlencode('Incorrect PIN. Please try again.'));
            exit;
        }

        // Get member data
        $member = $this->memberModel->findById($memberId);
        if (!$member || $member['status'] !== 'active') {
            unset($_SESSION['pin_login_member_id'], $_SESSION['pin_login_email']);
            $this->redirectWithError('login', 'Account not found or inactive.');
            return;
        }

        // Reset rate limit on success
        $this->authService->resetRateLimit($rateLimitKey);

        // Log member in
        $this->loginMember($member);

        // Clear PIN login session
        unset($_SESSION['pin_login_member_id'], $_SESSION['pin_login_email']);

        // Log the login
        $this->authService->logAudit(
            'member.login',
            $member['brigade_id'],
            $member['id'],
            'member',
            $member['id'],
            ['method' => 'pin']
        );

        // Redirect to intended URL or home
        $intendedUrl = Auth::getIntendedUrl();
        header('Location: ' . $intendedUrl);
        exit;
    }

    /**
     * Use magic link instead of PIN
     * GET /auth/magic-link
     */
    public function requestMagicLink(): void
    {
        if (!isset($_SESSION['pin_login_member_id'])) {
            header('Location: /auth/login');
            exit;
        }

        $member = $this->memberModel->findById($_SESSION['pin_login_member_id']);
        if (!$member) {
            unset($_SESSION['pin_login_member_id'], $_SESSION['pin_login_email']);
            $this->redirectWithError('login', 'Account not found.');
            return;
        }

        // Send magic link
        $this->sendMagicLinkEmail($member);

        // Clear PIN login session
        unset($_SESSION['pin_login_member_id'], $_SESSION['pin_login_email']);

        $this->redirectWithSuccess('login', 'Check your email for a magic link to sign in.');
    }

    /**
     * Logout
     * POST /auth/logout
     */
    public function logout(): void
    {
        // Log the logout if user was authenticated
        if (isset($_SESSION['member_id'])) {
            $this->authService->logAudit(
                'member.logout',
                $_SESSION['brigade_id'] ?? null,
                $_SESSION['member_id'],
                'member',
                $_SESSION['member_id']
            );
        }

        // Clear session
        $_SESSION = [];

        // Delete session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Destroy session
        session_destroy();

        // Start new session for flash message
        session_start();
        $_SESSION['flash_message'] = 'You have been logged out.';
        $_SESSION['flash_type'] = 'info';

        header('Location: /auth/login');
        exit;
    }

    /**
     * Send magic link email to member
     */
    private function sendMagicLinkEmail(array $member): void
    {
        // Create invite token for magic link
        $token = $this->authService->createInviteToken(
            $member['brigade_id'],
            $member['email'],
            $member['role']
        );

        $magicLinkUrl = $this->config['app_url'] . '/auth/verify/' . $token;

        // Prepare email data
        $emailData = [
            'memberName' => $member['name'],
            'brigadeName' => $member['brigade_name'] ?? 'Puke Fire Brigade',
            'magicLinkUrl' => $magicLinkUrl,
            'expiryDays' => $this->config['auth']['invite_expiry_days'] ?? 7
        ];

        // Send email (using simple mail for now - can be replaced with proper mailer)
        $this->sendEmail(
            $member['email'],
            'Sign in to ' . ($this->config['app_name'] ?? 'Puke Portal'),
            $this->renderEmailTemplate('emails/magic-link', $emailData)
        );
    }

    /**
     * Login a member (set session data)
     */
    private function loginMember(array $member): void
    {
        // Regenerate session ID for security
        session_regenerate_id(true);

        $_SESSION['member_id'] = $member['id'];
        $_SESSION['brigade_id'] = $member['brigade_id'];
        $_SESSION['member_name'] = $member['name'];
        $_SESSION['member_role'] = $member['role'];
        $_SESSION['member_email'] = $member['email'];
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();

        // Update last login
        $this->memberModel->updateLastLogin($member['id']);
    }

    /**
     * Check if user is authenticated
     */
    private function isAuthenticated(): bool
    {
        return isset($_SESSION['member_id']);
    }

    /**
     * Verify CSRF token
     */
    private function verifyCsrf(): bool
    {
        $token = $_POST['_csrf_token'] ?? '';
        return verifyCsrfToken($token);
    }

    /**
     * Redirect to login with error
     */
    private function redirectWithError(string $page, string $error, string $email = ''): void
    {
        $params = ['error' => $error];
        if ($email) {
            $params['email'] = $email;
        }
        header('Location: /auth/' . $page . '?' . http_build_query($params));
        exit;
    }

    /**
     * Redirect to login with success message
     */
    private function redirectWithSuccess(string $page, string $message): void
    {
        header('Location: /auth/' . $page . '?success=' . urlencode($message));
        exit;
    }

    /**
     * Redirect to activate page with error
     */
    private function redirectToActivateWithError(string $error, string $name = ''): void
    {
        $params = ['error' => $error];
        if ($name) {
            $params['name'] = $name;
        }
        header('Location: /auth/activate?' . http_build_query($params));
        exit;
    }

    /**
     * Clear activation session data
     */
    private function clearActivationSession(): void
    {
        unset(
            $_SESSION['activation_token_id'],
            $_SESSION['activation_email'],
            $_SESSION['activation_brigade_id'],
            $_SESSION['activation_role'],
            $_SESSION['activation_brigade_name']
        );
    }

    /**
     * Render an email template
     */
    private function renderEmailTemplate(string $template, array $data): string
    {
        extract($data);

        ob_start();
        $templatePath = __DIR__ . '/../../templates/' . $template . '.php';

        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            // Fallback simple template
            echo "Hello {$memberName},\n\n";
            echo "Click here to sign in: {$magicLinkUrl}\n\n";
            echo "This link expires in {$expiryDays} days.\n";
        }

        return ob_get_clean();
    }

    /**
     * Send email
     */
    private function sendEmail(string $to, string $subject, string $body): bool
    {
        $fromAddress = $this->config['email']['from_address'] ?? 'noreply@kiaora.tech';
        $fromName = $this->config['email']['from_name'] ?? 'Puke Fire Portal';

        $headers = [
            'From' => "{$fromName} <{$fromAddress}>",
            'Reply-To' => $this->config['email']['reply_to'] ?? $fromAddress,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer' => 'Puke Portal'
        ];

        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "{$key}: {$value}\r\n";
        }

        // Log the email in development mode
        if ($this->config['debug'] ?? false) {
            error_log("Email to: {$to}\nSubject: {$subject}\nBody: {$body}");
        }

        return mail($to, $subject, $body, $headerString);
    }
}
