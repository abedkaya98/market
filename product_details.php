<?php
if (session_status() === PHP_SESSION_NONE) { 
	session_start(); 
}
require_once __DIR__ . '/includes/init_lang.php';
require_once __DIR__ . '/includes/db_connection.php';
date_default_timezone_set('Asia/Amman'); 

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$site_currency = $store_settings['currency'] ?? '';
$site_name = $store_settings['site_name'] ?? '';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$low_stock_limit = $store_settings['low_stock_threshold'] ?? 5;
$enable_image_fade = $store_settings['enable_image_fade'] ?? 1;
$theme_folder = $store_settings['theme_folder'] ?? 'default';
$show_wishlist = $store_settings['show_wishlist'] ?? 1;
$show_videos = $store_settings['show_product_videos'] ?? 1;
$show_reviews = $store_settings['show_reviews'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_review']) && $show_reviews == 1) {
	$r_pid = intval($_POST['product_id']);
	$r_uid = $_SESSION['user_id'] ?? NULL;

	if ($r_uid) {
		$check_review = $conn->prepare("SELECT id FROM reviews WHERE product_id = ? AND user_id = ?");
		$check_review->bind_param("ii", $r_pid, $r_uid);
		$check_review->execute();
		$res = $check_review->get_result();

		if ($res->num_rows > 0) {
			header("Location: /product_details.php?id=$r_pid&review_msg=already_exists");
			exit();
		}
	} else {
		if (isset($_COOKIE['reviewed_' . $r_pid])) {
			header("Location: /product_details.php?id=$r_pid&review_msg=already_exists");
			exit();
		}
	}

	$r_rating = intval($_POST['rating']);
	$r_comment = mysqli_real_escape_string($conn, $_POST['comment']);
	
	$r_name = '';
	if ($r_uid) {
		$u_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
		$u_stmt->bind_param("i", $r_uid);
		$u_stmt->execute();
		$u_res = $u_stmt->get_result();
		if ($u_res->num_rows > 0) {
			$r_name = $u_res->fetch_assoc()['full_name'];
		}
	} else {
		$r_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
	}
	
	$stmt_rev = $conn->prepare("INSERT INTO reviews (product_id, user_id, customer_name, rating, comment, status) VALUES (?, ?, ?, ?, ?, 1)");
	$stmt_rev->bind_param("iisis", $r_pid, $r_uid, $r_name, $r_rating, $r_comment);	
	
	if ($stmt_rev->execute()) {
		if (!$r_uid) {
			setcookie('reviewed_' . $r_pid, '1', time() + (86400 * 365), "/");
		}
		header("Location: /product_details.php?id=$r_pid&review_msg=success");
		exit();
	} else {
		header("Location: /product_details.php?id=$r_pid&review_msg=error");
		exit();
	}
}

$review_msg = $_GET['review_msg'] ?? "";

$stmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) { 
	header("Location: /index.php"); 
	exit(); 
}

$is_simple = (isset($product['product_type']) && $product['product_type'] === 'simple');

$variants_query = $conn->query("SELECT * FROM product_variants WHERE product_id = $id");
$variants = [];
$unique_colors = [];
$unique_sizes = [];

$total_stock = $is_simple ? intval($product['stock']) : 0;

if (!$is_simple && $variants_query && $variants_query->num_rows > 0) {
	while($v = $variants_query->fetch_assoc()) {
		$variants[] = $v;
		$total_stock += $v['stock'];
		
		$color_name = trim($v['color_name'] ?? '');
		if (!empty($color_name) && !in_array($color_name, $unique_colors)) {
			$unique_colors[] = $color_name;
		}
		
		$size_name = trim($v['size_value'] ?? '');
		if (!empty($size_name) && !in_array($size_name, $unique_sizes)) {
			$unique_sizes[] = $size_name;
		}
	}
}

$all_images = [];
$color_galleries = [];

if (!empty($product['image'])) {
	$all_images[] = trim($product['image']);
}

$gallery_q = $conn->query("SELECT color_name, image FROM product_color_images WHERE product_id = $id");
if ($gallery_q) {
	while ($g = $gallery_q->fetch_assoc()) {
		$c = trim($g['color_name']);
		$img = trim($g['image']);
		if (!empty($img)) {
			if (!isset($color_galleries[$c])) {
				$color_galleries[$c] = [];
			}
			$color_galleries[$c][] = $img;
			
			if (!in_array($img, $all_images)) {
				$all_images[] = $img;
			}
		}
	}
}
$gallery_array = array_values($all_images);

$approved_reviews = [];
$total_rating_stars = 0;
$reviews_count = 0;
$avg_rating = 0;
$has_reviewed = false;

