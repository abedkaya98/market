<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/db_connection.php';

$client = new \Google\Client(); 
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri("https://auth.buxna.site/google-callback.php");

if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (isset($token['access_token']) && !isset($token['error'])) {
        $client->setAccessToken($token['access_token']);

        $google_oauth = new \Google\Service\Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();
        
        $email = $google_account_info->email;
        $name = $google_account_info->name;
        $google_id = $google_account_info->id;
        $picture = $google_account_info->picture;

        $original_store_url = isset($_GET['state']) ? base64_decode($_GET['state']) : '';
        if (empty($original_store_url)) {
            die("Error");
        }

        $stmt = $conn->prepare("SELECT id, status, role, language FROM users WHERE google_id = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $google_id, $email);
        $stmt->execute();
        $user_res = $stmt->get_result();

        if ($user_res->num_rows > 0) {
            $user_data = $user_res->fetch_assoc();
            
            if ($user_data['status'] == 0) {
                $_SESSION['flash_error'] = $lang['err_banned'] ?? 'Banned';
                header("Location: " . rtrim($original_store_url, '/') . "/login.php");
                exit();
            }

            $upd = $conn->prepare("UPDATE users SET google_id = ?, profile_pic = ?, last_login = NOW() WHERE id = ?");
            $upd->bind_param("ssi", $google_id, $picture, $user_data['id']);
            $upd->execute();
            $user_id = $user_data['id'];
        } else {
            $random_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $default_lang = 'ar';
            
            $ins = $conn->prepare("INSERT INTO users (google_id, full_name, profile_pic, email, password, role, status, language) VALUES (?, ?, ?, ?, ?, 'customer', 1, ?)");
            $ins->bind_param("ssssss", $google_id, $name, $picture, $email, $random_pass, $default_lang);
            $ins->execute();
            $user_id = $conn->insert_id;
        }

        $sso_token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $upd_token = $conn->prepare("UPDATE users SET last_login = NOW(), remember_token = ?, token_expiry = ? WHERE id = ?");
        $upd_token->bind_param("ssi", $sso_token, $expiry, $user_id);
        $upd_token->execute();

        header("Location: " . rtrim($original_store_url, '/') . "/login.php?sso_token=" . $sso_token);
        exit();

    } else {
        $_SESSION['flash_error'] = $lang['err_general'] ?? 'Google Auth Failed';
        header("Location: /login.php");
        exit();
    }
} else {
    header("Location: /login.php");
    exit();
}
?>