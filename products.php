<?php
if (session_status() === PHP_SESSION_NONE) { 
	session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

date_default_timezone_set('Asia/Amman'); 

$is_logged_in = isset($_SESSION['user_id']);

$site_name = $store_settings['site_name'] ?? '';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$site_currency = $store_settings['currency'] ?? '';
$low_stock_limit = $store_settings['low_stock_threshold'] ?? 5;
$hide_out = $store_settings['hide_out_of_stock'] ?? 0;
$show_best_sellers = $store_settings['show_best_sellers'] ?? 1;
$enable_image_fade = $store_settings['enable_image_fade'] ?? 1;
$show_wishlist = $store_settings['show_wishlist'] ?? 1;

$user_wishlist_ids = [];
if ($is_logged_in && isset($conn)) {
	$uid = intval($_SESSION['user_id']);
	$w_stmt = $conn->prepare("SELECT product_id FROM wishlist WHERE user_id = ?");
	$w_stmt->bind_param("i", $uid);
	$w_stmt->execute();
	$w_res = $w_stmt->get_result();
	while ($w_row = $w_res->fetch_assoc()) {
		$user_wishlist_ids[] = $w_row['product_id'];
	}
}

$sort = $_GET['sort'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

$where_clauses = ["1=1"];
$order_clause = "ORDER BY p.id DESC";
$page_title = $lang['all_products'] ?? ''; 
$page_icon = "fas fa-box";

$having_clause = ($hide_out == 1) ? " HAVING total_stock > 0 " : "";

if ($category_id > 0 && isset($conn)) {
	$cat_query = $conn->prepare("SELECT name, icon FROM categories WHERE id = ? LIMIT 1");
	$cat_query->bind_param("i", $category_id);
	$cat_query->execute();
	$cat_result = $cat_query->get_result();

	if ($cat_result->num_rows > 0) {
		$category = $cat_result->fetch_assoc();
		$cat_display_name = $category['name'];
		$page_title = ($lang['collection'] ?? '') . " " . $cat_display_name;
		$page_icon = !empty($category['icon']) ? $category['icon'] : 'fas fa-tags';
		$where_clauses[] = "p.category_id = $category_id";
	} else {
		header("Location: /index.php");
		exit();
	}
} elseif ($sort === 'new') {
	$page_title = $lang['latest_products'] ?? '';
	$page_icon = "fas fa-magic";
} elseif ($sort === 'sale') {
	$where_clauses[] = "p.price < p.old_price";
	$page_title = $lang['top_deals'] ?? '';
	$page_icon = "fas fa-percentage";
} elseif ($sort === 'best') {
	$page_title = $lang['best_sellers'] ?? '';
	$page_icon = "fas fa-fire";
	$order_clause = "ORDER BY sales_count DESC, p.id DESC";
} elseif (!empty($search) && isset($conn)) {
	$search_clean = $conn->real_escape_string($search);
	$where_clauses[] = "(p.name LIKE '%$search_clean%' OR p.description LIKE '%$search_clean%')";
	$page_title = ($lang['search_results'] ?? '') . " " . htmlspecialchars($search);
	$page_icon = "fas fa-search";
}

$where_sql = implode(" AND ", $where_clauses);
$query = "
	SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock,
	(SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE product_id = p.id) as sales_count
	FROM products p 
	LEFT JOIN product_variants v ON p.id = v.product_id 
	WHERE $where_sql 
	GROUP BY p.id 
	$having_clause
	$order_clause
";

$products = null;
if (isset($conn)) {
	$products = $conn->query($query);
}

function renderProductCard($row, $site_currency, $low_stock_limit) {
	global $user_wishlist_ids, $conn, $lang, $current_lang, $enable_image_fade, $show_wishlist;
	
	$current_time = time();
	$expiry_timestamp = !empty($row['discount_expiry']) ? strtotime($row['discount_expiry']) : 0;
	
	$stock = $row['total_stock'] ?? 0;
	$is_sold_out = ($stock <= 0); 
	
	$is_discount_valid = ($expiry_timestamp > $current_time) || ($expiry_timestamp == 0 && !empty($row['old_price']) && $row['old_price'] > $row['price']);
	if ($is_discount_valid) {
		$display_price = $row['price'];
		$old_price = $row['old_price'];
	} else {
		$display_price = ($row['old_price'] > 0) ? $row['old_price'] : $row['price'];
		$old_price = 0;
	}
	$discount_percentage = ($old_price > 0) ? round((($old_price - $display_price) / $old_price) * 100) : 0;
	
	$is_wished = in_array($row['id'], $user_wishlist_ids);
	$product_name = $row['name'];

	$product_id = intval($row['id']);
	$gallery_images = ["/assets/images/" . $row['image']];
	
	if (isset($conn)) {
		$gallery_query = $conn->prepare("SELECT image FROM product_color_images WHERE product_id = ? LIMIT 5");
		$gallery_query->bind_param("i", $product_id);
		$gallery_query->execute();
		$g_res = $gallery_query->get_result();
		while($g_row = $g_res->fetch_assoc()) {
			$gallery_images[] = "/assets/images/" . $g_row['image'];
		}
	}
	
	$gallery_images = array_values(array_unique($gallery_images));
	$json_images = htmlspecialchars(json_encode($gallery_images), ENT_QUOTES, 'UTF-8');

	$hover_events = "";
	if ($enable_image_fade == 1) {
		$hover_events = "onmouseenter='startImageSlider(this, {$json_images})' onmouseleave='stopImageSlider(this, \"/assets/images/" . htmlspecialchars($row['image']) . "\")' ontouchstart='startImageSlider(this, {$json_images})' ontouchend='stopImageSlider(this, \"/assets/images/" . htmlspecialchars($row['image']) . "\")'";
	}

	ob_start(); ?>
	<div class="product-card <?php echo $is_sold_out ? 'is-sold-out' : ''; ?>" 
		 onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'" <?php echo $hover_events; ?>>
		
		<div class="img-holder">
			<?php if($show_wishlist == 1): ?>
			<button class="wishlist-btn <?php echo $is_wished ? 'active' : ''; ?>" data-id="<?php echo htmlspecialchars($row['id']); ?>" onclick="event.stopPropagation();">
				<i class="<?php echo $is_wished ? 'fas fa-heart' : 'far fa-heart'; ?>"></i>
			</button>
			<?php endif; ?>
			
			<img src="/assets/images/<?php echo htmlspecialchars($row['image']); ?>" loading="lazy" class="product-main-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="<?php echo htmlspecialchars($product_name); ?>">
			
			<?php if($enable_image_fade == 1): ?>
			<img src="" class="product-bg-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="">
			<?php endif; ?>

			<div class="promo-overlay">
				<?php if(!$is_sold_out && $is_discount_valid): ?>
					<div class="unified-discount-box">
						<?php if($discount_percentage > 0): ?> <span><?php echo $discount_percentage; ?>% <?php echo $lang['discount_text'] ?? ''; ?></span> <?php endif; ?>
						<?php if($expiry_timestamp > $current_time): ?>
							<span class="divider">|</span>
							<div class="timer-part" data-expire="<?php echo htmlspecialchars($row['discount_expiry']); ?>">
								<i class="far fa-clock"></i> <span class="countdown">00:00:00</span>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			
			<?php if($is_sold_out): ?>
				<div class="sold-out-center-badge">
					<i class="fas fa-ban"></i> <?php echo $lang['sold_out'] ?? ''; ?>
				</div>
			<?php endif; ?>
		</div>
		
		<div class="info">
			<div class="title-wrapper">
				<h4><?php echo htmlspecialchars($product_name); ?></h4>
				<?php if (!$is_sold_out && $stock > 0 && $stock <= $low_stock_limit): ?>
					<span class="low-stock-badge-fixed"><?php echo $lang['almost_sold_out'] ?? ''; ?></span>
				<?php endif; ?>
			</div>
			<div class="price">
				<span class="current price-now" data-original="<?php echo ($row['old_price'] > 0) ? $row['old_price'] : $row['price']; ?>">
					<?php echo number_format($display_price, 2) . " " . htmlspecialchars($site_currency); ?>
				</span>
				<?php if($old_price > $display_price): ?>
					<span class="old price-old"><?php echo number_format($old_price, 2) . " " . htmlspecialchars($site_currency); ?></span>
				<?php endif; ?>
			</div>
			<div class="card-footer">
				<button type="button" class="add-to-cart <?php echo $is_sold_out ? 'btn-disabled' : ''; ?>" onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'; event.stopPropagation();" <?php echo $is_sold_out ? 'disabled' : ''; ?>>
					<i class="fas <?php echo $is_sold_out ? 'fa-times-circle' : 'fa-sliders-h'; ?>"></i> 
					<?php echo $is_sold_out ? ($lang['product_unavailable'] ?? '') : ($lang['select_options'] ?? ''); ?>
				</button>
			</div>
		</div>
	</div>
	<?php return ob_get_clean();
}

$theme_folder = $store_settings['theme_folder'] ?? 'default';
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
	
	<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<main class="container">
	<div class="page-header">
		<h2><i class="<?php echo htmlspecialchars($page_icon); ?>"></i> <?php echo htmlspecialchars($page_title); ?></h2>
	</div>

	<?php if ($products && $products->num_rows > 0): ?>
		<div class="products-grid">
			<?php while($row = $products->fetch_assoc()) echo renderProductCard($row, $site_currency, $low_stock_limit); ?>
		</div>
	<?php else: ?>
		<div class="empty-products-msg">
			<i class="fas fa-box-open empty-products-icon"></i>
			<h3 class="empty-products-title"><?php echo $lang['no_products_found'] ?? ''; ?></h3>
			<a href="/index.php" class="btn-back-home"><?php echo $lang['back_to_home'] ?? ''; ?></a>
		</div>
	<?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
const siteCurrency = "<?php echo htmlspecialchars($site_currency); ?>";
const msgAdded = "<?php echo isset($lang['added_to_wishlist']) ? addslashes($lang['added_to_wishlist']) : ''; ?>";
const msgRemoved = "<?php echo isset($lang['removed_from_wishlist']) ? addslashes($lang['removed_from_wishlist']) : ''; ?>";

function startCountdowns() {
	document.querySelectorAll('.timer-part').forEach(timer => {
		const expiryStr = timer.getAttribute('data-expire');
		if (!expiryStr) return;
		const expiryDate = new Date(expiryStr.replace(/-/g, "/")).getTime();
		const display = timer.querySelector('.countdown');
		const card = timer.closest('.product-card');
		const priceNowElement = card.querySelector('.price-now');
		const priceOldElement = card.querySelector('.price-old');
		
		const update = setInterval(() => {
			const now = new Date().getTime();
			const distance = expiryDate - now;
			
			if (distance <= 0) {
				clearInterval(update);
				const unifiedBox = timer.closest('.unified-discount-box');
				if(unifiedBox) unifiedBox.style.display = 'none';
				const originalPrice = parseFloat(priceNowElement.getAttribute('data-original')).toFixed(2);
				priceNowElement.innerHTML = originalPrice + " " + siteCurrency;
				if(priceOldElement) priceOldElement.style.display = 'none';
				return;
			}
			
			const d = Math.floor(distance / (1000 * 60 * 60 * 24));
			const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
			const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
			const s = Math.floor((distance % (1000 * 60)) / 1000);
			
			let daysStr = d > 0 ? d + " : " : "";
			display.innerHTML = daysStr + (h < 10 ? '0'+h : h) + ":" + (m < 10 ? '0'+m : m) + ":" + (s < 10 ? '0'+s : s);
		}, 1000);
	});
}

function initWishlist() {
	document.querySelectorAll('.wishlist-btn').forEach(btn => {
		const newBtn = btn.cloneNode(true);
		btn.parentNode.replaceChild(newBtn, btn);
		
		newBtn.addEventListener('click', function(e) {
			e.preventDefault(); 
			e.stopPropagation(); 
			
			if (!isLoggedIn) { 
				window.location.href = '/login.php'; 
				return; 
			}
			
			const productId = this.getAttribute('data-id');
			const allBtns = document.querySelectorAll(`.wishlist-btn[data-id="${productId}"]`);
			const formData = new FormData(); 
			formData.append('product_id', productId);
			
			fetch('/wishlist_action.php', { method: 'POST', body: formData })
			.then(res => res.json())
			.then(data => {
				if (data.status === 'success') {
					const isAdded = (data.action === 'added');
					allBtns.forEach(b => {
						b.classList.toggle('active', isAdded);
						b.querySelector('i').className = isAdded ? 'fas fa-heart' : 'far fa-heart';
					});
					
					Swal.fire({ 
						toast: true, position: 'top', showConfirmButton: false, timer: 1000, 
						background: 'var(--card-bg)', color: 'var(--text-color)', 
						icon: isAdded ? 'success' : 'info', 
						title: isAdded ? msgAdded : msgRemoved 
					});
				}
			});
		});
	});
}

let imageIntervals = {};
let imageTimeouts = {};

function startImageSlider(card, imagesArray) {
	if (!imagesArray || imagesArray.length <= 1) return;
	
	const mainImg = card.querySelector('.product-main-img');
	const bgImg = card.querySelector('.product-bg-img');
	if(!bgImg) return;
	
	const cardId = card.querySelector('.wishlist-btn') ? card.querySelector('.wishlist-btn').getAttribute('data-id') : '0';
	let currentIndex = 0;

	if (imageIntervals[cardId]) clearInterval(imageIntervals[cardId]);
	if (imageTimeouts[cardId]) clearTimeout(imageTimeouts[cardId]);
	if (imageTimeouts[cardId + '_init']) clearTimeout(imageTimeouts[cardId + '_init']);

	const transitionImage = () => {
		currentIndex = (currentIndex + 1) % imagesArray.length;
		bgImg.src = imagesArray[currentIndex];
		bgImg.style.opacity = "1"; 
		mainImg.style.opacity = "0";
		
		imageTimeouts[cardId] = setTimeout(() => {
			mainImg.src = imagesArray[currentIndex];
			mainImg.style.opacity = "1"; 
			bgImg.style.opacity = "0";
		}, 300);
	};

	imageTimeouts[cardId + '_init'] = setTimeout(transitionImage, 50);
	imageIntervals[cardId] = setInterval(transitionImage, 2000);
}

function stopImageSlider(card, originalImage) {
	const cardId = card.querySelector('.wishlist-btn') ? card.querySelector('.wishlist-btn').getAttribute('data-id') : '0';
	
	clearInterval(imageIntervals[cardId]); 
	clearTimeout(imageTimeouts[cardId]);
	clearTimeout(imageTimeouts[cardId + '_init']);

	const mainImg = card.querySelector('.product-main-img');
	const bgImg = card.querySelector('.product-bg-img');
	
	if(!bgImg) return;
	
	mainImg.style.transition = "none"; 
	bgImg.style.transition = "none";
	
	mainImg.src = originalImage; 
	mainImg.style.opacity = "1"; 
	bgImg.style.opacity = "0";
	
	setTimeout(() => { 
		mainImg.style.transition = "opacity 0.6s ease-in-out"; 
		bgImg.style.transition = "opacity 0.6s ease-in-out"; 
	}, 50);
}

document.addEventListener('DOMContentLoaded', () => { 
	startCountdowns(); 
	initWishlist(); 
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>