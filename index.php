<?php
if (session_status() === PHP_SESSION_NONE) { 
	session_start(); 
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

date_default_timezone_set('Asia/Amman'); 

$site_name = $store_settings['site_name'] ?? '';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$site_currency = $store_settings['currency'] ?? '';
$store_status = $store_settings['status'] ?? 1;
$low_stock_limit = $store_settings['low_stock_threshold'] ?? 5;
$hide_out = $store_settings['hide_out_of_stock'] ?? 0;
$show_latest = $store_settings['show_latest_products'] ?? 1;
$show_discounts = $store_settings['show_discounts'] ?? 1;
$show_best_sellers = $store_settings['show_best_sellers'] ?? 1;
$enable_image_fade = $store_settings['enable_image_fade'] ?? 1;
$show_wishlist = $store_settings['show_wishlist'] ?? 1;

if ($store_status == 0) {
	die("<div class='site-closed-msg'>
			<i class='fas fa-tools site-closed-icon'></i>
			<h2>" . ($lang['site_closed'] ?? '') . "</h2>
		 </div>");
}

$stock_condition = "";
if ($hide_out == 1) {
	$stock_condition = " HAVING total_stock > 0 ";
}

$user_wishlist_ids = [];
if (isset($_SESSION['user_id']) && isset($conn)) {
	$uid = intval($_SESSION['user_id']);
	$stmt_w = $conn->prepare("SELECT product_id FROM wishlist WHERE user_id = ?");
	$stmt_w->bind_param("i", $uid);
	$stmt_w->execute();
	$w_res = $stmt_w->get_result();
	while ($w_row = $w_res->fetch_assoc()) {
		$user_wishlist_ids[] = $w_row['product_id'];
	}
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
	$heart_class = $is_wished ? 'fas fa-heart' : 'far fa-heart';
	$btn_active = $is_wished ? 'active' : '';

	$product_id = intval($row['id']);
	$gallery_images = ["/assets/images/" . $row['image']];
	
	if(isset($conn)){
		$g_stmt = $conn->prepare("SELECT image FROM product_color_images WHERE product_id = ? LIMIT 5");
		$g_stmt->bind_param("i", $product_id);
		$g_stmt->execute();
		$g_res = $g_stmt->get_result();
		while($g_row = $g_res->fetch_assoc()) {
			$gallery_images[] = "/assets/images/" . $g_row['image'];
		}
	}
	$gallery_images = array_values(array_unique($gallery_images));
	$json_images = htmlspecialchars(json_encode($gallery_images), ENT_QUOTES, 'UTF-8');
	
	$product_name = $row['name'];

	$hover_events = "";
	if ($enable_image_fade == 1) {
		$hover_events = "onmouseenter='startImageSlider(this, {$json_images})' onmouseleave='stopImageSlider(this, \"/assets/images/" . htmlspecialchars($row['image']) . "\")' ontouchstart='startImageSlider(this, {$json_images})' ontouchend='stopImageSlider(this, \"/assets/images/" . htmlspecialchars($row['image']) . "\")'";
	}

	ob_start(); ?>
	
	<div class="product-card <?php echo $is_sold_out ? 'is-sold-out' : ''; ?>" onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'" <?php echo $hover_events; ?>>
		
		<div class="img-holder">
			<?php if($show_wishlist == 1): ?>
			<button class="wishlist-btn <?php echo $btn_active; ?>" data-id="<?php echo htmlspecialchars($row['id']); ?>" onclick="event.stopPropagation();">
				<i class="<?php echo $heart_class; ?>"></i>
			</button>
			<?php endif; ?>
			
			<img src="/assets/images/<?php echo htmlspecialchars($row['image']); ?>" loading="lazy" class="product-main-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="<?php echo htmlspecialchars($product_name); ?>">
			<?php if ($enable_image_fade == 1): ?>
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

<div class="full-red-bar">
	<div class="container nav-scroll-container">
	
		<?php if ($show_best_sellers == 1): ?>
		<a href="/products.php?sort=best" class="nav-button">
			<div class="icon-circle"><i class="fa-solid fa-circle-dollar-to-slot"></i></div>
			<span><?php echo $lang['best_sellers'] ?? ''; ?></span>
		</a>
		<?php endif; ?>
		
		<?php if ($show_latest == 1): ?>
		<a href="/products.php?sort=new" class="nav-button">
			<div class="icon-circle"><i class="fas fa-magic"></i></div>
			<span><?php echo $lang['latest_products'] ?? ''; ?></span>
		</a>
		<?php endif; ?>
		
		<?php if ($show_discounts == 1): ?>
		<a href="/products.php?sort=sale" class="nav-button">
			<div class="icon-circle"><i class="fas fa-percentage"></i></div>
			<span><?php echo $lang['top_deals'] ?? ''; ?></span>
		</a>
		<?php endif; ?>
		
		<?php 
		if(isset($conn)) {
			$nav_cats = $conn->query("SELECT * FROM categories LIMIT 10");
			if($nav_cats) {
				while($n_cat = $nav_cats->fetch_assoc()): 
					$cat_icon = !empty($n_cat['icon']) ? $n_cat['icon'] : 'fas fa-tshirt';
					$cat_name = $n_cat['name'];
		?>
		<a href="/products.php?category_id=<?php echo htmlspecialchars($n_cat['id']); ?>" class="nav-button">
			<div class="icon-circle"><i class="<?php echo htmlspecialchars($cat_icon); ?>"></i></div>
			<span><?php echo htmlspecialchars($cat_name); ?></span>
		</a>
		<?php 
				endwhile;
			}
		} 
		?>
	</div>
</div>

<main class="container">
	<?php 
	$base_query = "
		SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock 
		FROM products p 
		LEFT JOIN product_variants v ON p.id = v.product_id 
		GROUP BY p.id 
	";
	?>

	<?php if ($show_best_sellers == 1 && isset($conn)): ?>
		<section class="home-section">
			<div class="section-header">
				<h3><?php echo $lang['best_sellers'] ?? ''; ?></h3>
				<a href="/products.php?sort=best" class="view-more-link"><?php echo $lang['view_all'] ?? ''; ?></a>
			</div>
			<div class="products-grid">
				<?php 
				$res_best = $conn->query("
					SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock,
					(SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE product_id = p.id) as sales_count
					FROM products p 
					LEFT JOIN product_variants v ON p.id = v.product_id 
					GROUP BY p.id 
					$stock_condition 
					ORDER BY sales_count DESC, p.id DESC LIMIT 10
				");
				if($res_best) {
					while($row = $res_best->fetch_assoc()) echo renderProductCard($row, $site_currency, $low_stock_limit);
				}
				?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($show_latest == 1 && isset($conn)): ?>
		<section class="home-section">
			<div class="section-header">
				<h3><?php echo $lang['latest_products'] ?? ''; ?></h3>
				<a href="/products.php?sort=new" class="view-more-link"><?php echo $lang['view_all'] ?? ''; ?></a>
			</div>
			<div class="products-grid">
				<?php 
				$res_latest = $conn->query("$base_query $stock_condition ORDER BY p.id DESC LIMIT 10");
				if($res_latest) {
					while($row = $res_latest->fetch_assoc()) echo renderProductCard($row, $site_currency, $low_stock_limit);
				}
				?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($show_discounts == 1 && isset($conn)): ?>
	<section class="home-section">
		<div class="section-header">
			<h3><?php echo $lang['top_deals'] ?? ''; ?></h3>
			<a href="/products.php?sort=sale" class="view-more-link"><?php echo $lang['view_all'] ?? ''; ?></a>
		</div>
		<div class="products-grid">
			<?php 
			$res_sale = $conn->query("
				SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock 
				FROM products p 
				LEFT JOIN product_variants v ON p.id = v.product_id 
				WHERE p.price < p.old_price 
				GROUP BY p.id 
				$stock_condition 
				ORDER BY p.id DESC LIMIT 10
			");
			if($res_sale) {
				while($row = $res_sale->fetch_assoc()) echo renderProductCard($row, $site_currency, $low_stock_limit);
			}
			?>
		</div>
	</section>
	<?php endif; ?>

	<?php 
	if(isset($conn)){
		$cats = $conn->query("SELECT * FROM categories ORDER BY id ASC");
		if($cats){
			while($cat = $cats->fetch_assoc()): 
				$cat_id = intval($cat['id']);
				$cat_name = $cat['name'];
				
				$prods = $conn->query("
					SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock 
					FROM products p 
					LEFT JOIN product_variants v ON p.id = v.product_id 
					WHERE p.category_id = $cat_id 
					GROUP BY p.id 
					$stock_condition 
					ORDER BY p.id DESC LIMIT 10
				");
				if($prods && $prods->num_rows > 0):
	?>
	<section class="home-section">
		<div class="section-header">
			<h3><?php echo htmlspecialchars($cat_name); ?></h3>
			<a href="/products.php?category_id=<?php echo $cat_id; ?>" class="view-more-link"><?php echo $lang['view_all'] ?? ''; ?></a>
		</div>
		<div class="products-grid">
			<?php while($p = $prods->fetch_assoc()) echo renderProductCard($p, $site_currency, $low_stock_limit); ?>
		</div>
	</section>
	<?php 
				endif; 
			endwhile; 
		}
	}
	?>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
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
			display.innerHTML = daysStr + (h < 10 ? '0' + h : h) + ":" + (m < 10 ? '0' + m : m) + ":" + (s < 10 ? '0' + s : s);
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
			const allBtnsForProduct = document.querySelectorAll(`.wishlist-btn[data-id="${productId}"]`);
			
			allBtnsForProduct.forEach(b => b.style.transform = "scale(0.8)");
			
			const formData = new FormData(); 
			formData.append('product_id', productId);
			
			fetch('/wishlist_action.php', { method: 'POST', body: formData })
			.then(res => res.json())
			.then(data => {
				allBtnsForProduct.forEach(b => b.style.transform = "scale(1)"); 
				
				if (data.status === 'success') {
					if (data.action === 'added') { 
						allBtnsForProduct.forEach(b => {
							b.classList.add('active'); 
							b.querySelector('i').className = 'fas fa-heart'; 
						});
						
						Swal.fire({
							toast: true, position: 'top', showConfirmButton: false, timer: 1000,
							background: 'var(--card-bg)', color: 'var(--text-color)', icon: 'success', title: msgAdded
						});

					} else { 
						allBtnsForProduct.forEach(b => {
							b.classList.remove('active'); 
							b.querySelector('i').className = 'far fa-heart'; 
						});
						
						Swal.fire({
							toast: true, position: 'top', showConfirmButton: false, timer: 1000,
							background: 'var(--card-bg)', color: 'var(--text-color)', icon: 'info', title: msgRemoved
						});
					}
				} else if (data.status === 'error' && data.message === 'not_logged_in') { 
					window.location.href = '/login.php'; 
				}
			}).catch(err => { 
				console.error('Wishlist Error:', err); 
				allBtnsForProduct.forEach(b => b.style.transform = "scale(1)"); 
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
	if (!bgImg) return; 
	
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
	const mainImg = card.querySelector('.product-main-img');
	const bgImg = card.querySelector('.product-bg-img');
	if (!bgImg) return;
	
	const cardId = card.querySelector('.wishlist-btn') ? card.querySelector('.wishlist-btn').getAttribute('data-id') : '0';
	
	clearInterval(imageIntervals[cardId]);
	clearTimeout(imageTimeouts[cardId]);
	clearTimeout(imageTimeouts[cardId + '_init']);

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