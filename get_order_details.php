<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

$dir = ($current_lang == 'ar') ? 'rtl' : 'ltr';

if (!isset($_SESSION['user_id']) || !isset($_GET['order_id'])) {
    die('<div class="modal-loader"><i class="fas fa-lock loader-icon-error"></i><p class="loader-txt">' . htmlspecialchars($lang['unauthorized_access'] ?? '') . '</p></div>');
}

$user_id = intval($_SESSION['user_id']);
$order_id = intval($_GET['order_id']);

$currency = $store_settings['currency'] ?? '₪';

$order_q = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$order_q->bind_param("ii", $order_id, $user_id);
$order_q->execute();
$order = $order_q->get_result()->fetch_assoc();

if (!$order) {
    die('<div class="modal-loader"><i class="fas fa-search loader-icon" style="color: var(--gray-text);"></i><p class="loader-txt">' . htmlspecialchars($lang['order_not_found'] ?? '') . '</p></div>');
}

$items_q = $conn->prepare("
    SELECT oi.*, p.name, p.image, pv.color_name, pv.size_value, pv.variant_image 
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN product_variants pv ON oi.variant_id = pv.id
    WHERE oi.order_id = ?
");
$items_q->bind_param("i", $order_id);
$items_q->execute();
$items = $items_q->get_result();

$status_map = [
    'pending'   => ['label' => $lang['status_pending'] ?? '', 'color' => '#f39c12', 'bg' => 'rgba(243, 156, 18, 0.1)'],
    'processing'=> ['label' => $lang['status_processing'] ?? '', 'color' => '#3498db', 'bg' => 'rgba(52, 152, 219, 0.1)'],
    'shipped'   => ['label' => $lang['status_shipped'] ?? '', 'color' => '#9b59b6', 'bg' => 'rgba(155, 89, 182, 0.1)'],
    'completed' => ['label' => $lang['status_completed'] ?? '', 'color' => '#2e7d32', 'bg' => 'rgba(46, 125, 50, 0.1)'],
    'cancelled' => ['label' => $lang['status_cancelled'] ?? '', 'color' => '#d32f2f', 'bg' => 'rgba(211, 47, 47, 0.1)']
];
$current_status = $status_map[$order['status']] ?? ['label' => $order['status'], 'color' => 'var(--gray-text)', 'bg' => 'var(--img-bg)'];

$payment_method = ($order['payment_method'] === 'cod') ? ($lang['payment_cod'] ?? '') : ($lang['payment_online'] ?? '');
?>

<div class="order-modal-header">
    <h3><?php echo htmlspecialchars($lang['order_details_title'] ?? ''); ?> #<?php echo htmlspecialchars($order['id']); ?></h3>
    <p><i class="far fa-clock"></i> <?php echo htmlspecialchars($lang['ordered_at'] ?? ''); ?> <span dir="ltr"><?php echo htmlspecialchars(date('Y-m-d h:i A', strtotime($order['created_at']))); ?></span></p>
</div>

<div class="order-modal-info">
    <div class="detail-row">
        <span class="detail-label"><?php echo htmlspecialchars($lang['order_status_label'] ?? ''); ?></span>
        <span class="order-badge" style="background: <?php echo htmlspecialchars($current_status['bg']); ?>; color: <?php echo htmlspecialchars($current_status['color']); ?>; margin: 0;">
            <?php echo htmlspecialchars($current_status['label']); ?>
        </span>
    </div>
    <div class="detail-row">
        <span class="detail-label"><?php echo htmlspecialchars($lang['payment_method_label'] ?? ''); ?></span>
        <span class="detail-value"><?php echo htmlspecialchars($payment_method); ?></span>
    </div>
    
    <div class="detail-row">
        <span class="detail-label"><?php echo htmlspecialchars($lang['phone_req'] ?? ''); ?></span>
        <span class="detail-value" dir="ltr"><?php echo htmlspecialchars($order['phone'] ?? '---'); ?></span>
    </div>

    <div class="detail-row" style="margin-bottom: 0;">
        <span class="detail-label"><?php echo htmlspecialchars($lang['shipping_address_label'] ?? ''); ?></span>
        <span class="detail-value address-value">
            <?php echo htmlspecialchars($order['city']); ?> <br>
            <small><?php echo htmlspecialchars($order['address']); ?></small>
        </span>
    </div>
</div>

<h4 class="order-modal-products-title"><i class="fas fa-box"></i> <?php echo htmlspecialchars($lang['ordered_products'] ?? ''); ?></h4>
<div class="order-modal-table-wrapper">
    <table class="order-items-table">
        <thead>
            <tr>
                <th><?php echo htmlspecialchars($lang['product'] ?? ''); ?></th>
                <th class="text-center"><?php echo htmlspecialchars($lang['quantity'] ?? ''); ?></th>
                <th class="text-center"><?php echo htmlspecialchars($lang['price'] ?? ''); ?></th>
                <th class="text-center"><?php echo htmlspecialchars($lang['total'] ?? ''); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php while($item = $items->fetch_assoc()): 
                $img_src = '/assets/images/' . (!empty($item['variant_image']) ? $item['variant_image'] : $item['image']);
            ?>
            <tr>
                <td>
                    <div class="order-item-prod-info">
                        <img src="<?php echo htmlspecialchars($img_src); ?>" class="prod-img-mini" alt="Product">
                        <div>
                            <span class="prod-name-mini"><?php echo htmlspecialchars($item['name']); ?></span>
                            
                            <?php if(!empty($item['color_name']) || !empty($item['size_value'])): ?>
                                <div class="prod-variants-mini">
                                    <?php if(!empty($item['color_name'])): ?>
                                        <span class="variant-badge">
                                            <?php echo htmlspecialchars($lang['color_label'] ?? ''); ?> <?php echo htmlspecialchars($item['color_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if(!empty($item['size_value'])): ?>
                                        <span class="variant-badge">
                                            <?php echo htmlspecialchars($lang['size_label'] ?? ''); ?> <?php echo htmlspecialchars($item['size_value']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="text-center qty-col">x<?php echo htmlspecialchars($item['quantity']); ?></td>
                <td class="text-center price-col"><?php echo htmlspecialchars(number_format($item['price'], 2)); ?></td>
                <td class="text-center total-col"><?php echo htmlspecialchars(number_format($item['price'] * $item['quantity'], 2)); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="order-modal-summary">
    <div class="detail-row">
        <span class="detail-label"><?php echo htmlspecialchars($lang['subtotal_label'] ?? ''); ?></span>
        <span class="detail-value"><?php echo htmlspecialchars(number_format($order['subtotal'], 2)); ?> <?php echo htmlspecialchars($currency); ?></span>
    </div>
    <div class="detail-row">
        <span class="detail-label"><?php echo htmlspecialchars($lang['delivery_fee_label'] ?? ''); ?></span>
        <span class="detail-value"><?php echo htmlspecialchars(number_format($order['shipping_cost'], 2)); ?> <?php echo htmlspecialchars($currency); ?></span>
    </div>
    <div class="detail-row final-total-row">
        <span class="detail-label"><?php echo htmlspecialchars($lang['final_total_label'] ?? ''); ?></span>
        <span class="final-total-val"><?php echo htmlspecialchars(number_format($order['total_amount'], 2)); ?> <?php echo htmlspecialchars($currency); ?></span>
    </div>
</div>