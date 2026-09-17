<?php

// modules/pmwh3/model/general_model.php
// Note: This model is named Login_Model but contains password reset logic.
class Login_Model extends \ckvsoft\mvc\Model
{

    // Define the token expiry time in seconds (30 minutes)
    private const TOKEN_EXPIRY_SECONDS = 1800;

    private $mailer;

    public function __construct()
    {
        parent::__construct();
        require_once __DIR__ . '/../config/version.php';

        $this->mailer = new \ckvsoft\Mailer();
        $this->moduleDb = $this->moduleDb();
    }

    /**
     * Handles customer login authentication.
     * * @param array $data Contains customername and hashed password.
     * @return array|false Customer data array on success, false otherwise.
     */
    public function customerLogin($data)
    {
        // Select customer based on username and password hash
        $customer = $this->moduleDb->selectOne("SELECT cid, role_id, language FROM pmwh3_customers WHERE customer = :customer AND password = :password",
                ['customer' => $data['customername'], 'password' => $data['password']]);

        if ($customer) {
            return $customer;
        }
        return false;
    }

    /**
     * Generates a strong, random password string.
     * * @param int $length The desired length of the password.
     * @return string The generated password.
     */
    private function generateRandomPassword(int $length = 12): string
    {
        return \pmwh3\Utils\PasswordUtil::generate($length);
    }

    /**
     * Phase 1: Generates a temporary password and activation link, stores the hash and token, and sends the email.
     * * @param string $email The customer's email address.
     * @return bool Always true for security (to prevent user enumeration).
     */
    public function sendResetLink(string $email): bool
    {
        // 1. Check if the customer exists
        $customer = $this->moduleDb->selectOne("SELECT cid FROM pmwh3_customers WHERE email = :email",
                ['email' => $email]);

        if (!$customer) {
            // Security measure: Always return true regardless of whether the email was found.
            return true;
        }

        $customerId = $customer['cid'];

        // 2. Generate security components
        $token = bin2hex(random_bytes(32)); // Secure 64-character hex token
        $password = $this->generateRandomPassword(); // Clear-text temporary password
        // Hash the temporary password using the framework's hash class (ckvsoft\Hash::create)
        $password_hash = \ckvsoft\Hash::create('sha256', $password, HASH_KEY);
        $expires = time() + self::TOKEN_EXPIRY_SECONDS; // Calculate expiration time
        // 3. Store reset request data
        // Delete any existing requests for this customer ID
        $this->moduleDb->delete("pmwh3_password_request", "cid = :customer_id", ['customer_id' => $customerId]);
        // Insert the new request with the token and the hash of the temporary password
        $this->moduleDb->insert("pmwh3_password_request", [
            'cid' => $customerId,
            'token' => $token,
            'password' => $password_hash,
            'expires' => date('Y-m-d H:i:s', $expires)
        ]);

        // 4. Build the activation link URL
        // Determine protocol (using a fixed 'https' is often safer for production)
        $protocol = 'https';
        // Use the framework's Request object to get the host
        $request = new \ckvsoft\Request();
        $host = $request->getServerVar('HTTP_HOST');

        // Construct the full activation URL:
        //   https://<host><BASE_URI>pmwh3/login/activate/<TOKEN>
        $activateLink = $protocol . '://' . $host . BASE_URI . 'pmwh3/login/activate/' . $token;

        // 5. Prepare and send the email. We don't ship the
        // temp password in the message body any more; the
        // activation link is enough -- it opens a form where the
        // user picks a new password. The temp password / hash row
        // in pmwh3_password_request acts only as the one-shot
        // ticket the activate() controller validates.
        $subject = "Password reset";
        $body    = "Someone (hopefully you) requested a password reset.\n\n"
                 . "Open this link within 30 minutes to set a new password:\n\n"
                 . $activateLink . "\n\n"
                 . "If you didn't ask for this, you can ignore the message.";

        $isSent = $this->mailer->send($email, $subject, $body);
        if (!$isSent) {
            error_log("Failed to send reset email to $email");
        }

        return true;
    }

    /**
     * Validates the token and retrieves customer ID for activation.
     * * NOTE: The table name 'password_resets' seems inconsistent with the INSERT/DELETE above ('pmwh3_password_request').
     * The table name should be consistent. Assuming 'password_resets' is the correct table for retrieval here.
     * * @param string $token The security token from the URL.
     * @return int|null The customer ID (cid) on success, null otherwise.
     */
    public function validateToken(string $token): ?int
    {
        // Select the request data by token
        $resetData = $this->moduleDb->selectOne("SELECT cid, expires FROM pmwh3_password_request WHERE token = :token",
                ['token' => $token]);

        if (!$resetData) {
            return null; // Token not found
        }

        // Check if the token has expired
        $expiryTime = strtotime($resetData['expires']);
        if ($expiryTime < time()) {
            return null; // Token expired
        }

        // Return the Customer ID (cid)
        return (int) $resetData['cid'];
    }

    /**
     * Retrieves the stored password hash from the request table.
     * This is needed by the Controller/Model to update the main users table.
     * * @param string $token The security token.
     * @return string|null The temporary password hash.
     */
    public function getTempPasswordHash(string $token): ?string
    {
        $data = $this->moduleDb->selectOne("SELECT password FROM pmwh3_password_request WHERE token = :token",
                ['token' => $token]);

        return $data['password'] ?? null;
    }

    /**
     * Finalize a password reset: write a new password for the cid
     * associated with $token, then delete the reset request row so
     * the token can't be reused.
     *
     * Returns false if the token is unknown / expired, true on
     * successful write.
     */
    public function finalizeReset(string $token, string $newPassword): bool
    {
        if ($newPassword === '') {
            return false;
        }
        $cid = $this->validateToken($token);
        if ($cid === null) {
            return false;
        }
        $ok = \pmwh3\Utils\CustomerManager::changePassword($cid, $newPassword);
        if ($ok) {
            // One-shot token: kill all reset rows for this cid so
            // the link in the email can't be reused.
            $this->moduleDb->delete('pmwh3_password_request',
                    'cid = :c', ['c' => $cid]);
        }
        return $ok;
    }
}
