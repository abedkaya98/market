<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db_connection.php';

ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'not_logged_in']);
    exit();
}

if (isset($_POST['product_id'])) {
    $user_id = intval($_SESSION['user_id']);
    $product_id = intval($_POST['product_id']);

    if ($product_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'invalid_product']);
        exit();
    }

    $check_stmt = $conn->prepare("SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?");
    $check_stmt->bind_param("ii", $user_id, $product_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        $del_stmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
        $del_stmt->bind_param("ii", $user_id, $product_id);
        
        if ($del_stmt->execute()) {
            echo json_encode(['status' => 'success', 'action' => 'removed']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'db_error']);
        }
    } else {
        $ins_stmt = $conn->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
        $ins_stmt->bind_param("ii", $user_id, $product_id);
        
        if ($ins_stmt->execute()) {
            echo json_encode(['status' => 'success', 'action' => 'added']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'db_error']);
        }
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'invalid_request']);
}
exit();
?>