if ($show_reviews == 1) {
	$reviews_query = $conn->query("
		SELECT r.*, u.profile_pic 
		FROM reviews r 
		LEFT JOIN users u ON r.user_id = u.id 
		WHERE r.product_id = $id AND r.status = 1 
		ORDER BY r.created_at DESC
	");

	if ($reviews_query) {
		while ($rev = $reviews_query->fetch_assoc()) {
			$approved_reviews[] = $rev;
			$total_rating_stars += $rev['rating'];
		}
	}
	$reviews_count = count($approved_reviews);
	$avg_rating = $reviews_count > 0 ? round($total_rating_stars / $reviews_count, 1) : 0;

	if (isset($_SESSION['user_id'])) {
		$check_q = $conn->query("SELECT id FROM reviews WHERE product_id = $id AND user_id = ".$_SESSION['user_id']);
		if ($check_q && $check_q->num_rows > 0) $has_reviewed = true;
	} else {
		if (isset($_COOKIE['reviewed_' . $id])) {
			$has_reviewed = true;
		}
	}
}

$product_videos = [];
if ($show_videos == 1 && isset($conn)) {
	$v_stmt = $conn->prepare("SELECT title, description, video_url FROM product_videos WHERE product_id = ?");
	$v_stmt->bind_param("i", $id);
	$v_stmt->execute();
	$v_res = $v_stmt->get_result();
	while($v_row = $v_res->fetch_assoc()){
		$vid_url = trim($v_row['video_url']);
		$thumb = "https://placehold.co/600x400/1a1a1a/ffffff?text=Video"; 
		$is_local = false;
		$is_iframe = false; 
		$is_vertical = false; // متغير جديد لاكتشاف الفيديوهات العمودية (Reels)
		
		if (strpos($vid_url, 'youtube.com/watch') !== false) {
			parse_str(parse_url($vid_url, PHP_URL_QUERY), $vars);
			if(isset($vars['v'])) {
				$vid_url = "https://www.youtube.com/embed/" . $vars['v'];
				$thumb = "https://img.youtube.com/vi/" . $vars['v'] . "/mqdefault.jpg";
			}
		} elseif (strpos($vid_url, 'youtu.be/') !== false) {
			$vid_id = basename(parse_url($vid_url, PHP_URL_PATH));
			$vid_url = "https://www.youtube.com/embed/" . $vid_id;
			$thumb = "https://img.youtube.com/vi/" . $vid_id . "/mqdefault.jpg";
		} 
		// معالجة فيسبوك الذكية المحدثة
		elseif (strpos($vid_url, 'facebook.com/') !== false || strpos($vid_url, 'fb.watch/') !== false) {
			// اكتشاف إذا كان الفيديو Reel (عمودي)
			if (strpos($vid_url, '/reel/') !== false) {
				$is_vertical = true;
			}
			$embed_href = urlencode($vid_url);
			$vid_url = "https://www.facebook.com/plugins/video.php?href=" . $embed_href . "&show_text=false&width=" . ($is_vertical ? "350" : "auto");
			// صورة غلاف زرقاء خاصة بفيسبوك
			$thumb = "https://placehold.co/600x400/1877F2/ffffff?text=Facebook+Video"; 
			$is_iframe = true; 
		} 
		elseif (strpos($vid_url, 'http') !== 0) {
			$vid_url = str_replace('videos/', '', $vid_url);
			$vid_url = ltrim($vid_url, '/');
			$vid_url = "/assets/videos/" . $vid_url; 
			$is_local = true;
		}
		
		$v_row['embed_url'] = $vid_url;
		$v_row['thumb_url'] = $thumb;
		$v_row['is_local'] = $is_local;
		$v_row['is_iframe'] = $is_iframe; 
		$v_row['is_vertical'] = $is_vertical; // تمرير حالة الفيديو العمودي
		$product_videos[] = $v_row;
	}
}

$user_wishlist_ids = [];
if (isset($_SESSION['user_id'])) {
	$uid = intval($_SESSION['user_id']);
	$w_query = $conn->query("SELECT product_id FROM wishlist WHERE user_id = $uid");
	if ($w_query) {
		while ($w_row = $w_query->fetch_assoc()) {
			$user_wishlist_ids[] = $w_row['product_id'];
		}
	}
}

if (!function_exists('renderProductCard')) {
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
			$display_price = (!empty($row['old_price']) && $row['old_price'] > 0) ? $row['old_price'] : $row['price'];
			$old_price = 0;
		}
		$discount_percentage = ($old_price > 0) ? round((($old_price - $display_price) / $old_price) * 100) : 0;
		
		$is_wished = in_array($row['id'], $user_wishlist_ids);
		$heart_class = $is_wished ? 'fas fa-heart' : 'far fa-heart';
		$btn_active = $is_wished ? 'active' : '';

		$product_id = intval($row['id']);
		$product_name = $row['name'];
		
		$gallery_images = ["/assets/images/" . $row['image']];
		$gallery_query = $conn->query("SELECT image FROM product_color_images WHERE product_id = $product_id LIMIT 5");
		if ($gallery_query) {
			while($g_row = $gallery_query->fetch_assoc()) {
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
		
		<div class="product-card <?php echo $is_sold_out ? 'is-sold-out' : ''; ?>" onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'" <?php echo $hover_events; ?>>
			
			<div class="img-holder">
				<?php if($show_wishlist == 1): ?>
				<button class="wishlist-btn <?php echo $btn_active; ?>" data-id="<?php echo htmlspecialchars($row['id']); ?>" onclick="event.stopPropagation();">
					<i class="<?php echo $heart_class; ?>"></i>
				</button>
				<?php endif; ?>
				
				<img src="/assets/images/<?php echo htmlspecialchars($row['image']); ?>" loading="lazy" class="product-main-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="<?php echo htmlspecialchars($product_name); ?>">
				<?php if($enable_image_fade == 1): ?>
				<img src="" class="product-bg-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="">
				<?php endif; ?>
				
				<div class="promo-overlay">
					<?php if(!$is_sold_out && $is_discount_valid): ?>
						<div class="unified-discount-box">
							<?php if($discount_percentage > 0): ?> <span><?php echo $lang['discount_text'] ?? ''; ?> <?php echo $discount_percentage; ?>%</span> <?php endif; ?>
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
					<span class="current price-now" data-original="<?php echo (!empty($row['old_price']) && $row['old_price'] > 0) ? $row['old_price'] : $row['price']; ?>">
						<?php echo number_format($display_price, 2) . " " . htmlspecialchars($site_currency); ?>
					</span>
					<?php if($old_price > $display_price): ?>
						<span class="old price-old"><?php echo number_format($old_price, 2) . " " . htmlspecialchars($site_currency); ?></span>
					<?php endif; ?>
				</div>

				<div class="card-footer">
					<button type="button" class="add-to-cart <?php echo $is_sold_out ? 'btn-disabled' : ''; ?>" <?php echo $is_sold_out ? 'disabled' : ''; ?> onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'; event.stopPropagation();">
						<i class="fas <?php echo $is_sold_out ? 'fa-times-circle' : 'fa-sliders-h'; ?>"></i> 
						<?php echo $is_sold_out ? ($lang['product_unavailable'] ?? '') : ($lang['select_options'] ?? ''); ?>
					</button>
				</div>
			</div>
		</div>
		<?php return ob_get_clean();
	}
}

$main_current_time = time();
$main_expiry_timestamp = !empty($product['discount_expiry']) ? strtotime($product['discount_expiry']) : 0;
$main_is_discount_valid = ($main_expiry_timestamp > $main_current_time) || ($main_expiry_timestamp == 0 && !empty($product['old_price']) && $product['old_price'] > $product['price']);

if ($main_is_discount_valid) {
	$main_display_price = $product['price'];
	$main_old_price = $product['old_price'] ?? 0;
} else {
	$main_display_price = (!empty($product['old_price']) && $product['old_price'] > 0) ? $product['old_price'] : $product['price'];
	$main_old_price = 0;
}
$main_original_price_js = (!empty($product['old_price']) && $product['old_price'] > 0) ? $product['old_price'] : $product['price'];

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
	
	<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.css" />
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<div class="product-container">
	<div class="product-layout">
		
		<div class="gallery-section">
			<div class="thumbs-vertical" id="thumbsContainer">
				<?php if (count($gallery_array) > 0): ?>
					<?php foreach($gallery_array as $index => $img): ?>
						<img src="/assets/images/<?php echo htmlspecialchars($img); ?>" class="<?php echo $index === 0 ? 'active' : ''; ?>" onclick="swap(this, <?php echo $index; ?>)">
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			
			<div class="main-image-wrapper">
				
				<?php if($show_wishlist == 1): ?>
				<?php
					$is_wished = in_array($id, $user_wishlist_ids);
					$heart_class = $is_wished ? 'fas fa-heart' : 'far fa-heart';
					$btn_active = $is_wished ? 'active' : '';
				?>
				<button type="button" class="wishlist-btn <?php echo $btn_active; ?>" data-id="<?php echo $id; ?>" title="<?php echo $lang['wishlist'] ?? ''; ?>" id="mainWishBtn">
					<i class="<?php echo $heart_class; ?>"></i>
				</button>
				<?php endif; ?>

				<a href="javascript:void(0);" id="zoomLink">
					<img id="viewPort" src="/assets/images/<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
				</a>
				
				<?php 
				if($main_is_discount_valid): 
					$main_discount_percentage = ($main_old_price > 0) ? round((($main_old_price - $main_display_price) / $main_old_price) * 100) : 0;
					if($main_discount_percentage > 0 || $main_expiry_timestamp > $main_current_time):
				?>
					<div class="product-details-discount-badge timer-part" data-expire="<?php echo ($main_expiry_timestamp > $main_current_time) ? htmlspecialchars($product['discount_expiry']) : ''; ?>">
						
						<?php if($main_discount_percentage > 0): ?>
							<span><?php echo $lang['discount_text'] ?? ''; ?> <?php echo $main_discount_percentage; ?>%</span>
						<?php endif; ?>

						<?php if($main_discount_percentage > 0 && $main_expiry_timestamp > $main_current_time): ?>
							<span class="discount-divider">|</span>
						<?php endif; ?>

						<?php if($main_expiry_timestamp > $main_current_time): ?>
							<span class="countdown countdown-ltr">00:00:00</span>
						<?php endif; ?>

					</div>
				<?php 
					endif; 
				endif; 
				?>
			</div>
		</div>

		<div class="details-section">
			<span class="p-category"><?php echo htmlspecialchars($product['category_name']); ?></span>
			<h1 class="p-title"><?php echo htmlspecialchars($product['name']); ?></h1>
			
			<?php if ($show_reviews == 1): ?>
			<div class="top-rating" onclick="scrollToReviews()">
				<div class="stars">
					<?php 
					for($i=1; $i<=5; $i++) {
						if($i <= floor($avg_rating)) echo '<i class="fas fa-star"></i>';
						elseif($i == ceil($avg_rating) && strpos((string)$avg_rating, '.') !== false) echo '<i class="fas fa-star-half-alt"></i>';
						else echo '<i class="far fa-star"></i>';
					}
					?>
				</div>
				<span>(<?php echo $avg_rating; ?>) <?php echo $lang['product_rating'] ?? ''; ?></span>
			</div>
			<?php endif; ?>

			<div class="p-price-box" id="price_display">
				<?php if($main_old_price > $main_display_price): ?>
					<span class="old"><?php echo htmlspecialchars($main_old_price)." ".htmlspecialchars($site_currency); ?></span>
				<?php endif; ?>
				<span class="price-now-main" data-original="<?php echo $main_original_price_js; ?>"><?php echo htmlspecialchars($main_display_price)." ".htmlspecialchars($site_currency); ?></span>
			</div>

			<form id="add-to-cart-form" action="/cart_action.php" method="POST">
				<input type="hidden" name="product_id" value="<?php echo $id; ?>">
				<input type="hidden" name="add_to_cart" value="1">
				<input type="hidden" name="variant_id" id="variant_id_input">
				<input type="hidden" name="color" id="color_input">
				<input type="hidden" name="size" id="size_input">

				<?php if(!$is_simple && !empty($unique_colors)): ?>
					<div class="attr-group">
						<span class="attr-label"><?php echo $lang['choose_color'] ?? ''; ?></span>
						<div class="attr-swatches" id="color_swatches">
							<?php foreach($unique_colors as $c): ?>
								<div class="swatch" data-color="<?php echo htmlspecialchars($c); ?>" onclick="selectColor(this)"><?php echo htmlspecialchars($c); ?></div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php if(!$is_simple && !empty($unique_sizes)): ?>
					<div class="attr-group" id="size_group">
						<span class="attr-label"><?php echo $lang['choose_size'] ?? ''; ?></span>
						<div class="attr-swatches" id="size_swatches"></div>
					</div>
				<?php endif; ?>

				<div id="stock_status" class="stock-info warning-stock">
					<i class="fas fa-spinner fa-spin"></i> <?php echo $lang['initializing'] ?? ''; ?>
				</div>

				<div class="p-actions">
					<div class="qty-ctrl">
						<button type="button" onclick="adj(-1)"><i class="fas fa-minus"></i></button>
						<input type="number" name="quantity" id="p_qty" value="1" readonly>
						<button type="button" onclick="adj(1)"><i class="fas fa-plus"></i></button>
					</div>
					<button type="submit" id="submit_btn" class="btn-cart" disabled>
						<i class="fas fa-shopping-bag"></i> <span id="cart_btn_text"><?php echo $lang['add_to_cart'] ?? ''; ?></span>
					</button>
					
					<button type="button" class="btn-share" onclick="copyProductLink()" title="<?php echo $lang['share_product'] ?? ''; ?>">
						<i class="fas fa-share-alt"></i>
					</button>
				</div>
			</form>
		</div>
	</div>

	<div class="modern-tabs" id="tabs-section">
		<div class="tabs-header">
			<button class="tab-btn active" onclick="switchTab(event, 'desc-tab')"><?php echo $lang['description'] ?? ''; ?></button>
			<button class="tab-btn" onclick="switchTab(event, 'info-tab')"><?php echo $lang['additional_info'] ?? ''; ?></button>
			<?php if($show_videos == 1 && count($product_videos) > 0): ?>
			<button class="tab-btn" onclick="switchTab(event, 'video-tab')"><?php echo $lang['product_video'] ?? ''; ?></button>
			<?php endif; ?>
			<?php if($show_reviews == 1): ?>
			<button class="tab-btn" id="btn-reviews-tab" onclick="switchTab(event, 'reviews-tab')"><?php echo $lang['reviews'] ?? ''; ?> (<?php echo $reviews_count; ?>)</button>
			<?php endif; ?>
		</div>
		
		<div id="desc-tab" class="tab-panel active">
			<?php echo nl2br(htmlspecialchars($product['description'])); ?>
		</div>
		
		<div id="info-tab" class="tab-panel">
			<table class="info-table">
				<?php if(!empty($unique_colors)): ?>
				<tr>
					<th><?php echo $lang['available_colors'] ?? ''; ?></th>
					<td><?php echo implode('، ', array_map('htmlspecialchars', $unique_colors)); ?></td>
				</tr>
				<?php endif; ?>
				
				<?php if(!empty($unique_sizes)): ?>
				<tr>
					<th><?php echo $lang['available_sizes'] ?? ''; ?></th>
					<td class="countdown-ltr"><?php echo implode(', ', array_map('htmlspecialchars', $unique_sizes)); ?></td>
				</tr>
				<?php endif; ?>
				
				<?php if(!empty($product['extra_info'])): ?>
				<tr>
					<th><?php echo $lang['other_info'] ?? ''; ?></th>
					<td><?php echo nl2br(htmlspecialchars($product['extra_info'])); ?></td>
				</tr>
				<?php endif; ?>
			</table>
		</div>

<?php if($show_videos == 1 && count($product_videos) > 0): ?>
		<div id="video-tab" class="tab-panel">
			<div class="product-videos-grid">
				<?php foreach($product_videos as $index => $vid): ?>
					<div class="video-card">
						<div class="video-body">
							<h3 class="video-card-title"><?php echo htmlspecialchars($vid['title']); ?></h3>
							<?php if(!empty($vid['description'])): ?>
								<p class="video-card-desc"><?php echo nl2br(htmlspecialchars($vid['description'])); ?></p>
							<?php endif; ?>
						</div>
						
						<?php 
						// إعدادات Fancybox مخصصة للفيديوهات العمودية والـ iframes
						$fancybox_opts = "";
						if($vid['is_iframe']) {
							if($vid['is_vertical']) {
								// أبعاد هواتف للفيديوهات العمودية وإخفاء الخلفية البيضاء
								$fancybox_opts = 'data-options=\'{"iframe" : {"css" : {"width" : "350px", "height" : "90vh", "max-width": "100%", "background": "#000"}, "attr": {"scrolling": "no", "allowtransparency": "true", "frameborder": "0"}}}\'';
							} else {
								// إعدادات افتراضية للفيس بوك العرضي
								$fancybox_opts = 'data-options=\'{"iframe" : {"attr": {"scrolling": "no", "allowtransparency": "true", "frameborder": "0"}}}\'';
							}
						}
						?>
						
						<a class="video-thumb-wrapper" data-fancybox="video-single-<?php echo $index; ?>" href="<?php echo htmlspecialchars($vid['embed_url']); ?>" 
							<?php 
							if($vid['is_local']) echo 'data-type="video"'; 
							elseif($vid['is_iframe']) echo 'data-type="iframe"'; 
							?>
							<?php echo $fancybox_opts; ?>
							>
							
							<?php if($vid['is_local']): ?>
								<video src="<?php echo htmlspecialchars($vid['embed_url']); ?>#t=0.1" class="video-thumb-img" preload="metadata" muted playsinline></video>
							<?php else: ?>
								<img src="<?php echo htmlspecialchars($vid['thumb_url']); ?>" class="video-thumb-img" alt="<?php echo htmlspecialchars($vid['title']); ?>">
							<?php endif; ?>
							<div class="play-overlay"><i class="fas fa-play"></i></div>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if($show_reviews == 1): ?>
		<div id="reviews-tab" class="tab-panel">
			<?php if($reviews_count > 0): ?>
				<div class="reviews-list">
				<?php foreach($approved_reviews as $r): 
					$avatar = '/assets/profiles/default-avatar.png';
					if(!empty($r['profile_pic'])) {
						$avatar = (strpos($r['profile_pic'], 'http') === 0) ? $r['profile_pic'] : '/' . $r['profile_pic'];
					}
				?>
						<div class="review-card">
							<div class="rev-head">
								<div class="rev-author-info">
									<img src="<?php echo htmlspecialchars($avatar); ?>" class="rev-avatar">
									<span class="rev-name"><?php echo htmlspecialchars($r['customer_name']); ?></span>
								</div>
								<span class="rev-date"><?php echo date('Y-m-d', strtotime($r['created_at'])); ?></span>
							</div>
							<div class="rev-stars">
								<?php 
								for($i=1; $i<=5; $i++) {
									echo ($i <= $r['rating']) ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
								}
								?>
							</div>
							<div class="rev-comment"><?php echo nl2br(htmlspecialchars($r['comment'])); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<div class="empty-reviews-msg">
					<i class="far fa-comment-dots"></i>
					<p><?php echo $lang['no_reviews'] ?? ''; ?></p>
				</div>
			<?php endif; ?>

			<?php if (!$has_reviewed): ?>
				<div class="review-form-container">
					<h3><?php echo $lang['add_your_review'] ?? ''; ?></h3>
					<form action="/product_details.php?id=<?php echo $id; ?>" method="POST">
						<input type="hidden" name="product_id" value="<?php echo $id; ?>">
						
						<div class="form-group">
							<label><?php echo $lang['your_rating'] ?? ''; ?></label>
							<div class="interactive-stars">
								<input type="radio" id="star5" name="rating" value="5" required />
								<label for="star5" title="5"><i class="fas fa-star"></i></label>
								<input type="radio" id="star4" name="rating" value="4" />
								<label for="star4" title="4"><i class="fas fa-star"></i></label>
								<input type="radio" id="star3" name="rating" value="3" />
								<label for="star3" title="3"><i class="fas fa-star"></i></label>
								<input type="radio" id="star2" name="rating" value="2" />
								<label for="star2" title="2"><i class="fas fa-star"></i></label>
								<input type="radio" id="star1" name="rating" value="1" />
								<label for="star1" title="1"><i class="fas fa-star"></i></label>
							</div>
						</div>

						<div class="form-group">
							<label><?php echo $lang['your_name'] ?? ''; ?></label>
							<?php if(isset($_SESSION['user_id'])): ?>
								<?php
								$display_name = '';
								$u_stmt_disp = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
								$u_stmt_disp->bind_param("i", $_SESSION['user_id']);
								$u_stmt_disp->execute();
								$u_res_disp = $u_stmt_disp->get_result();
								if ($u_res_disp->num_rows > 0) {
									$display_name = $u_res_disp->fetch_assoc()['full_name'];
								}
								?>
								<input type="text" value="<?php echo htmlspecialchars($display_name); ?>" readonly class="form-readonly-input">
								<small><?php echo $lang['review_as_user'] ?? ''; ?></small>
							<?php else: ?>
								<input type="text" name="customer_name" required placeholder="<?php echo $lang['name_placeholder'] ?? ''; ?>">
							<?php endif; ?>
						</div>

						<div class="form-group">
							<label><?php echo $lang['your_review_optional'] ?? ''; ?></label>
							<textarea name="comment" rows="4" placeholder="<?php echo $lang['review_placeholder'] ?? ''; ?>"></textarea>
						</div>

						<button type="submit" name="submit_review" class="btn-submit-rev"><i class="fas fa-paper-plane"></i> <?php echo $lang['submit_review'] ?? ''; ?></button>
					</form>
				</div>
			<?php else: ?>
				<div class="success-review-msg">
					<p class="success-review-text"><i class="fas fa-check-circle"></i> <?php echo $lang['already_reviewed_alert'] ?? ''; ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

	<div class="related-products">
		<h3 class="section-title"><?php echo $lang['related_products'] ?? ''; ?></h3>
		<div class="products-grid related-grid">
			<?php 
			$cat_id = intval($product['category_id']);
			
			$related_query = $conn->query("
				SELECT * FROM (
					(SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock 
					FROM products p LEFT JOIN product_variants v ON p.id = v.product_id 
					WHERE p.category_id = $cat_id AND p.id != $id 
					GROUP BY p.id HAVING total_stock > 0 LIMIT 10)
					UNION
					(SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock 
					FROM products p LEFT JOIN product_variants v ON p.id = v.product_id 
					WHERE p.category_id != $cat_id AND p.id != $id 
					GROUP BY p.id HAVING total_stock > 0 LIMIT 10)
				) AS combined ORDER BY RAND() LIMIT 4
			");

			if ($related_query && $related_query->num_rows > 0):
				while($rel = $related_query->fetch_assoc()): 
					echo renderProductCard($rel, $site_currency, $low_stock_limit);
				endwhile;
			else:
				echo "<p class='v-tab-empty'>".($lang['no_related_products'] ?? '')."</p>";
			endif;
			?>
		</div>
	</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
	const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
	const siteCurrency = "<?php echo htmlspecialchars($site_currency); ?>";
	
	const msgAdding = "<?php echo addslashes($lang['adding_to_cart'] ?? ''); ?>";
	const msgAddedSuccess = "<?php echo addslashes($lang['added_successfully'] ?? ''); ?>";
	const msgAddedText = "<?php echo addslashes($lang['added_to_cart_success'] ?? ''); ?>";
	const msgAddError = "<?php echo addslashes($lang['error_adding'] ?? ''); ?>";
	const msgAddToCart = "<?php echo addslashes($lang['add_to_cart'] ?? ''); ?>";
	const msgInStock = "<?php echo addslashes($lang['in_stock'] ?? ''); ?>";
	const msgOutOfStock = "<?php echo addslashes($lang['out_of_stock'] ?? ''); ?>";
	const msgError = "<?php echo addslashes($lang['error'] ?? ''); ?>";

	$('#add-to-cart-form').on('submit', function(e) {
		e.preventDefault(); 
		const btn = $('#submit_btn');
		btn.prop('disabled', true);
		$('#cart_btn_text').text(msgAdding);
		
		$.ajax({
			url: '/cart_action.php',
			type: 'POST',
			data: $(this).serialize(), 
			success: function(response) {
				let res = response;
				if(typeof response === 'string') {
					try {
						res = JSON.parse(response);
					} catch(e) {
						res = { status: 'error', message: response };
					}
				}

				if(res.status === 'success' || (res.message && res.message.includes('بنجاح')) || (typeof response === 'string' && response.includes('بنجاح'))) {
					if(res.cart_count) document.querySelector('.cart-count').innerText = res.cart_count;
					Swal.fire({
						title: msgAddedSuccess, text: msgAddedText,
						icon: 'success', toast: true, position: 'top', showConfirmButton: false, timer: 1000,
						background: 'var(--card-bg)', color: 'var(--text-color)'
					});
				} else {
					Swal.fire({
						title: msgError, text: res.message || msgAddError, 
						icon: 'error', toast: true, position: 'top', showConfirmButton: false, timer: 1000,
						background: 'var(--card-bg)', color: 'var(--text-color)'
					});
				}
				btn.prop('disabled', false);
				$('#cart_btn_text').text(msgAddToCart);
			},
			error: function() {
				Swal.fire({
					title: msgError, text: msgAddError, 
					icon: 'error', toast: true, position: 'top', showConfirmButton: false, timer: 1000,
					background: 'var(--card-bg)', color: 'var(--text-color)'
				});
				btn.prop('disabled', false);
				$('#cart_btn_text').text(msgAddToCart);
			}
		});
	});

	<?php if($review_msg === "success"): ?>
		Swal.fire({
			title: '<?php echo addslashes($lang['thanks'] ?? ''); ?>', 
			text: '<?php echo addslashes($lang['thanks_review'] ?? ''); ?>', 
			icon: 'success', confirmButtonColor: 'var(--primary-color)', showConfirmButton: false, timer: 1000,
			background: 'var(--card-bg)', color: 'var(--text-color)'
		}).then(() => {
			window.history.replaceState({}, document.title, "/product_details.php?id=<?php echo $id; ?>");
			scrollToReviews();
		});
	<?php elseif($review_msg === "already_exists"): ?>
		Swal.fire({
			title: '<?php echo addslashes($lang['alert'] ?? ''); ?>', 
			text: '<?php echo addslashes($lang['already_reviewed'] ?? ''); ?>', 
			icon: 'warning', confirmButtonColor: 'var(--primary-color)', confirmButtonText: '<?php echo addslashes($lang['ok'] ?? ''); ?>',
			background: 'var(--card-bg)', color: 'var(--text-color)'
		}).then(() => {
			window.history.replaceState({}, document.title, "/product_details.php?id=<?php echo $id; ?>");
			scrollToReviews();
		});
	<?php endif; ?>

	const variants = <?php echo json_encode($variants); ?>;
	const globalGalleryImages = <?php echo json_encode($gallery_array); ?>; 
	
	const isSimple = <?php echo $is_simple ? 'true' : 'false'; ?>;
	const mainStock = <?php echo intval($product['stock']); ?>;
	const hasColors = <?php echo (!empty($unique_colors)) ? 'true' : 'false'; ?>;
	const hasSizes = <?php echo (!empty($unique_sizes)) ? 'true' : 'false'; ?>;
	
	let basePrice = <?php echo $main_display_price; ?>;
	let oldPrice = <?php echo $main_old_price; ?>;
	const currency = '<?php echo htmlspecialchars($site_currency); ?>';
	const totalStock = <?php echo $total_stock; ?>;
	
	let maxQty = 0;
	let currentImgIndex = 0;

	$('#zoomLink').on('click', function(e) {
		e.preventDefault();
		let fancyboxItems = globalGalleryImages.map(img => {
			return { src: '/assets/images/' + img, type: 'image' };
		});
		$.fancybox.open(fancyboxItems, {
			loop: true, index: currentImgIndex,
			buttons: ["zoom", "slideShow", "fullScreen", "download", "thumbs", "close"]
		});
	});

	function swap(el, idx) {
		document.getElementById('viewPort').src = el.src;
		document.querySelectorAll('.thumbs-vertical img').forEach(i => i.classList.remove('active'));
		el.classList.add('active');
		currentImgIndex = idx;
	}

	function selectColor(el) {
		document.querySelectorAll('#color_swatches .swatch').forEach(s => s.classList.remove('active'));
		el.classList.add('active');
		const color = el.getAttribute('data-color');
		document.getElementById('color_input').value = color;

		if (hasSizes) {
			renderSizes(color);
		} else {
			const matchedVariant = variants.find(v => (v.color_name || '').trim() === color);
			if (matchedVariant) applyVariant(null, matchedVariant);
		}
	}

	function renderSizes(colorFilter) {
		const sizeContainer = document.getElementById('size_swatches');
		if (!sizeContainer) return;
		sizeContainer.innerHTML = '';
		
		let filteredVariants = variants;
		if (hasColors) {
			filteredVariants = variants.filter(v => (v.color_name || '').trim() === colorFilter);
		}

		filteredVariants.forEach(v => {
			const s = document.createElement('div');
			s.className = 'swatch';
			if(v.stock <= 0) s.classList.add('disabled');
			s.innerText = v.size_value || '<?php echo addslashes($lang['unified_size'] ?? ''); ?>';
			s.onclick = () => applyVariant(s, v);
			sizeContainer.appendChild(s);
		});
		
		const firstAvailableSize = sizeContainer.querySelector('.swatch:not(.disabled)');
		if(firstAvailableSize) firstAvailableSize.click();
	}

	function applyVariant(el, v) {
		if(el && el.classList.contains('disabled')) return;
		
		if (el) {
			document.querySelectorAll('#size_swatches .swatch').forEach(s => s.classList.remove('active'));
			el.classList.add('active');
			document.getElementById('size_input').value = v.size_value || '';
		}
		
		document.getElementById('variant_id_input').value = v.id;
		maxQty = v.stock;

		if (v.variant_image && v.variant_image.trim() !== '') {
			const imgPath = '/assets/images/' + v.variant_image.trim();
			document.getElementById('viewPort').src = imgPath;
			
			let foundIdx = globalGalleryImages.indexOf(v.variant_image.trim());
			if(foundIdx !== -1) currentImgIndex = foundIdx;
			
			document.querySelectorAll('.thumbs-vertical img').forEach((i) => {
				i.classList.remove('active');
				if (i.src.includes(v.variant_image.trim())) i.classList.add('active');
			});
		}

		const priceBox = document.getElementById('price_display');
		const displayPrice = basePrice;
		let html = '';
		if (oldPrice > displayPrice) html += `<span class="old">${oldPrice} ${currency}</span>`;
		html += `<span class="price-now-main" data-original="<?php echo $main_original_price_js; ?>">${displayPrice} ${currency}</span>`;
		priceBox.innerHTML = html;

		const stockBox = document.getElementById('stock_status');
		const btn = document.getElementById('submit_btn');
		const qtyInput = document.getElementById('p_qty');
		
		if (maxQty > 0) {
			stockBox.className = 'stock-info in-stock';
			stockBox.innerHTML = `<i class="fas fa-check-circle"></i> ` + msgInStock + ` (${maxQty})`;
			btn.disabled = false;
		} else {
			stockBox.className = 'stock-info out-of-stock';
			stockBox.innerHTML = `<i class="fas fa-times-circle"></i> ` + msgOutOfStock;
			btn.disabled = true;
		}
		qtyInput.value = 1;
	}

	function adj(n) {
		let input = document.getElementById('p_qty');
		let v = parseInt(input.value) + n;
		if(v >= 1 && v <= maxQty) input.value = v;
	}

	function switchTab(evt, tabId) {
		document.querySelectorAll('.tab-panel').forEach(c => c.classList.remove('active'));
		document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
		document.getElementById(tabId).classList.add('active');
		if(evt) evt.currentTarget.classList.add('active');
		else {
			const targetBtn = document.getElementById('btn-' + tabId);
			if (targetBtn) targetBtn.classList.add('active');
		}
	}

	function scrollToReviews() {
		const section = document.getElementById('tabs-section');
		if(section) {
			section.scrollIntoView({ behavior: 'smooth' });
			switchTab(null, 'reviews-tab');
		}
	}

	function copyProductLink() {
		const dummy = document.createElement('input');
		document.body.appendChild(dummy);
		dummy.value = window.location.href.split('?')[0] + '?id=<?php echo $id; ?>';
		dummy.select();
		document.execCommand('copy');
		document.body.removeChild(dummy);
		
		Swal.fire({
			title: '<?php echo addslashes($lang['copied_successfully'] ?? ''); ?>', 
			text: '<?php echo addslashes($lang['product_link_copied'] ?? ''); ?>', 
			icon: 'success', toast: true, position: 'top', showConfirmButton: false, timer: 1000,
			background: 'var(--card-bg)', color: 'var(--text-color)'
		});
	}

	function startCountdowns() {
		document.querySelectorAll('.timer-part').forEach(timer => {
			const expiryStr = timer.getAttribute('data-expire');
			if (!expiryStr) return;
			const expiryDate = new Date(expiryStr.replace(/-/g, "/")).getTime();
			const display = timer.querySelector('.countdown');
			const card = timer.closest('.product-card') || timer.closest('.main-image-wrapper');
			
			let priceNowElement = null;
			let priceOldElement = null;
			
			if(card && card.classList.contains('product-card')) {
				priceNowElement = card.querySelector('.price-now');
				priceOldElement = card.querySelector('.price-old');
			} else {
				priceNowElement = document.querySelector('.price-now-main');
				priceOldElement = document.querySelector('.p-price-box .old');
			}

			const update = setInterval(() => {
				const now = new Date().getTime();
				const distance = expiryDate - now;
				
				if (distance <= 0) {
					clearInterval(update);
					if(timer.classList.contains('unified-discount-box') || timer.classList.contains('product-details-discount-badge')) {
						timer.style.display = 'none';
					}
					if (priceNowElement) {
						const originalPrice = parseFloat(priceNowElement.getAttribute('data-original')).toFixed(2);
						priceNowElement.innerHTML = originalPrice + " " + siteCurrency;
						
						if (!card || !card.classList.contains('product-card')) {
							basePrice = parseFloat(originalPrice);
							oldPrice = 0;
						}
					}
					if(priceOldElement) priceOldElement.style.display = 'none';
					return;
				}
				
				const d = Math.floor(distance / (1000 * 60 * 60 * 24));
				const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
				const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
				const s = Math.floor((distance % (1000 * 60)) / 1000);
				
				let daysStr = d > 0 ? d + "d " : "";
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
				const icon = this.querySelector('i');
				const currentBtn = this;
				
				currentBtn.style.transform = "scale(0.8)";
				
				const formData = new FormData(); 
				formData.append('product_id', productId);
				
				fetch('/wishlist_action.php', { method: 'POST', body: formData })
				.then(res => res.json())
				.then(data => {
					currentBtn.style.transform = "scale(1)"; 
					
					if (data.status === 'success') {
						if (data.action === 'added') { 
							currentBtn.classList.add('active'); 
							icon.className = 'fas fa-heart'; 
							Swal.fire({
								toast: true, position: 'top', showConfirmButton: false, timer: 1000,
								background: 'var(--card-bg)', color: 'var(--text-color)', icon: 'success', title: '<?php echo addslashes($lang['added_to_wishlist'] ?? ''); ?>'
							});
						} else { 
							currentBtn.classList.remove('active'); 
							icon.className = 'far fa-heart'; 
							Swal.fire({
								toast: true, position: 'top', showConfirmButton: false, timer: 1000,
								background: 'var(--card-bg)', color: 'var(--text-color)', icon: 'info', title: '<?php echo addslashes($lang['removed_from_wishlist'] ?? ''); ?>'
							});
						}
					} else if (data.status === 'error' && data.message === 'not_logged_in') { 
						window.location.href = '/login.php'; 
					}
				}).catch(err => { 
					console.error('Wishlist Error:', err); 
					currentBtn.style.transform = "scale(1)"; 
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
		const cardId = card.querySelector('.wishlist-btn') ? card.querySelector('.wishlist-btn').getAttribute('data-id') : '0';
		if (!bgImg) return;
		
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
		const mainImg = card.querySelector('.product-main-img');
		const bgImg = card.querySelector('.product-bg-img');
		if (!bgImg) return;

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

window.onload = () => {
		startCountdowns(); 
		initWishlist(); 

		if (isSimple) {
			maxQty = mainStock;
			document.getElementById('variant_id_input').value = '0'; 
			
			const stockBox = document.getElementById('stock_status');
			const btn = document.getElementById('submit_btn');
			const qtyInput = document.getElementById('p_qty');
			
			if (maxQty > 0) {
				stockBox.className = 'stock-info in-stock';
				stockBox.innerHTML = `<i class="fas fa-check-circle"></i> ` + msgInStock + ` (${maxQty})`;
				btn.disabled = false;
			} else {
				stockBox.className = 'stock-info out-of-stock';
				stockBox.innerHTML = `<i class="fas fa-times-circle"></i> ` + msgOutOfStock;
				btn.disabled = true;
			}
			qtyInput.value = 1;
			
		} else {
			if (totalStock <= 0) {
				document.getElementById('stock_status').className = 'stock-info out-of-stock';
				document.getElementById('stock_status').innerHTML = "<?php echo addslashes($lang['sold_out'] ?? ''); ?>";
				document.getElementById('submit_btn').disabled = true;
				return;
			}

			if (hasColors) {
				const firstCol = document.querySelector('#color_swatches .swatch');
				if (firstCol) firstCol.click();
			} else if (hasSizes) {
				renderSizes('');
			} else {
				if(variants.length > 0) applyVariant(null, variants[0]);
			}
		}
	};
</script>

<?php include __DIR__ . '/footer.php'; ?>

</body>
</html>