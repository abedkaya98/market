<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';
require_once __DIR__ . '/includes/whatsapp_helper.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'send') {
    $country_code = preg_replace('/[^0-9]/', '', $_POST['country_code'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $full_phone = $country_code . $phone;

    $otp_code = (string)rand(1000, 9999);
    $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

    $stmt = $conn->prepare("DELETE FROM otp_verifications WHERE phone = ?");
    $stmt->bind_param("s", $full_phone);
    $stmt->execute();

    $stmt = $conn->prepare("INSERT INTO otp_verifications (phone, code, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $full_phone, $otp_code, $expires_at);
    
    if ($stmt->execute()) {
        $otp_template = $lang['otp_whatsapp_msg'] ?? "🔒 *OTP Code:* {code}\nValid for 5 minutes.";
        $otp_message = str_replace('{code}', $otp_code, $otp_template);
        
        $sent = sendWhatsAppNotification($full_phone, $otp_message);
        
        if ($sent) {
            echo json_encode(['status' => 'success', 'message' => $lang['otp_sent'] ?? 'Success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $lang['otp_send_error'] ?? 'Error sending WhatsApp']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => $lang['db_error'] ?? 'Database error']);
    }
    exit;
}

if ($action === 'verify') {
    $country_code = preg_replace('/[^0-9]/', '', $_POST['country_code'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $full_phone = $country_code . $phone;
    $entered_code = trim($_POST['code'] ?? '');

    $stmt = $conn->prepare("SELECT id FROM otp_verifications WHERE phone = ? AND code = ? AND expires_at > NOW()");
    $stmt->bind_param("ss", $full_phone, $entered_code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $conn->query("DELETE FROM otp_verifications WHERE id = " . intval($row['id']));
        
        $_SESSION['verified_phone'] = $full_phone;
        
        echo json_encode(['status' => 'success', 'message' => $lang['phone_verified'] ?? 'Verified successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $lang['otp_invalid'] ?? 'Invalid or expired OTP']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => $lang['invalid_action'] ?? 'Invalid action']);
exit;
?>