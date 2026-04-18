<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

$order_id = intval($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    header("Location: /index.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: /index.php");
    exit();
}

$site_currency = $store_settings['currency'] ?? '';
$site_name = $store_settings['site_name'] ?? '';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$theme_folder = $store_settings['theme_folder'] ?? 'default';

$payment_text = ($order['payment_method'] === 'cod') ? ($lang['payment_cod'] ?? '') : ($lang['payment_credit'] ?? '');

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
<body>

<?php include __DIR__ . '/header.php'; ?>

<div class="success-wrapper">
    <div class="receipt-card">
        
        <div class="success-icon-box">
            <i class="fas fa-check"></i>
        </div>
        
        <h1 class="success-title"><?php echo htmlspecialchars($lang['order_received_successfully'] ?? ''); ?></h1>
        <p class="success-desc">
            <?php echo htmlspecialchars($lang['thanks_for_shopping'] ?? ''); ?> <strong><?php echo htmlspecialchars($order['full_name']); ?></strong>.<br> 
            <?php echo htmlspecialchars($lang['team_will_prepare'] ?? ''); ?>
        </p>

        <div class="order-details-grid">
            <div class="detail-row">
                <span class="detail-label"><?php echo htmlspecialchars($lang['order_number_label'] ?? ''); ?></span>
                <span class="detail-value">#<?php echo htmlspecialchars($order['id']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><?php echo htmlspecialchars($lang['order_date_label'] ?? ''); ?></span>
                <span class="detail-value" dir="ltr"><?php echo date('Y-m-d h:i A', strtotime($order['created_at'])); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><?php echo htmlspecialchars($lang['payment_method_label'] ?? ''); ?></span>
                <span class="detail-value"><?php echo htmlspecialchars($payment_text); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><?php echo htmlspecialchars($lang['grand_total'] ?? ''); ?></span>
                <span class="detail-value highlight"><?php echo number_format($order['total_amount'], 2) . ' ' . htmlspecialchars($site_currency); ?></span>
            </div>
        </div>

        <div class="action-buttons">
            <a href="/index.php" class="btn-primary-action"><i class="fas fa-store"></i> <?php echo htmlspecialchars($lang['continue_shopping'] ?? ''); ?></a>
            
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/profile.php?tab=my-orders" class="btn-secondary-action"><i class="fas fa-box-open"></i> <?php echo htmlspecialchars($lang['track_order'] ?? ''); ?></a>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>