<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db_connection.php'; 
require_once __DIR__ . '/includes/init_lang.php';

$site_currency = $store_settings['currency'] ?? '';
$site_name = $store_settings['site_name'] ?? '';
$site_icon = $store_settings['site_icon'] ?? 'default-icon.png';
$theme_folder = $store_settings['theme_folder'] ?? 'default';

$u_id = $_SESSION['user_id'] ?? null;
$s_id = null;

if (!$u_id) {
    if (isset($_COOKIE['guest_cart_token'])) {
        $s_id = $conn->real_escape_string($_COOKIE['guest_cart_token']);
    } else {
        $s_id = bin2hex(random_bytes(16));
        setcookie('guest_cart_token', $s_id, time() + (86400 * 30), "/");
        $_COOKIE['guest_cart_token'] = $s_id; 
    }
}

if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    if ($u_id) {
        $conn->query("DELETE FROM cart WHERE id = $remove_id AND user_id = $u_id");
    } else {
        $conn->query("DELETE FROM cart WHERE id = $remove_id AND session_id = '$s_id'");
    }
    header("Location: /cart.php?removed=1"); 
    exit();
}

$query_condition = $u_id ? "c.user_id = $u_id" : "c.session_id = '$s_id'";
$cart_query = $conn->query("
    SELECT c.id as cart_id, c.quantity, p.id as product_id, p.name, p.price, p.old_price, p.discount_expiry, p.image, pv.color_name, pv.size_value 
    FROM cart c 
    JOIN products p ON c.product_id = p.id 
    LEFT JOIN product_variants pv ON c.variant_id = pv.id 
    WHERE $query_condition
");

$shipping_areas_query = $conn->query("SELECT * FROM shipping_areas ORDER BY price ASC");

$full_name_val = ""; 
$phone_val = "";
$addresses_query = false;
$phones_query = false;

if ($u_id) {
    $user_q = $conn->query("SELECT full_name, phone FROM users WHERE id = $u_id LIMIT 1");
    if ($user_q && $row = $user_q->fetch_assoc()) {
        $full_name_val = $row['full_name'];
        $phone_val     = $row['phone'];
    }
    $addresses_query = $conn->query("SELECT * FROM user_addresses WHERE user_id = $u_id ORDER BY is_default DESC");
    $phones_query = $conn->query("SELECT * FROM user_phones WHERE user_id = $u_id ORDER BY is_default DESC");
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

<?php if (!$cart_query || $cart_query->num_rows == 0): ?>
    <div class="container">
        <div class="empty-state-container">
            <div class="empty-state-icon"><i class="fas fa-shopping-cart"></i></div>
            <h3><?php echo htmlspecialchars($lang['empty_cart_title'] ?? ''); ?></h3>
            <p><?php echo htmlspecialchars($lang['empty_cart_desc'] ?? ''); ?></p>
            <a href="/index.php" class="btn-explore"><i class="fas fa-store"></i> <?php echo htmlspecialchars($lang['start_shopping'] ?? ''); ?></a>
        </div>
    </div>
<?php else: ?>
    
    <div class="checkout-container">
        <form id="orderForm" action="/process_order.php" method="POST" onsubmit="handleOrderSubmit(event)">            
            <div class="content-card">
                <div class="card-title"><i class="fas fa-box-open"></i> <?php echo htmlspecialchars($lang['order_review'] ?? ''); ?></div>
                <div class="products-list">
                <?php 
                    $sub = 0;
                    $current_time = time();
                    while($item = $cart_query->fetch_assoc()):
                        $expiry_timestamp = !empty($item['discount_expiry']) ? strtotime($item['discount_expiry']) : 0;
                        $is_discount_valid = ($expiry_timestamp > $current_time) || ($expiry_timestamp == 0 && !empty($item['old_price']) && $item['old_price'] > $item['price']);
                        
                        $actual_price = $is_discount_valid ? $item['price'] : ((!empty($item['old_price']) && $item['old_price'] > 0) ? $item['old_price'] : $item['price']);
                        
                        $line = $actual_price * $item['quantity']; 
                        $sub += $line;
                        
                        $v_text = trim(($item['color_name'] ?? '') . " - " . ($item['size_value'] ?? ''));
                        if($v_text == "-") $v_text = ""; 
                    ?>
                    <div class="prod-item">
                        <img src="/assets/images/<?php echo htmlspecialchars($item['image']); ?>" class="prod-img-zoom" alt="Product" onclick="viewCartImage(this.src)">
                        <div class="prod-info">
                            <div>
                                <a href="/product_details.php?id=<?php echo htmlspecialchars($item['product_id']); ?>" class="product-link-clean">
                                    <h4 class="prod-name"><?php echo htmlspecialchars($item['name']); ?></h4>
                                </a>
                                <?php if($v_text): ?><span class="variant-text"><?php echo htmlspecialchars($v_text); ?></span><?php endif; ?>
                            </div>
                            <div class="prod-bottom-row">
                                <div class="prod-price-box">
                                    <div class="qty-ctrl-cart">
                                        <button type="button" onclick="updateCartQty(<?php echo htmlspecialchars($item['cart_id']); ?>, -1)"><i class="fas fa-minus"></i></button>
                                        <input type="number" id="qty_<?php echo htmlspecialchars($item['cart_id']); ?>" value="<?php echo htmlspecialchars($item['quantity']); ?>" readonly>
                                        <button type="button" onclick="updateCartQty(<?php echo htmlspecialchars($item['cart_id']); ?>, 1)"><i class="fas fa-plus"></i></button>
                                    </div>
                                    <span class="prod-price"><?php echo number_format($line, 2) . " " . htmlspecialchars($site_currency); ?></span>
                                </div>
                                <a href="/cart.php?remove=<?php echo htmlspecialchars($item['cart_id']); ?>" class="prod-remove"><i class="fas fa-trash-alt"></i> <?php echo htmlspecialchars($lang['delete'] ?? ''); ?></a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="card-title"><i class="far fa-id-badge"></i> <?php echo htmlspecialchars($lang['delivery_details'] ?? ''); ?></div>
                
                <div class="form-group">
                    <label><?php echo htmlspecialchars($lang['full_name_req'] ?? ''); ?></label>
                    <input type="text" name="full_name" class="input-ctrl" required placeholder="<?php echo htmlspecialchars($lang['full_name_placeholder'] ?? ''); ?>" value="<?php echo htmlspecialchars($full_name_val); ?>">
                </div>

                <?php if($u_id && $addresses_query && $addresses_query->num_rows > 0): ?>
                    <div class="form-group saved-address-box">
                        <label><i class="fas fa-map-marker-alt address-icon"></i> <?php echo htmlspecialchars($lang['select_saved_address'] ?? ''); ?></label>
                        <select id="address_selector" name="saved_address_id" class="input-ctrl address-select" onchange="applySavedAddress(this)">
                            <?php while($addr = $addresses_query->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($addr['id']); ?>" data-city="<?php echo htmlspecialchars($addr['city']); ?>" data-details="<?php echo htmlspecialchars($addr['address_details']); ?>">
                                    <?php echo htmlspecialchars($addr['city'] . ' - ' . $addr['address_details']); ?>
                                    <?php echo $addr['is_default'] ? ' ' . htmlspecialchars($lang['default_txt'] ?? '') : ''; ?>
                                </option>
                            <?php endwhile; ?>
                            <option value="new" class="address-new-opt"><?php echo htmlspecialchars($lang['add_new_address'] ?? ''); ?></option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label><?php echo htmlspecialchars($lang['city_req'] ?? ''); ?></label>
                        <input type="text" id="city_input" name="city" class="input-ctrl" required placeholder="<?php echo htmlspecialchars($lang['city_placeholder'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars($lang['address_req'] ?? ''); ?></label>
                        <input type="text" id="address_input" name="address" class="input-ctrl" required placeholder="<?php echo htmlspecialchars($lang['address_placeholder'] ?? ''); ?>">
                    </div>
                </div>

                <?php if($u_id): ?>
                    <div class="form-group save-address-wrapper <?php echo ($addresses_query && $addresses_query->num_rows > 0) ? 'd-none' : ''; ?>" id="save_address_wrapper">
                        <label class="save-addr-label">
                            <input type="checkbox" name="save_new_address" value="1" class="save-addr-checkbox" checked>
                            <?php echo htmlspecialchars($lang['save_as_default_address'] ?? ''); ?>
                        </label>
                    </div>
                <?php endif; ?>

                <div class="whatsapp-section-divider">
                    <?php if($u_id && $phones_query && $phones_query->num_rows > 0): ?>
                        <div class="form-group saved-address-box">
                            <label><i class="fab fa-whatsapp wa-icon-label"></i> <?php echo htmlspecialchars($lang['use_saved_phone'] ?? ''); ?></label>
                            <select id="saved_phone_selector" name="saved_phone_id" class="input-ctrl address-select" onchange="applySavedPhone(this)">
                                <?php while($p = $phones_query->fetch_assoc()): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        +<?php echo htmlspecialchars($p['country_code'] . ' ' . $p['phone_number']); ?>
                                        <?php echo $p['is_default'] ? ' (' . htmlspecialchars($lang['default_txt'] ?? '') . ')' : ''; ?>
                                    </option>
                                <?php endwhile; ?>
                                <option value="new" class="address-new-opt"><?php echo htmlspecialchars($lang['add_new_phone'] ?? ''); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group <?php echo ($u_id && $phones_query && $phones_query->num_rows > 0) ? 'd-none' : ''; ?>" id="new_phone_group">
                        <label><i class="fab fa-whatsapp wa-icon-label"></i> <?php echo htmlspecialchars($lang['phone_number'] ?? ''); ?></label>
                        <div class="phone-input-wrapper">
                            <select name="country_code" id="country_code" class="input-ctrl country-select">
                                <option value="970">+970 (PS)</option>
                                <option value="972">+972 (IL)</option>
                                <option value="962">+962 (JO)</option>
                            </select>
                            <input type="tel" name="phone" id="phone_input_field" class="input-ctrl phone-input phone-input-ltr" placeholder="59xxxxxxx" value="<?php echo ($u_id && $phones_query && $phones_query->num_rows > 0) ? '' : htmlspecialchars($phone_val); ?>" <?php echo ($u_id && $phones_query && $phones_query->num_rows > 0) ? '' : 'required'; ?>>
                        </div>
                        <?php if($u_id): ?>
                            <div class="save-phone-wrapper">
                                <label class="save-addr-label">
                                    <input type="checkbox" name="save_new_phone" value="1" class="save-addr-checkbox" checked>
                                    <?php echo htmlspecialchars($lang['save_phone_for_future'] ?? ''); ?>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group notes-group">
                    <label><?php echo htmlspecialchars($lang['extra_notes'] ?? ''); ?></label>
                    <textarea name="notes" class="input-ctrl" rows="2" placeholder="<?php echo htmlspecialchars($lang['notes_placeholder'] ?? ''); ?>"></textarea>
                </div>
            </div>

            <div class="content-card">
                <div class="card-title"><i class="fas fa-truck"></i> <?php echo htmlspecialchars($lang['choose_shipping'] ?? ''); ?></div>
                <div class="selection-list">
                    <?php 
                    $first = true; $default_shipping = 0;
                    if($shipping_areas_query):
                        while($area = $shipping_areas_query->fetch_assoc()): 
                            if($first) $default_shipping = $area['price'];
                    ?>
                        <div class="selection-item <?php echo $first ? 'active' : ''; ?>" onclick="updateTotal(<?php echo htmlspecialchars($area['price']); ?>, this)">
                            <label class="radio-label">
                                <input type="radio" name="shipping_area" value="<?php echo htmlspecialchars($area['id']); ?>" <?php echo $first ? 'checked' : ''; ?> required>
                                <span><?php echo htmlspecialchars($area['name']); ?></span>
                            </label>
                            <span class="shipping-price"><?php echo number_format($area['price'], 2) . " " . htmlspecialchars($site_currency); ?></span>
                        </div>
                    <?php $first = false; endwhile; endif; ?>
                </div>
            </div>

            <div class="content-card">
                <div class="card-title"><i class="fas fa-money-bill-wave"></i> <?php echo htmlspecialchars($lang['payment_method'] ?? ''); ?></div>
                <div class="selection-list">
                    <div class="selection-item active" onclick="togglePayment(this)">
                        <label class="radio-label">
                            <input type="radio" name="payment_method" value="cod" checked required>
                            <span><?php echo htmlspecialchars($lang['cod_payment'] ?? ''); ?></span>
                        </label>
                        <i class="fas fa-hand-holding-usd payment-icon"></i>
                    </div>
                </div>
            </div>

            <div class="checkout-summary-card">
                <div class="total-line">
                    <span><?php echo htmlspecialchars($lang['subtotal'] ?? ''); ?></span>
                    <span><?php echo number_format($sub, 2) . " " . htmlspecialchars($site_currency); ?></span>
                </div>
                <div class="total-line">
                    <span><?php echo htmlspecialchars($lang['delivery_fee'] ?? ''); ?></span>
                    <span id="ship_txt"><?php echo number_format($default_shipping, 2) . " " . htmlspecialchars($site_currency); ?></span>
                </div>
                <div class="total-line final">
                    <span><?php echo htmlspecialchars($lang['grand_total'] ?? ''); ?></span>
                    <span id="grand_txt"><?php echo number_format($sub + $default_shipping, 2) . " " . htmlspecialchars($site_currency); ?></span>
                </div>

                <button type="submit" class="btn-order"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($lang['checkout_now'] ?? ''); ?></button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div id="imgModal" class="image-modal-overlay">
    <span class="close-btn">&times;</span>
    <img id="modalImg" src="">
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const subTotal = <?php echo (float)($sub ?? 0); ?>;
    const currency = "<?php echo htmlspecialchars($site_currency); ?>";
    const langItemRemoved = "<?php echo addslashes($lang['item_removed_success'] ?? ''); ?>";
    const langPleaseEnterPhone = "<?php echo addslashes($lang['please_enter_phone'] ?? ''); ?>";
    const langErrorOops = "<?php echo addslashes($lang['error_oops'] ?? ''); ?>";

    function updateCartQty(cartId, change) {
        const input = document.getElementById('qty_' + cartId);
        let newQty = parseInt(input.value) + change;
        if(newQty < 1) { window.location.href = '/cart.php?remove=' + cartId; return; }

        const formData = new FormData();
        formData.append('update_cart_qty', '1');
        formData.append('cart_id', cartId);
        formData.append('new_qty', newQty);

        fetch('/cart_action.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') { window.location.reload(); } 
            else { Swal.fire({ toast: true, position: 'top', icon: 'error', title: data.message, showConfirmButton: false, timer: 1000 }); }
        });
    }

    function applySavedAddress(selectEl) {
        const cityInput = document.getElementById('city_input');
        const addressInput = document.getElementById('address_input');
        const saveWrapper = document.getElementById('save_address_wrapper');
        if (!selectEl) return;
        if (selectEl.value === 'new') {
            cityInput.value = ''; addressInput.value = '';
            cityInput.removeAttribute('readonly'); addressInput.removeAttribute('readonly');
            if(saveWrapper) saveWrapper.classList.remove('d-none');
        } else {
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            cityInput.value = selectedOption.getAttribute('data-city');
            addressInput.value = selectedOption.getAttribute('data-details');
            cityInput.setAttribute('readonly', 'readonly'); addressInput.setAttribute('readonly', 'readonly');
            if(saveWrapper) saveWrapper.classList.add('d-none');
        }
    }

    function applySavedPhone(selectEl) {
        const newPhoneGroup = document.getElementById('new_phone_group');
        const phoneInput = document.getElementById('phone_input_field');
        if (!selectEl) return;
        if (selectEl.value === 'new') {
            newPhoneGroup.classList.remove('d-none');
            phoneInput.setAttribute('required', 'required');
        } else {
            newPhoneGroup.classList.add('d-none');
            phoneInput.removeAttribute('required');
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        const addressSelector = document.getElementById('address_selector');
        if(addressSelector) applySavedAddress(addressSelector);
        
        const phoneSelector = document.getElementById('saved_phone_selector');
        if(phoneSelector) applySavedPhone(phoneSelector);
    });

    function updateTotal(cost, el) {
        cost = parseFloat(cost);
        document.getElementById('ship_txt').innerText = cost.toFixed(2) + " " + currency;
        document.getElementById('grand_txt').innerText = (subTotal + cost).toFixed(2) + " " + currency;
        const parentList = el.closest('.selection-list');
        parentList.querySelectorAll('.selection-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active'); el.querySelector('input').checked = true;
    }

    function togglePayment(el) {
        const parentList = el.closest('.selection-list');
        parentList.querySelectorAll('.selection-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active'); el.querySelector('input').checked = true;
    }

    function viewCartImage(src) {
        document.getElementById('imgModal').style.display = "flex";
        document.getElementById('modalImg').src = src;
        document.body.classList.add('no-scroll'); 
    }

    document.getElementById('imgModal').addEventListener('click', function(e) { 
        if (e.target === this || e.target.classList.contains('close-btn')) {
            this.style.display = 'none'; 
            document.body.classList.remove('no-scroll'); 
        }
    });

    document.addEventListener('keydown', e => { 
        if (e.key === "Escape") {
            document.getElementById('imgModal').style.display = "none";
            document.body.classList.remove('no-scroll'); 
        }
    });

    <?php if(isset($_GET['removed']) && $_GET['removed'] == 1): ?>
        Swal.fire({ toast: true, position: 'top', icon: 'error', title: langItemRemoved, showConfirmButton: false, timer: 1000 });
        window.history.replaceState(null, null, window.location.pathname);
    <?php endif; ?>
</script>

<?php include __DIR__ . '/footer.php'; ?>

<div id="otpModal" class="otp-modal-overlay">
    <div class="otp-modal-box">
        <span class="otp-modal-close" onclick="closeOtpModal()">&times;</span>
        <i class="fab fa-whatsapp otp-wa-icon"></i>
        <h3 class="otp-title"><?php echo htmlspecialchars($lang['verify_phone_title'] ?? ''); ?></h3>
        <p class="otp-desc"><?php echo htmlspecialchars($lang['enter_otp'] ?? ''); ?></p>
        
        <div class="otp-digit-inputs">
            <input type="text" id="otp_val" maxlength="4" class="otp-input">
        </div>

        <button type="button" class="btn-order" onclick="verifyOtp()"><?php echo htmlspecialchars($lang['verify_btn'] ?? ''); ?></button>
        
        <div class="resend-timer">
            <span id="resend_text"><?php echo htmlspecialchars($lang['resend_wait'] ?? ''); ?> <span id="timer_count">60</span>s</span>
            <button type="button" id="resend_btn" class="resend-btn d-none" onclick="sendOtp(true)"><?php echo htmlspecialchars($lang['resend_code'] ?? ''); ?></button>
        </div>
    </div>
</div>

<script>
    const isOtpEnabled = <?php 
        $otp_setting_query = $conn->query("SELECT wa_otp_verification FROM settings WHERE id = 1");
        $is_enabled = $otp_setting_query ? ($otp_setting_query->fetch_assoc()['wa_otp_verification'] ?? 1) : 1;
        echo $is_enabled ? 'true' : 'false'; 
    ?>;

    let countdownInterval;

    function handleOrderSubmit(e) {
        e.preventDefault();
        const phoneSelect = document.getElementById('saved_phone_selector');
        const isSavedPhone = phoneSelect && phoneSelect.value !== 'new';

        if (!isOtpEnabled || isSavedPhone) {
            document.getElementById('orderForm').submit();
            return;
        }
        sendOtp();
    }

    function sendOtp(isResend = false) {
        const country = document.getElementById('country_code').value;
        const phone = document.getElementById('phone_input_field').value;

        if(!phone) {
            Swal.fire({ toast: true, position: 'top', icon: 'error', title: langPleaseEnterPhone, showConfirmButton: false, timer: 1000 });
            return;
        }

        document.getElementById('otpModal').classList.add('active');
        document.body.classList.add('no-scroll'); 
        if(!isResend) { document.getElementById('otp_val').value = ''; }

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('country_code', country);
        formData.append('phone', phone);

        fetch('/ajax_otp.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ toast: true, position: 'top', icon: 'success', title: data.message, showConfirmButton: false, timer: 1000 });
                startTimer(60);
            } else {
                Swal.fire({ icon: 'error', title: langErrorOops, text: data.message });
                closeOtpModal();
            }
        });
    }

    function verifyOtp() {
        const country = document.getElementById('country_code').value;
        const phone = document.getElementById('phone_input_field').value;
        const code = document.getElementById('otp_val').value;

        if (code.length < 4) return;

        const formData = new FormData();
        formData.append('action', 'verify');
        formData.append('country_code', country);
        formData.append('phone', phone);
        formData.append('code', code);

        fetch('/ajax_otp.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                closeOtpModal();
                document.getElementById('orderForm').submit();
            } else {
                Swal.fire({ toast: true, position: 'top', icon: 'error', title: data.message, showConfirmButton: false, timer: 1000 });
            }
        });
    }

    function startTimer(seconds) {
        clearInterval(countdownInterval);
        document.getElementById('resend_text').classList.remove('d-none');
        document.getElementById('resend_btn').classList.add('d-none');
        
        let timeLeft = seconds;
        document.getElementById('timer_count').innerText = timeLeft;

        countdownInterval = setInterval(() => {
            timeLeft--;
            document.getElementById('timer_count').innerText = timeLeft;
            if (timeLeft <= 0) {
                clearInterval(countdownInterval);
                document.getElementById('resend_text').classList.add('d-none');
                document.getElementById('resend_btn').classList.remove('d-none');
            }
        }, 1000);
    }

    function closeOtpModal() {
        document.getElementById('otpModal').classList.remove('active');
        document.body.classList.remove('no-scroll'); 
        clearInterval(countdownInterval);
    }
</script>
</body>
</html>