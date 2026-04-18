<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

ob_clean();

function getCartIdentifier() {
    if (isset($_SESSION['user_id'])) {
        return ['user_id' => intval($_SESSION['user_id']), 'session_id' => null];
    } else {
        if (isset($_COOKIE['guest_cart_token'])) {
            $token = $_COOKIE['guest_cart_token'];
        } else {
            $token = bin2hex(random_bytes(16));
            setcookie('guest_cart_token', $token, time() + (86400 * 30), "/");
        }
        return ['user_id' => null, 'session_id' => $token];
    }
}

function getCartCount($conn) {
    $identifier = getCartIdentifier();
    
    if ($identifier['user_id'] !== null) {
        $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
        $stmt->bind_param("i", $identifier['user_id']);
    } else {
        $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE session_id = ?");
        $stmt->bind_param("s", $identifier['session_id']);
    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return intval($result['total'] ?? 0);
}

if (isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id'] ?? 0);
    $variant_id = intval($_POST['variant_id'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 1);
    
    $identifier = getCartIdentifier();
    $u_id = $identifier['user_id'];
    $s_id = $identifier['session_id'];

    if ($variant_id > 0) {
        $check_stock = $conn->prepare("SELECT stock FROM product_variants WHERE id = ? AND product_id = ?");
        $check_stock->bind_param("ii", $variant_id, $product_id);
    } else {
        $check_stock = $conn->prepare("SELECT stock FROM products WHERE id = ?");
        $check_stock->bind_param("i", $product_id);
    }
    
    $check_stock->execute();
    $stock_res = $check_stock->get_result()->fetch_assoc();

    $error_qty = $lang['qty_not_available'] ?? '';

    if (!$stock_res || $stock_res['stock'] < $quantity) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $error_qty]);
            exit();
        }
        die($error_qty);
    }

    if ($u_id !== null) {
        $check_cart = $conn->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND variant_id = ?");
        $check_cart->bind_param("iii", $u_id, $product_id, $variant_id);
    } else {
        $check_cart = $conn->prepare("SELECT id, quantity FROM cart WHERE session_id = ? AND product_id = ? AND variant_id = ?");
        $check_cart->bind_param("sii", $s_id, $product_id, $variant_id);
    }
    
    $check_cart->execute();
    $cart_item = $check_cart->get_result()->fetch_assoc();

    if ($cart_item) {
        $new_qty = $cart_item['quantity'] + $quantity;
        if ($new_qty > $stock_res['stock']) {
             $new_qty = $stock_res['stock'];
        }
        $update_stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
        $update_stmt->bind_param("ii", $new_qty, $cart_item['id']);
        $update_stmt->execute();
    } else {
        if ($u_id !== null) {
            $insert_stmt = $conn->prepare("INSERT INTO cart (user_id, session_id, product_id, variant_id, quantity) VALUES (?, NULL, ?, ?, ?)");
            $insert_stmt->bind_param("iiii", $u_id, $product_id, $variant_id, $quantity);
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO cart (user_id, session_id, product_id, variant_id, quantity) VALUES (NULL, ?, ?, ?, ?)");
            $insert_stmt->bind_param("siii", $s_id, $product_id, $variant_id, $quantity);
        }
        $insert_stmt->execute();
    }

    $total_count = getCartCount($conn);
    $success_msg = $lang['added_to_cart_success'] ?? '';

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'cart_count' => $total_count,
            'message' => $success_msg
        ]);
        exit();
    }

    header("Location: /cart.php");
    exit();
}

if (isset($_GET['remove'])) {
    $cart_id = intval($_GET['remove']);
    $identifier = getCartIdentifier();
    
    if ($identifier['user_id'] !== null) {
        $del_stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
        $del_stmt->bind_param("ii", $cart_id, $identifier['user_id']);
    } else {
        $del_stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND session_id = ?");
        $del_stmt->bind_param("is", $cart_id, $identifier['session_id']);
    }
    
    $del_stmt->execute();
    header("Location: /cart.php");
    exit();
}

if (isset($_POST['update_cart_qty'])) {
    $cart_id = intval($_POST['cart_id'] ?? 0);
    $new_qty = intval($_POST['new_qty'] ?? 0);
    $identifier = getCartIdentifier();
    
    if ($identifier['user_id'] !== null) {
        $check = $conn->prepare("SELECT c.product_id, c.variant_id FROM cart c WHERE c.id = ? AND c.user_id = ?");
        $check->bind_param("ii", $cart_id, $identifier['user_id']);
    } else {
        $check = $conn->prepare("SELECT c.product_id, c.variant_id FROM cart c WHERE c.id = ? AND c.session_id = ?");
        $check->bind_param("is", $cart_id, $identifier['session_id']);
    }
    
    $check->execute();
    $cart_item = $check->get_result()->fetch_assoc();
    
    header('Content-Type: application/json');

    if ($cart_item) {
        if ($new_qty <= 0) {
            $del_stmt = $conn->prepare("DELETE FROM cart WHERE id = ?");
            $del_stmt->bind_param("i", $cart_id);
            $del_stmt->execute();
            
            echo json_encode(['status' => 'success', 'action' => 'removed']);
            exit();
        }
        
        if ($cart_item['variant_id'] > 0) {
            $stock_q = $conn->prepare("SELECT stock FROM product_variants WHERE id = ?");
            $stock_q->bind_param("i", $cart_item['variant_id']);
        } else {
            $stock_q = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $stock_q->bind_param("i", $cart_item['product_id']);
        }
        
        $stock_q->execute();
        $stock_row = $stock_q->get_result()->fetch_assoc();
        $stock = intval($stock_row['stock'] ?? 0);
        
        if ($new_qty <= $stock) {
            $upd_stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
            $upd_stmt->bind_param("ii", $new_qty, $cart_id);
            $upd_stmt->execute();
            
            echo json_encode(['status' => 'success', 'action' => 'updated']);
        } else {
            $error_msg = $lang['qty_exceeds_stock'] ?? '';
            echo json_encode(['status' => 'error', 'message' => $error_msg]);
        }
    } else {
        $not_found_msg = $lang['cart_item_not_found'] ?? '';
        echo json_encode(['status' => 'error', 'message' => $not_found_msg]);
    }
    exit();
}
?>