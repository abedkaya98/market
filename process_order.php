<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /cart.php");
    exit();
}

$u_id = $_SESSION['user_id'] ?? null;
$s_id = $_COOKIE['guest_cart_token'] ?? null;

if (!$u_id && !$s_id) {
    header("Location: /cart.php");
    exit();
}

$cart_items = [];
$subtotal = 0;

if ($u_id) {
    $stmt = $conn->prepare("SELECT c.id as cart_id, c.product_id, c.variant_id, c.quantity, p.price, p.old_price, p.discount_expiry, p.name FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $stmt->bind_param("i", $u_id);
} else {
    $stmt = $conn->prepare("SELECT c.id as cart_id, c.product_id, c.variant_id, c.quantity, p.price, p.old_price, p.discount_expiry, p.name FROM cart c JOIN products p ON c.product_id = p.id WHERE c.session_id = ?");
    $stmt->bind_param("s", $s_id);
}

$stmt->execute();
$result = $stmt->get_result();

$current_time = time();
while ($row = $result->fetch_assoc()) {
    $expiry_timestamp = !empty($row['discount_expiry']) ? strtotime($row['discount_expiry']) : 0;
    $is_discount_valid = ($expiry_timestamp > $current_time) || ($expiry_timestamp == 0 && !empty($row['old_price']) && $row['old_price'] > $row['price']);
    
    $actual_price = $is_discount_valid ? $row['price'] : ((!empty($row['old_price']) && $row['old_price'] > 0) ? $row['old_price'] : $row['price']);
    
    $row['price'] = $actual_price; 
    $cart_items[] = $row;
    $subtotal += ($actual_price * $row['quantity']);
}

if (empty($cart_items)) {
    header("Location: /cart.php");
    exit();
}

$full_name        = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
$city             = $conn->real_escape_string(trim($_POST['city'] ?? ''));
$address          = $conn->real_escape_string(trim($_POST['address'] ?? ''));
$notes            = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
$shipping_area_id = intval($_POST['shipping_area'] ?? 0);
$payment_method   = (isset($_POST['payment_method']) && $_POST['payment_method'] === 'card') ? 'card' : 'cod';

$save_new_address = intval($_POST['save_new_address'] ?? 0);

$saved_phone_id = $_POST['saved_phone_id'] ?? 'new';
$save_new_phone = intval($_POST['save_new_phone'] ?? 0);
$country_code   = preg_replace('/[^0-9]/', '', $_POST['country_code'] ?? '');
$raw_phone      = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');

$final_phone = "";

if ($u_id && $saved_phone_id !== 'new') {
    $ph_stmt = $conn->prepare("SELECT country_code, phone_number FROM user_phones WHERE id = ? AND user_id = ?");
    $ph_stmt->bind_param("ii", $saved_phone_id, $u_id);
    $ph_stmt->execute();
    $ph_res = $ph_stmt->get_result()->fetch_assoc();
    if ($ph_res) {
        $final_phone = $ph_res['country_code'] . $ph_res['phone_number'];
    }
}

if (empty($final_phone)) {
    $final_phone = $country_code . $raw_phone;
    
    if ($u_id && $save_new_phone === 1 && !empty($raw_phone)) {
        $upd_def_ph = $conn->prepare("UPDATE user_phones SET is_default = 0 WHERE user_id = ?");
        $upd_def_ph->bind_param("i", $u_id);
        $upd_def_ph->execute();

        $ins_ph = $conn->prepare("INSERT INTO user_phones (user_id, country_code, phone_number, is_default) VALUES (?, ?, ?, 1)");
        $ins_ph->bind_param("iss", $u_id, $country_code, $raw_phone);
        $ins_ph->execute();
    }
}

$phone = $conn->real_escape_string($final_phone);

$shipping_cost = 0;
$ship_stmt = $conn->prepare("SELECT price FROM shipping_areas WHERE id = ?");
$ship_stmt->bind_param("i", $shipping_area_id);
$ship_stmt->execute();
$ship_res = $ship_stmt->get_result()->fetch_assoc();
if ($ship_res) {
    $shipping_cost = floatval($ship_res['price']);
}

$total_amount = $subtotal + $shipping_cost;

$conn->begin_transaction();

try {
    $insert_order = $conn->prepare("
        INSERT INTO orders (user_id, guest_session_id, full_name, phone, city, address, notes, shipping_area_id, shipping_cost, subtotal, total_amount, payment_method, status, language) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $insert_order->bind_param(
        "issssssidddss", 
        $u_id, $s_id, $full_name, $phone, $city, $address, $notes, $shipping_area_id, $shipping_cost, $subtotal, $total_amount, $payment_method, $current_lang
    );
    $insert_order->execute();
    $order_id = $conn->insert_id;

    if (!$order_id) {
        $err_msg = $lang['order_creation_failed'] ?? 'Error';
        throw new Exception($err_msg);
    }

    $saved_address_id = $_POST['saved_address_id'] ?? 'new';

    if ($u_id && $saved_address_id === 'new' && $save_new_address === 1) {
        $upd_def = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
        $upd_def->bind_param("i", $u_id);
        $upd_def->execute();

        $insert_addr = $conn->prepare("INSERT INTO user_addresses (user_id, city, address_details, is_default) VALUES (?, ?, ?, 1)");
        $insert_addr->bind_param("iss", $u_id, $city, $address);
        $insert_addr->execute();
    }

    $insert_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, ?, ?, ?)");
    
    // أوامر تحديث المخزون
    $upd_v_stock = $conn->prepare("UPDATE product_variants SET stock = GREATEST(0, stock - ?) WHERE id = ?");
    $upd_p_stock = $conn->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");

    foreach ($cart_items as $item) {
        $p_id = intval($item['product_id']);
        $v_id = intval($item['variant_id']);
        $qty  = intval($item['quantity']);
        $prc  = floatval($item['price']);

        $insert_item->bind_param("iiiid", $order_id, $p_id, $v_id, $qty, $prc);
        if (!$insert_item->execute()) {
            $err_msg2 = $lang['item_save_failed'] ?? 'Error';
            throw new Exception($err_msg2);
        }

        // تنفيذ الخصم
        if ($v_id > 0) {
            $upd_v_stock->bind_param("ii", $qty, $v_id);
            $upd_v_stock->execute();
        } else {
            $upd_p_stock->bind_param("ii", $qty, $p_id);
            $upd_p_stock->execute();
        }
    }

    if ($u_id) {
        $del_cart = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $del_cart->bind_param("i", $u_id);
    } else {
        $del_cart = $conn->prepare("DELETE FROM cart WHERE session_id = ?");
        $del_cart->bind_param("s", $s_id);
    }
    $del_cart->execute();

    $conn->commit();

    $settings_query = $conn->query("SELECT currency, social_whatsapp, wa_admin_order_alerts, wa_customer_status_updates, admin_alert_lang FROM settings WHERE id = 1");
    $site_settings = $settings_query ? $settings_query->fetch_assoc() : [];
    
    $site_currency = $site_settings['currency'] ?? '₪';
    $admin_phone_number = trim($site_settings['social_whatsapp'] ?? '');
    
    $wa_admin_alerts = isset($site_settings['wa_admin_order_alerts']) ? intval($site_settings['wa_admin_order_alerts']) : 1;
    $wa_customer_alerts = isset($site_settings['wa_customer_status_updates']) ? intval($site_settings['wa_customer_status_updates']) : 1;
    $admin_lang_code = $site_settings['admin_alert_lang'] ?? 'ar';
    
    $whatsapp_helper_path = __DIR__ . '/includes/whatsapp_helper.php';
    
    if (file_exists($whatsapp_helper_path)) {
        require_once $whatsapp_helper_path;
        
        $area_name = "";
        $area_stmt = $conn->prepare("SELECT name FROM shipping_areas WHERE id = ?");
        $area_stmt->bind_param("i", $shipping_area_id);
        $area_stmt->execute();
        $area_res = $area_stmt->get_result()->fetch_assoc();
        if ($area_res) { $area_name = $area_res['name']; }

        $merged_city_address = "{$city} - {$address}";
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $base_url = "{$protocol}://{$_SERVER['HTTP_HOST']}";

        $admin_order_link = "{$base_url}/admin/view_order.php?id={$order_id}";
        $track_orders_link = "{$base_url}/profile.php?tab=my-orders"; 
        
        $order_details_text = "";
        foreach ($cart_items as $item) {
            $product_name = $item['name'];
            $quantity = $item['quantity'];
            $variant_info = ""; 

            if (!empty($item['variant_id'])) {
                $var_stmt = $conn->prepare("SELECT color_name, size_value FROM product_variants WHERE id = ?");
                $var_stmt->bind_param("i", $item['variant_id']);
                $var_stmt->execute();
                $var_res = $var_stmt->get_result()->fetch_assoc();
                if ($var_res) {
                    $parts = [];
                    if (!empty($var_res['color_name'])) $parts[] = $var_res['color_name'];
                    if (!empty($var_res['size_value'])) $parts[] = $var_res['size_value'];
                    if (!empty($parts)) $variant_info = " (" . implode(" - ", $parts) . ")";
                }
            }
            $order_details_text .= "▪️ {$quantity}x {$product_name}{$variant_info}\n";
        }
        
        $replace_keys = ['{order_id}', '{customer_name}', '{customer_phone}', '{total}', '{currency}', '{city_address}', '{shipping_area}', '{order_details}', '{admin_link}', '{track_link}'];
        $replace_vals = [$order_id, $full_name, $phone, number_format($total_amount, 2), $site_currency, $merged_city_address, $area_name, $order_details_text, $admin_order_link, $track_orders_link];

        if ($wa_admin_alerts === 1 && !empty($admin_phone_number)) {
            $admin_lang_array = $lang; 
            $admin_lang_file = __DIR__ . "/lang/{$admin_lang_code}.php";
            if (file_exists($admin_lang_file)) {
                $get_admin_lang = function($file) {
                    include $file;
                    return $lang ?? [];
                };
                $admin_lang_array = $get_admin_lang($admin_lang_file);
            }
            
            $admin_msg_template = $admin_lang_array['whatsapp_new_order'] ?? '';
            if (!empty($admin_msg_template)) {
                $admin_whatsapp_msg = str_replace($replace_keys, $replace_vals, $admin_msg_template);
                sendWhatsAppNotification($admin_phone_number, $admin_whatsapp_msg);
            }
        }

        if ($wa_customer_alerts === 1 && !empty($phone)) {
            $customer_msg_template = $lang['whatsapp_customer_receipt'] ?? '';
            if (!empty($customer_msg_template)) {
                $customer_whatsapp_msg = str_replace($replace_keys, $replace_vals, $customer_msg_template);
                sendWhatsAppNotification($phone, $customer_whatsapp_msg);
            }
        }
    }

    header("Location: /success.php?order_id=" . $order_id);
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $error_msg = urlencode($e->getMessage());
    header("Location: /cart.php?error=" . $error_msg);
    exit();
}
?>