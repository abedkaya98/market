<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db_connection.php';

$saved_lang = $_SESSION['lang'] ?? $_COOKIE['lang'] ?? 'en';

if (isset($_SESSION['user_id'])) {
    $user_id = intval($_SESSION['user_id']);
    if (isset($conn)) {
        $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
}

$_SESSION = array();

if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, "/");
}

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

setcookie('lang', $saved_lang, time() + (86400 * 365), "/");

header("Location: /login.php");
exit();
?>