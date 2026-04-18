<?php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php'; 
}

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

if (isset($_GET['sso_token'])) {
    $token = $_GET['sso_token'];
    $stmt_token = $conn->prepare("SELECT * FROM users WHERE remember_token = ? AND token_expiry > NOW() LIMIT 1");
    $stmt_token->bind_param("s", $token);
    $stmt_token->execute();
    $res_token = $stmt_token->get_result();
    
    if ($res_token && $res_token->num_rows > 0) {
        $user = $res_token->fetch_assoc();
        $_SESSION['user_logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
        $_SESSION['role'] = $user['role']; 
        $_SESSION['lang'] = !empty($user['language']) ? $user['language'] : 'ar';

        $upd_login = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $upd_login->bind_param("i", $user['id']);
        $upd_login->execute();

        setcookie('remember_user', $token, time() + (86400 * 30), "/");
        header("Location: /index.php");
        exit();
    }
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_user'])) {
    $token = $_COOKIE['remember_user'];
    $stmt_token = $conn->prepare("SELECT * FROM users WHERE remember_token = ? AND token_expiry > NOW() LIMIT 1");
    $stmt_token->bind_param("s", $token);
    $stmt_token->execute();
    $res_token = $stmt_token->get_result();
    
    if ($res_token && $res_token->num_rows > 0) {
        $user = $res_token->fetch_assoc();
        $_SESSION['user_logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
        $_SESSION['role'] = $user['role']; 
        $_SESSION['lang'] = !empty($user['language']) ? $user['language'] : 'ar';

        $upd_login = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $upd_login->bind_param("i", $user['id']);
        $upd_login->execute();

        header("Location: /index.php");
        exit();
    }
}

if (isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit();
}

$google_login_url = "#";
if (class_exists('Google_Client') && defined('GOOGLE_CLIENT_ID') && defined('GOOGLE_CLIENT_SECRET')) {
    $client = new Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    
    $client->setRedirectUri("https://auth.buxna.site/google-callback.php");
    $client->addScope("email");
    $client->addScope("profile");
    
    $current_domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
    $client->setState(base64_encode($current_domain));
    
    $google_login_url = $client->createAuthUrl();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt_login = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt_login->bind_param("s", $email);
    $stmt_login->execute();
    $res_login = $stmt_login->get_result();

    if ($res_login && $res_login->num_rows > 0) {
        $user = $res_login->fetch_assoc();
        
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            if ($user['status'] == 0) {
                $_SESSION['flash_error'] = $lang['err_banned'] ?? '';
                header("Location: /login.php");
                exit();
            } else {
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                $user_id = intval($user['id']);
                
                $upd_token = $conn->prepare("UPDATE users SET last_login = NOW(), remember_token = ?, token_expiry = ? WHERE id = ?");
                $upd_token->bind_param("ssi", $token, $expiry, $user_id);
                $upd_token->execute();
                
                setcookie('remember_user', $token, time() + (86400 * 30), "/");

                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['profile_pic'] = $user['profile_pic'] ?? '';
                $_SESSION['role'] = $user['role']; 
                $_SESSION['lang'] = !empty($user['language']) ? $user['language'] : 'ar';

                header("Location: /index.php");
                exit();
            }
        } else {
            $_SESSION['flash_error'] = $lang['err_wrong_pass'] ?? '';
            header("Location: /login.php");
            exit();
        }
    } else {
        $_SESSION['flash_error'] = $lang['err_email_not_found'] ?? '';
        header("Location: /login.php");
        exit();
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
        <h2 class="login-title"><?php echo htmlspecialchars($lang['login_title'] ?? ''); ?></h2>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-envelope auth-input-icon"></i>
                <input type="email" name="email" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['email_placeholder'] ?? ''); ?>" required>
            </div>
            
            <div class="input-group form-group auth-input-wrapper">
                <i class="fas fa-lock auth-input-icon"></i>
                <div class="password-wrapper">
                    <input type="password" name="password" id="login_pass" class="input-ctrl" placeholder="<?php echo htmlspecialchars($lang['password_placeholder'] ?? ''); ?>" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('login_pass', this)"></i>
                </div>
            </div>

            <div class="forgot-pass-container">
                <a href="/forgot_password.php" class="forgot-pass-link"><?php echo htmlspecialchars($lang['forgot_password'] ?? ''); ?></a>
            </div>

            <button type="submit" name="login" class="btn-login"><?php echo htmlspecialchars($lang['btn_login'] ?? ''); ?></button>
        </form>

        <?php if ($google_login_url !== "#"): ?>
        <a href="<?php echo htmlspecialchars($google_login_url); ?>" class="btn-google">
            <i class="fab fa-google google-icon"></i>
            <?php echo htmlspecialchars($lang['login_with_google'] ?? ''); ?>
        </a>
        <?php endif; ?>

        <p class="register-text">
            <?php echo htmlspecialchars($lang['no_account'] ?? ''); ?> 
            <a href="/register.php" class="register-link"><?php echo htmlspecialchars($lang['register_now'] ?? ''); ?></a>
        </p>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>