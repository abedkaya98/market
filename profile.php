<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

if (!isset($_SESSION['user_logged_in']) && !isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

$user_id = intval($_SESSION['user_id']);
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : (isset($_POST['tab']) ? $_POST['tab'] : 'my-info');

if (isset($_FILES['profile_image'])) {
    $file = $_FILES['profile_image'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($ext, $allowed_ext)) {
        $new_name = "user_" . $user_id . "_" . time() . "." . $ext;
        $upload_dir = __DIR__ . "/assets/profiles/";
        $upload_path = "assets/profiles/" . $new_name;
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_name)) {
            $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
            $stmt->bind_param("si", $upload_path, $user_id);
            $stmt->execute();
            $_SESSION['user_pic'] = '/' . $upload_path;
            header("Location: /profile.php?tab=my-info&success=img");
            exit();
        }
    }
}

if (isset($_POST['update_profile_info'])) {
    $full_name = trim($_POST['full_name']);
    $phone     = trim($_POST['phone']);

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
    $stmt->bind_param("ssi", $full_name, $phone, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['full_name'] = $full_name;
        header("Location: /profile.php?tab=my-info&success=profile_updated");
    } else {
        header("Location: /profile.php?tab=my-info&error=update_failed");
    }
    exit();
}

if (isset($_POST['add_new_address'])) {
    $city    = trim($_POST['city']);
    $details = trim($_POST['address_details']);
    $is_def  = isset($_POST['is_default']) ? 1 : 0;

    if ($is_def == 1) {
        $stmt_reset = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
        $stmt_reset->bind_param("i", $user_id);
        $stmt_reset->execute();
    }

    $stmt = $conn->prepare("INSERT INTO user_addresses (user_id, city, address_details, is_default) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $user_id, $city, $details, $is_def);
    
    if ($stmt->execute()) {
        header("Location: /profile.php?tab=my-address&success=address_added");
    } else {
        header("Location: /profile.php?tab=my-address&error=address_failed");
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'delete_address' && isset($_GET['id'])) {
    $address_id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $address_id, $user_id);
    $stmt->execute();
    header("Location: /profile.php?tab=my-address");
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'set_default_address' && isset($_GET['id'])) {
    $address_id = (int)$_GET['id'];
    
    $stmt_reset = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
    $stmt_reset->bind_param("i", $user_id);
    $stmt_reset->execute();
    
    $stmt_set = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
    $stmt_set->bind_param("ii", $address_id, $user_id);
    $stmt_set->execute();
    
    header("Location: /profile.php?tab=my-address&success=default_updated");
    exit();
}

if (isset($_POST['update_password_action'])) {
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];
    
    $stmt = $conn->prepare("SELECT password, google_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_query_pass = $stmt->get_result()->fetch_assoc();
    
    if ($new_pass !== $conf_pass) {
        header("Location: /profile.php?tab=security&error=no_match");
        exit();
    }

    if (empty($user_query_pass['google_id']) && !empty($user_query_pass['password'])) {
        $current_pass = $_POST['current_password'] ?? '';
        if (!password_verify($current_pass, $user_query_pass['password'])) {
            header("Location: /profile.php?tab=security&error=wrong_old");
            exit();
        }
    }

    $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
    $stmt_upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt_upd->bind_param("si", $hashed_password, $user_id);
    
    if ($stmt_upd->execute()) {
        header("Location: /profile.php?tab=security&success=pass_updated");
    } else {
        header("Location: /profile.php?tab=security&error=db_error");
    }
    exit();
}

$stmt_user = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

$site_name = $store_settings['site_name'];
$site_icon = $store_settings['site_icon'];
$site_currency = $store_settings['currency'];
$theme_folder = $store_settings['theme_folder'] ?? 'default';

$user_pic_display = !empty($user_data['profile_pic']) ? $user_data['profile_pic'] : '';
if (!empty($user_pic_display) && strpos($user_pic_display, 'http') !== 0 && strpos($user_pic_display, '/') !== 0) {
    $user_pic_display = '/' . $user_pic_display;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>" dir="<?php echo htmlspecialchars($dir); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" type="image/png" href="/assets/icons/<?php echo htmlspecialchars($site_icon); ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <?php
    $theme_css_path = __DIR__ . '/assets/themes/' . $theme_folder . '/style.css';
    $theme_css_version = file_exists($theme_css_path) ? filemtime($theme_css_path) : time();
    ?>
    <link rel="stylesheet" href="/assets/themes/<?php echo htmlspecialchars($theme_folder); ?>/style.css?v=<?php echo $theme_css_version; ?>">
</head>
<body class="profile-page-body">

<?php include __DIR__ . '/header.php'; ?>

<div class="floating-menu-btn" id="floatingMenuBtn" onclick="toggleSidebar()">
    <i class="fas fa-bars" id="menuIcon"></i>
</div>

<div class="profile-wrapper">
    
    <aside class="profile-sidebar" id="profile-sidebar">
        <h3 class="sidebar-title"><i class="fas fa-cogs sidebar-icon"></i> <?php echo htmlspecialchars($lang['account_settings']); ?></h3>
        <ul class="tab-menu" id="profile-tab-menu">
            <li class="tab-link <?php echo ($current_tab == 'my-info') ? 'active' : ''; ?>" id="btn-my-info" onclick="openTab(event, 'my-info')">
                <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($lang['my_personal_info']); ?>
            </li>
            <li class="tab-link <?php echo ($current_tab == 'my-orders') ? 'active' : ''; ?>" id="btn-my-orders" onclick="openTab(event, 'my-orders')">
                <i class="fas fa-box-open"></i> <?php echo htmlspecialchars($lang['order_history']); ?>
            </li>
            <li class="tab-link <?php echo ($current_tab == 'my-address') ? 'active' : ''; ?>" id="btn-my-address" onclick="openTab(event, 'my-address')">
                <i class="fas fa-map-marked-alt"></i> <?php echo htmlspecialchars($lang['shipping_addresses']); ?>
            </li>
            <li class="tab-link <?php echo ($current_tab == 'security') ? 'active' : ''; ?>" id="btn-security" onclick="openTab(event, 'security')">
                <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($lang['security_and_password']); ?>
            </li>
            
            <a href="/logout.php" class="tab-link logout"><i class="fas fa-sign-out-alt"></i> <?php echo htmlspecialchars($lang['logout']); ?></a>
        </ul>
    </aside>

    <main class="profile-content">
        
        <div id="my-info" class="tab-content <?php echo ($current_tab == 'my-info') ? 'active' : ''; ?>">
            
            <div class="info-header-card">
                <div class="avatar-edit" onclick="document.getElementById('fileInput').click();">
                    <?php if(!empty($user_pic_display)): ?>
                        <img src="<?php echo htmlspecialchars($user_pic_display); ?>" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                    <div class="upload-icon"><i class="fas fa-camera"></i></div>
                    <div class="edit-overlay"><?php echo htmlspecialchars($lang['change_image']); ?></div>
                </div>
                <form action="/profile.php" method="POST" enctype="multipart/form-data" id="imgForm" class="hidden-form">
                    <input type="file" name="profile_image" id="fileInput" accept="image/*" onchange="document.getElementById('imgForm').submit();">
                </form>
                
                <div class="user-details-center">
                    <h2><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
                    <span class="user-email"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email']); ?></span>
                </div>
            </div>

            <hr class="profile-divider">

            <h3 class="section-title"><?php echo htmlspecialchars($lang['update_info']); ?></h3>
            <form action="/profile.php" method="POST">
                <input type="hidden" name="update_profile_info" value="1">
                <input type="hidden" name="tab" value="my-info">
                <div class="form-group">
                    <label><?php echo htmlspecialchars($lang['full_name']); ?></label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars($lang['email_cannot_change']); ?></label>
                    <input type="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" disabled class="input-disabled">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars($lang['phone_number']); ?></label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" placeholder="05XXXXXXXX">
                </div>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> <?php echo htmlspecialchars($lang['save_changes']); ?></button>
            </form>
        </div>

        <div id="my-orders" class="tab-content <?php echo ($current_tab == 'my-orders') ? 'active' : ''; ?>">
            <h3 class="section-title"><?php echo htmlspecialchars($lang['order_history']); ?></h3>
            <?php
            $stmt_orders = $conn->prepare("
                SELECT o.*, 
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count 
                FROM orders o 
                WHERE o.user_id = ? 
                ORDER BY o.id DESC
            ");
            $stmt_orders->bind_param("i", $user_id);
            $stmt_orders->execute();
            $orders_query = $stmt_orders->get_result();

            if($orders_query && $orders_query->num_rows > 0):
                while($order = $orders_query->fetch_assoc()):
                    $status_map = [
                        'pending'   => ['label' => $lang['status_pending'], 'color' => '#f39c12', 'bg' => 'rgba(243, 156, 18, 0.1)', 'icon' => 'fa-clock'],
                        'processing'=> ['label' => $lang['status_processing'], 'color' => '#3498db', 'bg' => 'rgba(52, 152, 219, 0.1)', 'icon' => 'fa-box'],
                        'shipped'   => ['label' => $lang['status_shipped'], 'color' => '#9b59b6', 'bg' => 'rgba(155, 89, 182, 0.1)', 'icon' => 'fa-truck'],
                        'completed' => ['label' => $lang['status_completed'], 'color' => '#2e7d32', 'bg' => 'rgba(46, 125, 50, 0.1)', 'icon' => 'fa-check-circle'],
                        'cancelled' => ['label' => $lang['status_cancelled'], 'color' => '#d32f2f', 'bg' => 'rgba(211, 47, 47, 0.1)', 'icon' => 'fa-times-circle']
                    ];
                    $current_status = $status_map[$order['status']] ?? ['label' => $order['status'], 'color' => '#666', 'bg' => '#f4f4f4', 'icon' => 'fa-box'];
            ?>
                <div class="order-item" onclick="showOrderDetails(<?php echo htmlspecialchars($order['id']); ?>)">
                    <div class="order-item-flex">
                        <div class="order-status-icon" style="background: <?php echo htmlspecialchars($current_status['bg']); ?>; color: <?php echo htmlspecialchars($current_status['color']); ?>;">
                            <i class="fas <?php echo htmlspecialchars($current_status['icon']); ?>"></i>
                        </div>
                        <div class="order-meta">
                            <strong class="order-id-txt"><?php echo htmlspecialchars($lang['order_number']); ?> #<?php echo htmlspecialchars($order['id']); ?></strong>
                            <small class="order-date-txt"><i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars(date('Y-m-d', strtotime($order['created_at']))); ?> &nbsp; &bull; &nbsp; <?php echo htmlspecialchars($order['items_count']); ?> <?php echo htmlspecialchars($lang['products_count']); ?></small>
                        </div>
                    </div>
                    <div class="order-price-col">
                        <span class="order-badge" style="background: <?php echo htmlspecialchars($current_status['bg']); ?>; color: <?php echo htmlspecialchars($current_status['color']); ?>;">
                            <?php echo htmlspecialchars($current_status['label']); ?>
                        </span>
                        <div class="order-total-price"><?php echo htmlspecialchars(number_format($order['total_amount'], 2)); ?> <?php echo htmlspecialchars($site_currency); ?></div>
                    </div>
                </div>
            <?php endwhile; else: ?>
                <div class="empty-state-box">
                    <i class="fas fa-box-open empty-icon"></i>
                    <p class="empty-text"><?php echo htmlspecialchars($lang['no_previous_orders']); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div id="my-address" class="tab-content <?php echo ($current_tab == 'my-address') ? 'active' : ''; ?>">
            <h3 class="section-title"><?php echo htmlspecialchars($lang['my_shipping_addresses']); ?></h3>

            <div class="address-list">
                <?php
                $stmt_addr = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC");
                $stmt_addr->bind_param("i", $user_id);
                $stmt_addr->execute();
                $addresses = $stmt_addr->get_result();
                
                if($addresses->num_rows > 0):
                    while($addr = $addresses->fetch_assoc()):
                ?>
                    <div class="address-item <?php echo $addr['is_default'] ? 'is-default' : ''; ?>">
                        <div class="addr-header-flex">
                            <strong class="addr-city-name"><i class="fas fa-map-marker-alt addr-icon"></i> <?php echo htmlspecialchars($addr['city']); ?></strong>
                            <?php if($addr['is_default']): ?> 
                                <span class="badge-default"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($lang['default_address_badge']); ?></span> 
                            <?php endif; ?>
                        </div>
                        <p class="addr-details-txt">
                            <?php echo htmlspecialchars($addr['address_details']); ?>
                        </p>
                        
                        <div class="address-actions">
                            <?php if(!$addr['is_default']): ?>
                                <a href="/profile.php?action=set_default_address&id=<?php echo htmlspecialchars($addr['id']); ?>" class="action-link link-success">
                                    <i class="fas fa-star"></i> <?php echo htmlspecialchars($lang['set_as_default']); ?>
                                </a>
                            <?php endif; ?>
                            
                            <a href="javascript:void(0);" onclick="confirmDelete(<?php echo htmlspecialchars($addr['id']); ?>)" class="action-link link-danger">
                                <i class="fas fa-trash-alt"></i> <?php echo htmlspecialchars($lang['delete_address']); ?>
                            </a>
                        </div>
                    </div>
                <?php endwhile; else: ?>
                    <div class="empty-state-box">
                        <i class="fas fa-map-marked-alt empty-icon"></i>
                        <p class="empty-text"><?php echo htmlspecialchars($lang['no_addresses_yet']); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <hr class="profile-divider-dashed">

            <h4 class="add-addr-title"><i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars($lang['add_new_address']); ?></h4>
            <form action="/profile.php" method="POST" class="add-addr-form">
                <input type="hidden" name="add_new_address" value="1">
                <input type="hidden" name="tab" value="my-address">
                <div class="addr-grid">
                    <div class="form-group">
                        <label><?php echo htmlspecialchars($lang['city']); ?></label>
                        <input type="text" name="city" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars($lang['address_details']); ?></label>
                        <input type="text" name="address_details" required>
                    </div>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_default" id="is_default" value="1" class="custom-checkbox">
                    <label for="is_default" class="checkbox-label"><?php echo htmlspecialchars($lang['set_as_default_shipping']); ?></label>
                </div>
                <button type="submit" class="btn-save"><i class="fas fa-plus"></i> <?php echo htmlspecialchars($lang['add_address_btn']); ?></button>
            </form>
        </div>

        <div id="security" class="tab-content <?php echo ($current_tab == 'security') ? 'active' : ''; ?>">
            <h3 class="section-title"><?php echo htmlspecialchars($lang['security_and_account']); ?></h3>

            <div class="social-linking-box">
                <h4 class="social-linking-title"><i class="fas fa-link"></i> <?php echo htmlspecialchars($lang['social_linking']); ?></h4>
                
                <?php if (empty($user_data['google_id'])): ?>
                    <p class="social-linking-desc"><?php echo htmlspecialchars($lang['link_google_desc']); ?></p>
                    <a href="/login.php" class="btn-google-link">
                        <i class="fab fa-google"></i> <?php echo htmlspecialchars($lang['link_google_btn']); ?>
                    </a>
                <?php else: ?>
                    <div class="social-linked-success">
                        <i class="fas fa-check-circle success-icon"></i> <?php echo htmlspecialchars($lang['google_linked_success']); ?>
                    </div>
                    <p class="social-linking-desc-small"><?php echo htmlspecialchars($lang['google_linked_login']); ?></p>
                <?php endif; ?>
            </div>

            <hr class="profile-divider">

            <h3 class="section-title"><i class="fas fa-key icon-primary"></i> <?php echo htmlspecialchars($lang['update_password']); ?></h3>
            
            <?php if (empty($user_data['password']) && !empty($user_data['google_id'])): ?>
                <div class="alert-box-warning">
                    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($lang['google_pass_warning']); ?>
                </div>
            <?php endif; ?>

            <form action="/profile.php" method="POST">
                <input type="hidden" name="update_password_action" value="1">
                <input type="hidden" name="tab" value="security">
                
                <?php if (!empty($user_data['password'])): ?>
                <div class="form-group">
                    <?php if (empty($user_data['google_id'])): ?>
                        <label><?php echo htmlspecialchars($lang['current_password']); ?></label>
                        <div class="password-wrapper">
                            <input type="password" name="current_password" id="cur_pass" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePass('cur_pass', this)"></i>
                        </div>
                    <?php else: ?>
                        <div class="alert-box-info">
                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($lang['google_set_pass_info']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label><?php echo empty($user_data['password']) ? htmlspecialchars($lang['create_password']) : htmlspecialchars($lang['new_password']); ?></label>
                    <div class="password-wrapper">
                        <input type="password" name="new_password" id="new_pass" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePass('new_pass', this)"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label><?php echo htmlspecialchars($lang['confirm_password']); ?></label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="conf_pass" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePass('conf_pass', this)"></i>
                    </div>
                </div>
                <button type="submit" class="btn-save">
                    <i class="fas fa-shield-alt"></i> <?php echo empty($user_data['password']) ? htmlspecialchars($lang['create_password']) : htmlspecialchars($lang['update_password']); ?>
                </button>
            </form>
        </div>

    </main>
</div>

<div id="orderDetailModal" class="order-modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeOrderModal()">&times;</span>
        <div id="orderModalBody">
            <div class="modal-loader">
                <i class="fas fa-spinner fa-spin loader-icon"></i>
                <p class="loader-txt"><?php echo htmlspecialchars($lang['fetching_order_details']); ?></p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const langAreYouSure = "<?php echo htmlspecialchars($lang['are_you_sure']); ?>";
const langDeleteWarning = "<?php echo htmlspecialchars($lang['delete_address_warning']); ?>";
const langYesDelete = "<?php echo htmlspecialchars($lang['yes_delete_it']); ?>";
const langCancel = "<?php echo htmlspecialchars($lang['cancel']); ?>";
const langErrorLoading = "<?php echo htmlspecialchars($lang['error_loading_data']); ?>";

function toggleSidebar() {
    const sidebar = document.getElementById('profile-sidebar');
    const btn = document.getElementById('floatingMenuBtn');
    const icon = document.getElementById('menuIcon');
    sidebar.classList.toggle('open');
    btn.classList.toggle('open');
    if(sidebar.classList.contains('open')) {
        icon.classList.replace('fa-bars', 'fa-times');
    } else {
        icon.classList.replace('fa-times', 'fa-bars');
    }
}

function togglePass(inputId, icon) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    } else {
        input.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}

function openTab(evt, tabName) {
    const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?tab=' + tabName;
    window.history.pushState({path: newUrl}, '', newUrl);

    const contents = document.getElementsByClassName("tab-content");
    for (let i = 0; i < contents.length; i++) { contents[i].classList.remove("active"); }

    const links = document.getElementsByClassName("tab-link");
    for (let i = 0; i < links.length; i++) { links[i].classList.remove("active"); }

    document.getElementById(tabName).classList.add("active");
    if(evt) { evt.currentTarget.classList.add("active"); } 
    else { document.getElementById('btn-' + tabName).classList.add("active"); }
    
    if(window.innerWidth <= 992) {
        const sidebar = document.getElementById('profile-sidebar');
        const btn = document.getElementById('floatingMenuBtn');
        const icon = document.getElementById('menuIcon');
        sidebar.classList.remove('open');
        btn.classList.remove('open');
        icon.classList.replace('fa-times', 'fa-bars');
    }
}

function confirmDelete(addressId) {
    Swal.fire({
        title: langAreYouSure,
        text: langDeleteWarning,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--primary-color)',
        cancelButtonColor: '#6e7881',
        confirmButtonText: langYesDelete,
        cancelButtonText: langCancel,
        reverseButtons: true,
        background: 'var(--card-bg)',
        color: 'var(--text-color)'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '/profile.php?action=delete_address&id=' + addressId;
        }
    })
}

function showOrderDetails(orderId) {
    const modal = document.getElementById('orderDetailModal');
    const body = document.getElementById('orderModalBody');
    modal.style.display = "flex";
    body.innerHTML = '<div class="modal-loader"><i class="fas fa-spinner fa-spin loader-icon"></i><p class="loader-txt"><?php echo htmlspecialchars($lang['fetching_order_details']); ?></p></div>';

    fetch('/get_order_details.php?order_id=' + orderId + '&lang=<?php echo htmlspecialchars($current_lang); ?>')
        .then(response => response.text())
        .then(html => { body.innerHTML = html; })
        .catch(err => { body.innerHTML = '<div class="modal-loader"><i class="fas fa-exclamation-triangle loader-icon-error"></i><p class="loader-txt">' + langErrorLoading + '</p></div>'; });
}

function closeOrderModal() {
    document.getElementById('orderDetailModal').style.display = "none";
}

window.onclick = function(event) {
    const modal = document.getElementById('orderDetailModal');
    if (event.target == modal) { closeOrderModal(); }
}

const urlParams = new URLSearchParams(window.location.search);
const error = urlParams.get('error');
const success = urlParams.get('success');

const swalConfig = {
    confirmButtonColor: 'var(--primary-color)',
    showConfirmButton: false,
    timer: 1000, 
    background: 'var(--card-bg)',
    color: 'var(--text-color)'
};

if (success === 'pass_updated') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['success']); ?>', text: '<?php echo htmlspecialchars($lang['success_pass_updated']); ?>', icon: 'success' }); }
if (error === 'wrong_old') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['error']); ?>', text: '<?php echo htmlspecialchars($lang['error_wrong_old_pass']); ?>', icon: 'error' }); }
if (error === 'no_match') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['alert']); ?>', text: '<?php echo htmlspecialchars($lang['warning_pass_mismatch']); ?>', icon: 'warning' }); }
if (error === 'db_error') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['failed']); ?>', text: '<?php echo htmlspecialchars($lang['error_db_failed']); ?>', icon: 'error' }); }
if (success === 'img') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['updated']); ?>', text: '<?php echo htmlspecialchars($lang['success_img_updated']); ?>', icon: 'success' }); }
if (success === 'profile_updated') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['updated']); ?>', text: '<?php echo htmlspecialchars($lang['success_profile_updated']); ?>', icon: 'success' }); }
if (success === 'address_added') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['added']); ?>', text: '<?php echo htmlspecialchars($lang['success_address_added']); ?>', icon: 'success' }); }
if (success === 'default_updated') { Swal.fire({ ...swalConfig, title: '<?php echo htmlspecialchars($lang['set_success']); ?>', text: '<?php echo htmlspecialchars($lang['success_default_updated']); ?>', icon: 'success' }); }
</script>

</body>
</html>