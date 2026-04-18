<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

$site_name = $store_settings['site_name'] ?? 'website';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$theme_folder = $store_settings['theme_folder'] ?? 'default';

$error = $_SESSION['flash_error'] ?? "";
$success = $_SESSION['flash_success'] ?? "";
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$show_form = false;
$reset_email = "";

$token_value = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!empty($token_value)) {
    $stmt_check = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expiry > NOW() LIMIT 1");
    $stmt_check->bind_param("s", $token_value);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($res_check && $res_check->num_rows > 0) {
        $show_form = true;
        $row = $res_check->fetch_assoc();
        $reset_email = $row['email'];
    } else {
        $error = $lang['err_invalid_token'] ?? '';
    }
} else {
    $error = $lang['err_invalid_token'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password']) && $show_form) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($new_password !== $confirm_password) {
        $_SESSION['flash_error'] = $lang['err_passwords_mismatch'] ?? '';
        header("Location: /reset_password.php?token=" . urlencode($token_value));
        exit();
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt_update->bind_param("ss", $hashed_password, $reset_email);
        
        if ($stmt_update->execute()) {
            $stmt_del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt_del->bind_param("s", $reset_email);
            $stmt_del->execute();

            $success_text = $lang['success_password_reset'] ?? '';
            $login_text = $lang['login_now'] ?? '';
            
            $_SESSION['flash_success'] = "{$success_text} <br><br> <a href='/login.php' class='btn-login'>{$login_text}</a>";
            header("Location: /login.php");
            exit();
        } else {
            $_SESSION['flash_error'] = $lang['err_general'] ?? '';
            header("Location: /reset_password.php?token=" . urlencode($token_value));
            exit();
        }
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
            <i class="fas fa-key site-closed-icon"></i>
        </div>
        <h2 class="login-title"><?php echo htmlspecialchars($lang['reset_title'] ?? ''); ?></h2>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($show_form): ?>
        <form action="" method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token_value); ?>">
            
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-lock auth-input-icon"></i>
                <div class="password-wrapper">
                    <input type="password" name="new_password" id="new_password" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['new_password_placeholder'] ?? ''); ?>" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('new_password', this)"></i>
                </div>
            </div>
            
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-shield-alt auth-input-icon"></i>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirm_password" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['confirm_password_placeholder'] ?? ''); ?>" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
                </div>
            </div>

            <button type="submit" name="update_password" class="btn-login"><?php echo htmlspecialchars($lang['btn_update_password'] ?? ''); ?></button>
        </form>
        <?php endif; ?>

        <?php if (!$show_form && empty($success)): ?>
        <p class="register-text">
            <a href="/forgot_password.php" class="register-link"><?php echo htmlspecialchars($lang['forgot_password'] ?? ''); ?></a>
        </p>
        <?php endif; ?>

    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>