<?php
if (session_status() === PHP_SESSION_NONE) { 
	session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

$nav_is_logged_in = isset($_SESSION['user_id']);
$nav_is_admin = $nav_is_logged_in && isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']);

if ($nav_is_logged_in) {
	$nav_user_link = "/profile.php";
	$nav_user_label = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : $lang['my_account'];
	$nav_user_icon = "fas fa-user";
} else {
	$nav_user_link = "/login.php"; 
	$nav_user_label = $lang['login'];
	$nav_user_icon = "fas fa-sign-in-alt";
}

$nav_site_icon = $store_settings['site_icon'] ?? '';
$show_cart_btn = $store_settings['show_cart'] ?? 1;
$show_wishlist_btn = $store_settings['show_wishlist'] ?? 1;

$nav_cart_count = 0;
if (isset($_SESSION['user_id']) && isset($conn)) {
	$nav_u_id = intval($_SESSION['user_id']);
	$stmt = $conn->prepare("SELECT SUM(quantity) as count FROM cart WHERE user_id = ?");
	$stmt->bind_param("i", $nav_u_id);
	$stmt->execute();
	$cart_res = $stmt->get_result();
	if ($cart_res && $cart_res->num_rows > 0) { 
		$c = $cart_res->fetch_assoc()['count']; 
		$nav_cart_count = empty($c) ? 0 : $c; 
	}
} elseif (isset($_COOKIE['guest_cart_token']) && isset($conn)) {
	$guest_token = $_COOKIE['guest_cart_token'];
	$stmt = $conn->prepare("SELECT SUM(quantity) as count FROM cart WHERE session_id = ?");
	$stmt->bind_param("s", $guest_token);
	$stmt->execute();
	$cart_res = $stmt->get_result();
	if ($cart_res && $cart_res->num_rows > 0) { 
		$c = $cart_res->fetch_assoc()['count']; 
		$nav_cart_count = empty($c) ? 0 : $c; 
	}
}

$nav_search_val = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
$current_url = $_SERVER['REQUEST_URI'];
$url_parts = parse_url($current_url);
$base_path = $url_parts['path'] ?? '/';
parse_str($url_parts['query'] ?? '', $query_params);

$user_pic_path = "";
if (isset($_SESSION['profile_pic']) && !empty($_SESSION['profile_pic'])) {
	$user_pic_path = $_SESSION['profile_pic'];
	if (strpos($user_pic_path, 'http') !== 0 && strpos($user_pic_path, '/') !== 0) {
		$user_pic_path = '/' . $user_pic_path;
	}
}
?>

<header class="main-header">
	<div id="global-notification-container" class="notification-container"></div>
	
	<div class="top-bar container header-main-wrapper">
		<div class="header-right-group">
			<div class="logo-box">
				<a href="/index.php" class="store-return-btn">
					<i class="fas fa-store"></i> <span><?php echo $lang['store']; ?></span>
				</a>
				<?php if ($nav_is_admin): ?>
					<a href="/admin/index.php" class="store-return-btn admin-btn">
						<i class="fas fa-user-shield"></i> <span><?php echo $lang['admin_panel']; ?></span>
					</a>
				<?php endif; ?>
			</div>
			
			<div class="search-container">
				<form action="/products.php" method="GET" class="search-form">
					<input type="text" name="search" placeholder="<?php echo $lang['search_placeholder']; ?>" value="<?php echo $nav_search_val; ?>" class="search-input">
					<button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
				</form>
			</div>
		</div>
		
		<div class="user-actions">
			
			<a href="<?php echo htmlspecialchars($nav_user_link); ?>" class="action-btn">
				<?php if (!empty($user_pic_path)): ?>
					<img src="<?php echo htmlspecialchars($user_pic_path); ?>" class="nav-user-avatar" alt="<?php echo htmlspecialchars($nav_user_label); ?>">
				<?php else: ?>
					<i class="<?php echo htmlspecialchars($nav_user_icon); ?>"></i> 
				<?php endif; ?>
				<span><?php echo htmlspecialchars($nav_user_label); ?></span>
			</a>
			
			<?php if ($show_wishlist_btn == 1): ?>
			<a href="/wishlist.php" class="action-btn">
				<i class="far fa-heart"></i> <span class="hide-mobile-text"><?php echo $lang['wishlist']; ?></span>
			</a>
			<?php endif; ?>
			
			<?php if ($show_cart_btn == 1): ?>
			<a href="/cart.php" class="action-btn">
				<i class="fas fa-shopping-cart"></i> 
				<span class="hide-mobile-text"><?php echo $lang['cart']; ?></span>
				<span class="cart-count"><?php echo htmlspecialchars($nav_cart_count); ?></span>
			</a>
			<?php endif; ?>
			
			<div class="nav-lang-dropdown">
				<button type="button" class="action-btn">
					<i class="fas fa-globe"></i> <span class="hide-mobile-text"><?php echo strtoupper($current_lang); ?></span>
				</button>
				<div class="nav-lang-menu">
					<?php
					$available_langs = ['en' => $lang['lang_en'], 'ar' => $lang['lang_ar'], 'he' => $lang['lang_he']];
					foreach($available_langs as $code => $name):
						$query_params['lang'] = $code;
						$link = $base_path . '?' . http_build_query($query_params);
					?>
						<a href="<?php echo htmlspecialchars($link); ?>" class="<?php echo ($current_lang == $code) ? 'active' : ''; ?>">
							<?php echo $name; ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			
			<button id="darkModeToggle" class="action-btn dark-mode-toggle">
				<i class="fas fa-moon"></i>
			</button>

		</div>
	</div>
</header>