<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

if (isset($_SESSION['user_logged_in']) || isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit();
}

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($password !== $confirm_password) {
        $_SESSION['flash_error'] = $lang['err_passwords_mismatch'] ?? "Passwords do not match";
        header("Location: /register.php");
        exit();
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check->num_rows > 0) {
            $_SESSION['flash_error'] = $lang['err_email_exists'] ?? "Email already exists";
            header("Location: /register.php");
            exit();
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt_insert = $conn->prepare("INSERT INTO users (full_name, email, password, language) VALUES (?, ?, ?, ?)");
            $stmt_insert->bind_param("ssss", $full_name, $email, $hashed_password, $current_lang);
            
            if ($stmt_insert->execute()) {
                $_SESSION['flash_success'] = $lang['success_account_created'] ?? "Account created successfully";
                header("Location: /login.php");
                exit();
            } else {
                $_SESSION['flash_error'] = $lang['err_register_failed'] ?? "Registration failed";
                header("Location: /register.php");
                exit();
            }
        }
    }
}

$site_name = $store_settings['site_name'] ?? '';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$theme_folder = $store_settings['theme_folder'] ?? 'default';
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
        <h2 class="login-title"><?php echo htmlspecialchars($lang['register_title'] ?? ''); ?></h2>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-user auth-input-icon"></i>
                <input type="text" name="full_name" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['fullname_placeholder'] ?? ''); ?>" required>
            </div>

            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-envelope auth-input-icon"></i>
                <input type="email" name="email" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['email_placeholder'] ?? ''); ?>" required>
            </div>
            
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-lock auth-input-icon"></i>
                <div class="password-wrapper">
                    <input type="password" name="password" id="reg_pass1" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['password_placeholder'] ?? ''); ?>" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('reg_pass1', this)"></i>
                </div>
            </div>

            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-shield-alt auth-input-icon"></i>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="reg_pass2" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['confirm_password_placeholder'] ?? ''); ?>" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('reg_pass2', this)"></i>
                </div>
            </div>

            <button type="submit" name="register" class="btn-login"><?php echo htmlspecialchars($lang['btn_create_account'] ?? ''); ?></button>
        </form>

        <p class="register-text">
            <?php echo htmlspecialchars($lang['already_have_account'] ?? ''); ?> 
            <a href="/login.php" class="register-link"><?php echo htmlspecialchars($lang['login_now'] ?? ''); ?></a>
        </p>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>