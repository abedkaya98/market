<?php
$display_site_name = $store_settings['site_name'] ?? '';
$social_fb = $store_settings['social_facebook'] ?? '';
$social_ig = $store_settings['social_instagram'] ?? '';
$social_tt = $store_settings['social_tiktok'] ?? '';
$social_wa = $store_settings['social_whatsapp'] ?? '';
$site_addr = $store_settings['site_address'] ?? '';
$site_phone = $store_settings['site_phone'] ?? '';
$site_email = $store_settings['site_email'] ?? '';
?>

<footer class="main-footer">
	<div class="footer-grid container">
		
		<div class="footer-brand-section">
			<h4 class="footer-brand-title">
				<?php echo htmlspecialchars($display_site_name); ?>
			</h4>
			<p class="footer-desc">
				<?php echo $lang['footer_desc'] ?? ''; ?>
			</p>
		</div>

		<div class="footer-links-section">
			<h4 class="footer-heading"><?php echo $lang['important_links'] ?? ''; ?></h4>
			<ul class="footer-links-list">
				<li><a href="/about/index.php"><i class="fas fa-chevron-left rtl-icon"></i> <?php echo $lang['about_us'] ?? ''; ?></a></li>
				<li><a href="/about/returns.php"><i class="fas fa-chevron-left rtl-icon"></i> <?php echo $lang['return_policy'] ?? ''; ?></a></li>
				<li><a href="/about/shipping.php"><i class="fas fa-chevron-left rtl-icon"></i> <?php echo $lang['shipping_delivery'] ?? ''; ?></a></li>
				<li><a href="/about/privacy_policy.php"><i class="fas fa-chevron-left rtl-icon"></i> <?php echo $lang['privacy_policy'] ?? ''; ?></a></li>
				<li><a href="/about/terms_of_service.php"><i class="fas fa-chevron-left rtl-icon"></i> <?php echo $lang['terms_of_service'] ?? ''; ?></a></li>
			</ul>
		</div>

		<div class="footer-contact-section">
			<h4 class="footer-heading"><?php echo $lang['contact_us'] ?? ''; ?></h4>
			
			<?php if(!empty($site_addr)): ?>
				<p class="contact-item">
					<i class="fas fa-map-marker-alt contact-icon"></i> 
					<span><?php echo htmlspecialchars($site_addr); ?></span>
				</p>
			<?php endif; ?>

			<?php if(!empty($site_phone)): ?>
				<p class="contact-item">
					<i class="fas fa-phone contact-icon"></i> 
					<span dir="ltr"><?php echo htmlspecialchars($site_phone); ?></span>
				</p>
			<?php endif; ?>

			<?php if(!empty($site_email)): ?>
				<p class="contact-item">
					<i class="fas fa-envelope contact-icon"></i> 
					<span><?php echo htmlspecialchars($site_email); ?></span>
				</p>
			<?php endif; ?>

			<div class="social-links">
				<?php if(!empty($social_fb)): ?>
					<a href="<?php echo htmlspecialchars($social_fb); ?>" target="_blank" class="social-icon"><i class="fab fa-facebook-f"></i></a>
				<?php endif; ?>
				
				<?php if(!empty($social_ig)): ?>
					<a href="<?php echo htmlspecialchars($social_ig); ?>" target="_blank" class="social-icon"><i class="fab fa-instagram"></i></a>
				<?php endif; ?>
				
				<?php if(!empty($social_tt)): ?>
					<a href="<?php echo htmlspecialchars($social_tt); ?>" target="_blank" class="social-icon"><i class="fab fa-tiktok"></i></a>
				<?php endif; ?>
				
				<?php if(!empty($social_wa)): ?>
					<a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $social_wa); ?>" target="_blank" class="social-icon"><i class="fab fa-whatsapp"></i></a>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<div class="footer-bottom">
		<p>&copy; <?php echo date("Y"); ?> <?php echo $lang['all_rights_reserved'] ?? ''; ?> <strong><?php echo htmlspecialchars($display_site_name); ?></strong></p>
	</div>
</footer>

<?php if(!empty($social_wa)): ?>
<a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $social_wa); ?>" class="whatsapp-float" target="_blank" rel="noopener noreferrer">
	<i class="fab fa-whatsapp"></i>
</a>
<?php endif; ?>

<script>
function showGlobalNotification(message, type = 'success', requireConfirm = false) {
	const container = document.getElementById('global-notification-container');
	if (!container) return;
	
	const notif = document.createElement('div');
	notif.className = 'global-alert alert-' + type;
	
	const text = document.createElement('span');
	text.textContent = message;
	notif.appendChild(text);
	
	if (requireConfirm) {
		const btn = document.createElement('button');
		btn.innerHTML = '&times;';
		btn.className = 'close-alert-btn';
		btn.onclick = () => notif.remove();
		notif.appendChild(btn);
	}
	
	container.appendChild(notif);
	
	if (!requireConfirm) {
		setTimeout(() => {
			notif.style.opacity = '0';
			setTimeout(() => notif.remove(), 300);
		}, 1000);
	}
}

<?php if (isset($_SESSION['flash_success'])): ?>
	showGlobalNotification(<?php echo json_encode($_SESSION['flash_success']); ?>, "success");
	<?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
	showGlobalNotification(<?php echo json_encode($_SESSION['flash_error']); ?>, "error");
	<?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
</script>

<script src="/assets/js/dark_mode.js"></script>