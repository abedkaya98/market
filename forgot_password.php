<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php'; 
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_SESSION['user_logged_in']) || isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit();
}

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$site_name = $store_settings['site_name'] ?? 'Store';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$theme_folder = $store_settings['theme_folder'] ?? 'default';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_request'])) {
    $email = trim($_POST['email'] ?? '');
    
    $stmt_check = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt_check->bind_param("s", $email);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($res_check && $res_check->num_rows > 0) {
        $user = $res_check->fetch_assoc();
        $token = bin2hex(random_bytes(32));
        $expiry = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $stmt_del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt_del->bind_param("s", $email);
        $stmt_del->execute();

        $stmt_ins = $conn->prepare("INSERT INTO password_resets (email, token, expiry) VALUES (?, ?, ?)");
        $stmt_ins->bind_param("sss", $email, $token, $expiry);
        $stmt_ins->execute();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : '';
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('SMTP_USER') ? SMTP_USER : ''; 
            $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';   
            
            $smtp_port = defined('SMTP_PORT') ? SMTP_PORT : 587;
            $mail->SMTPSecure = ($smtp_port == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp_port;
            $mail->CharSet    = 'UTF-8';

            $from_email = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $mail->Username;
            $mail->setFrom($from_email, $site_name);
            $mail->addAddress($email);

            $current_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
            $resetLink = $current_domain . "/reset_password.php?token=" . $token . "&lang=" . $current_lang;
            
            $mail->isHTML(true);
            
            $mail_subject = $lang['email_subject'] ?? 'Password Reset';
            $mail_hello = $lang['email_hello'] ?? 'Hello';
            $mail_body = $lang['email_body_text'] ?? 'Please click the link to reset your password.';
            $mail_btn = $lang['email_btn'] ?? 'Reset Password';
            $mail_footer = $lang['email_footer'] ?? 'If you did not request this, please ignore this email.';

            $mail->Subject = $mail_subject . " - " . $site_name;
            $mail->Body    = "
                <div dir='$dir' style='font-family: Cairo, sans-serif; text-align: center; padding: 20px; border: 1px solid #eee;'>
                    <h2 style='color: #8e1b1d;'>{$mail_hello} {$user['full_name']}</h2>
                    <p>{$mail_body}</p>
                    <a href='$resetLink' style='background: #8e1b1d; color: #fff; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 20px 0;'>{$mail_btn}</a>
                    <p style='font-size: 12px; color: #777;'>{$mail_footer}</p>
                </div>";

            $mail->send();
            $_SESSION['flash_success'] = $lang['success_reset_sent'] ?? 'Reset link has been sent to your email.';
            header("Location: /forgot_password.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['flash_error'] = $lang['err_send_failed'] ?? 'Failed to send the email.';
            header("Location: /forgot_password.php");
            exit();
        }
    } else {
        $_SESSION['flash_error'] = $lang['err_email_not_registered'] ?? 'Email is not registered.';
        header("Location: /forgot_password.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>" dir="<?php echo htmlspecialchars($dir); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" type="image/png" href="/assets/icons/<?php echo htmlspecialchars($site_icon); ?>">
    
    <?php
    $theme_css_path = __DIR__ . '/assets/themes/' . $theme_folder . '/style.css';
    $theme_css_version = file_exists($theme_css_path) ? filemtime($theme_css_path) : time();
    ?>
    <link rel="stylesheet" href="/assets/themes/<?php echo htmlspecialchars($theme_folder); ?>/style.css?v=<?php echo $theme_css_version; ?>">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="auth-page">

<?php include __DIR__ . '/header.php'; ?>

<main class="main-wrapper auth-main">
    <div class="login-card">
        <div class="text-center">
            <i class="fas fa-lock-open site-closed-icon"></i>
        </div>
        <h2 class="login-title"><?php echo htmlspecialchars($lang['forgot_title'] ?? 'Forgot Password'); ?></h2>
        <p class="social-linking-desc text-center">
            <?php echo htmlspecialchars($lang['forgot_desc'] ?? 'Enter your email to receive a reset link.'); ?>
        </p>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-envelope auth-input-icon"></i>
                <input type="email" name="email" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['email_placeholder'] ?? 'Email'); ?>" required>
            </div>
            
            <button type="submit" name="reset_request" class="btn-login"><?php echo htmlspecialchars($lang['btn_send_reset'] ?? 'Send Reset Link'); ?></button>
        </form>

        <p class="register-text">
            <a href="/login.php" class="register-link"><?php echo htmlspecialchars($lang['back_to_login'] ?? 'Back to Login'); ?></a>
        </p>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>