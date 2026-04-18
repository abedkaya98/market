<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/init_lang.php';

if (isset($_POST['action']) && $_POST['action'] === 'remove' && isset($_POST['product_id'])) {
    if (isset($_SESSION['user_id'])) {
        $uid = intval($_SESSION['user_id']);
        $pid = intval($_POST['product_id']);
        $del_stmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
        $del_stmt->bind_param("ii", $uid, $pid);
        $del_stmt->execute();
        echo "success";
    }
    exit();
}

$site_name = $store_settings['site_name'] ?? '';
$site_currency = $store_settings['currency'] ?? '₪';
$site_icon = $store_settings['site_icon'] ?? 'arabian.png';
$low_stock_limit = intval($store_settings['low_stock_threshold'] ?? 5);
$enable_image_fade = intval($store_settings['enable_image_fade'] ?? 1);
$theme_folder = $store_settings['theme_folder'] ?? 'default';

$is_logged_in = isset($_SESSION['user_id']);
$wishlist_items = false;

if ($is_logged_in) {
    $user_id = intval($_SESSION['user_id']);
    $query = "SELECT p.*, COALESCE(SUM(v.stock), p.stock) as total_stock 
              FROM products p 
              INNER JOIN wishlist w ON p.id = w.product_id 
              LEFT JOIN product_variants v ON p.id = v.product_id
              WHERE w.user_id = $user_id 
              GROUP BY p.id, w.id
              ORDER BY w.id DESC";
    $wishlist_items = $conn->query($query);
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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<main class="container">
    <div class="wishlist-header">
        <i class="fas fa-heart"></i>
        <h2><?php echo htmlspecialchars($lang['wishlist_title'] ?? ''); ?></h2>
        <p><?php echo htmlspecialchars($lang['wishlist_desc'] ?? ''); ?></p>
    </div>

    <div id="wishlist-container" class="products-grid">
        <?php if (!$is_logged_in): ?>
            <div class="empty-state-container">
                <div class="empty-state-icon"><i class="fas fa-user-lock"></i></div>
                <h3><?php echo htmlspecialchars($lang['login_required'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($lang['login_desc'] ?? ''); ?></p>
                <a href="/login.php" class="btn-explore"><?php echo htmlspecialchars($lang['login_btn'] ?? ''); ?></a>
            </div>

        <?php elseif ($wishlist_items && $wishlist_items->num_rows > 0): ?>
            <?php 
            $current_time = time();
            while($row = $wishlist_items->fetch_assoc()): 
                
                $stock = intval($row['total_stock'] ?? 0);
                $is_sold_out = ($stock <= 0);

                $expiry_timestamp = !empty($row['discount_expiry']) ? strtotime($row['discount_expiry']) : 0;
                $is_discount_valid = ($expiry_timestamp > $current_time) || ($expiry_timestamp == 0 && !empty($row['old_price']) && $row['old_price'] > $row['price']);

                if ($is_discount_valid) {
                    $display_price = $row['price'];
                    $old_price = $row['old_price'] ?? 0;
                } else {
                    $display_price = (!empty($row['old_price']) && $row['old_price'] > 0) ? $row['old_price'] : $row['price'];
                    $old_price = 0;
                }
                $discount_percentage = ($old_price > 0) ? round((($old_price - $display_price) / $old_price) * 100) : 0;
                
                $product_id = intval($row['id']);
                $gallery_images = ["/assets/images/" . $row['image']];
                $gallery_query = $conn->prepare("SELECT image FROM product_color_images WHERE product_id = ? LIMIT 5");
                $gallery_query->bind_param("i", $product_id);
                $gallery_query->execute();
                $g_res = $gallery_query->get_result();
                while($g_row = $g_res->fetch_assoc()) {
                    $gallery_images[] = "/assets/images/" . $g_row['image'];
                }
                $gallery_images = array_values(array_unique($gallery_images));
                $json_images = htmlspecialchars(json_encode($gallery_images), ENT_QUOTES, 'UTF-8');
                
                $hover_events = "";
                if ($enable_image_fade == 1) {
                    $hover_events = "onmouseenter='startImageSlider(this, {$json_images})' onmouseleave='stopImageSlider(this, \"/assets/images/" . htmlspecialchars($row['image']) . "\")'";
                }
            ?>
            
            <div class="product-card <?php echo $is_sold_out ? 'is-sold-out' : ''; ?> cursor-pointer" id="product-<?php echo htmlspecialchars($row['id']); ?>" onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'" <?php echo $hover_events; ?>>
                
                <div class="img-holder wishlist-img-holder">
                    <button class="wishlist-btn active" data-id="<?php echo htmlspecialchars($row['id']); ?>" onclick="event.stopPropagation();">
                        <i class="fas fa-heart"></i>
                    </button>
                    
                    <img src="/assets/images/<?php echo htmlspecialchars($row['image']); ?>" loading="lazy" class="product-main-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="<?php echo htmlspecialchars($row['name']); ?>">
                    
                    <?php if($enable_image_fade == 1): ?>
                        <img src="" class="product-bg-img <?php echo $is_sold_out ? 'grayscale-image' : ''; ?>" alt="">
                    <?php endif; ?>

                    <?php if($discount_percentage > 0 || ($is_discount_valid && $expiry_timestamp > $current_time)): ?>
                    <div class="unified-discount-box">
                        <?php if($discount_percentage > 0): ?> <span><?php echo $discount_percentage; ?>% <?php echo htmlspecialchars($lang['discount_text'] ?? ''); ?></span> <?php endif; ?>
                        <?php if($expiry_timestamp > $current_time): ?>
                            <span class="divider">|</span>
                            <div class="timer-part" data-expire="<?php echo htmlspecialchars($row['discount_expiry']); ?>">
                                <i class="far fa-clock"></i> <span class="countdown">00:00:00</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if($is_sold_out): ?>
                        <div class="sold-out-center-badge">
                            <i class="fas fa-ban ms-1"></i> <?php echo htmlspecialchars($lang['sold_out'] ?? ''); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="info">
                    <div class="title-wrapper">
                        <h4><?php echo htmlspecialchars($row['name']); ?></h4>
                        <?php if (!$is_sold_out && $stock > 0 && $stock <= $low_stock_limit): ?>
                            <span class="low-stock-badge-fixed"><?php echo htmlspecialchars($lang['almost_sold_out'] ?? ''); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="price">
                        <span class="current price-now" data-original="<?php echo ($row['old_price'] > 0) ? $row['old_price'] : $row['price']; ?>">
                            <?php echo number_format($display_price, 2) . " " . htmlspecialchars($site_currency); ?>
                        </span>
                        <?php if($old_price > $display_price): ?>
                            <span class="old price-old strike-price-wishlist">
                                <?php echo number_format($old_price, 2) . " " . htmlspecialchars($site_currency); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <button type="button" class="add-to-cart <?php echo $is_sold_out ? 'btn-disabled' : ''; ?>" onclick="window.location.href='/product_details.php?id=<?php echo htmlspecialchars($row['id']); ?>'; event.stopPropagation();" <?php echo $is_sold_out ? 'disabled' : ''; ?>>
                            <i class="fas <?php echo $is_sold_out ? 'fa-times-circle' : 'fa-sliders-h'; ?>"></i> 
                            <?php echo $is_sold_out ? htmlspecialchars($lang['product_unavailable'] ?? '') : htmlspecialchars($lang['select_options'] ?? ''); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>

        <?php else: ?>
            <div class="empty-state-container">
                <div class="empty-state-icon"><i class="far fa-heart"></i></div>
                <h3><?php echo htmlspecialchars($lang['empty_wishlist'] ?? ''); ?></h3>
                <p><?php echo htmlspecialchars($lang['empty_wishlist_desc'] ?? ''); ?></p>
                <a href="/index.php" class="btn-explore"><?php echo htmlspecialchars($lang['start_shopping'] ?? ''); ?></a>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const siteCurrency = "<?php echo htmlspecialchars($site_currency); ?>";

const msgRemoved = "<?php echo addslashes($lang['removed_from_wishlist'] ?? ''); ?>";
const msgEmptyTitle = "<?php echo addslashes($lang['empty_wishlist'] ?? ''); ?>";
const msgEmptyDesc = "<?php echo addslashes($lang['empty_wishlist_desc'] ?? ''); ?>";
const msgStartShopping = "<?php echo addslashes($lang['start_shopping'] ?? ''); ?>";

function startCountdowns() {
    document.querySelectorAll('.timer-part').forEach(timer => {
        const expiryStr = timer.getAttribute('data-expire');
        if (!expiryStr) return;
        const expiryDate = new Date(expiryStr.replace(/-/g, "/")).getTime();
        const display = timer.querySelector('.countdown');
        const card = timer.closest('.product-card');
        const priceNowElement = card ? card.querySelector('.price-now') : null;
        const priceOldElement = card ? card.querySelector('.price-old') : null;

        const update = setInterval(() => {
            const now = new Date().getTime();
            const distance = expiryDate - now;
            if (distance <= 0) {
                clearInterval(update);
                const unifiedBox = timer.closest('.unified-discount-box');
                if(unifiedBox) unifiedBox.style.display = 'none';
                if(priceNowElement){
                    const originalPrice = parseFloat(priceNowElement.getAttribute('data-original')).toFixed(2);
                    priceNowElement.innerHTML = originalPrice + " " + siteCurrency;
                }
                if(priceOldElement) priceOldElement.style.display = 'none';
                return;
            }
            const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const s = Math.floor((distance % (1000 * 60)) / 1000);
            display.innerHTML = (h < 10 ? '0' + h : h) + ":" + (m < 10 ? '0' + m : m) + ":" + (s < 10 ? '0' + s : s);
        }, 1000);
    });
}

let imageIntervals = {};
let imageTimeouts = {};

function startImageSlider(card, imagesArray) {
    if (!imagesArray || imagesArray.length <= 1) return;
    const mainImg = card.querySelector('.product-main-img');
    const bgImg = card.querySelector('.product-bg-img');
    if (!bgImg) return;
    const cardId = card.querySelector('.wishlist-btn').getAttribute('data-id');
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
    const cardId = card.querySelector('.wishlist-btn').getAttribute('data-id');
    const bgImg = card.querySelector('.product-bg-img');
    if(!bgImg) return;
    
    clearInterval(imageIntervals[cardId]);
    clearTimeout(imageTimeouts[cardId]);
    clearTimeout(imageTimeouts[cardId + '_init']);

    const mainImg = card.querySelector('.product-main-img');
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

    document.querySelectorAll('.wishlist-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); 
            
            const productId = this.getAttribute('data-id');
            const card = document.getElementById(`product-${productId}`);
            
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('product_id', productId);

            fetch('/wishlist.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
                    
                    Swal.fire({
                        toast: true,
                        position: 'top',
                        showConfirmButton: false,
                        timer: 1000,
                        background: 'var(--card-bg)',
                        color: 'var(--text-color)',
                        icon: 'info',
                        title: msgRemoved
                    });

                    card.classList.add('removing');
                    setTimeout(() => {
                        card.remove();
                        if (document.querySelectorAll('.product-card').length === 0) {
                            document.getElementById('wishlist-container').innerHTML = `
                                <div class="empty-state-container">
                                    <div class="empty-state-icon"><i class="far fa-heart"></i></div>
                                    <h3>${msgEmptyTitle}</h3>
                                    <p>${msgEmptyDesc}</p>
                                    <a href="/index.php" class="btn-explore">${msgStartShopping}</a>
                                </div>
                            `;
                        }
                    }, 400);
                }
            })
            .catch(error => console.error('Error:', error));
        });
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>