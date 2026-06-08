<?php
require_once __DIR__ . '/wp-load.php';

// PHP-level suppression
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

// Extracts the main domain (e.g. example.com, example.co.uk)
function extract_subdomains($host) {
    $host = strtolower($host);
    $parts = explode('.', $host);

    if (count($parts) < 3) {
        // Δεν υπάρχουν subdomains
        return '';
    }

    // Παίρνουμε όλα εκτός από τα 2 τελευταία (domain + tld)
    return implode('.', array_slice($parts, 0, -2));
}

$host = parse_url( get_option('siteurl'), PHP_URL_HOST );
$subdomains = extract_subdomains($host);
$is_staging = stripos($site_url, 'staging') !== false;

// We don't want the code to run if is LIVE site (aka if is there is no subdomain or if it is 'www' & no "staging" keyword exists in URL)
if ( (!$subdomains || $subdomains === 'www') && !$is_staging ) {
	echo 'SOS: You cannot run this script in LIVE site!';
	echo "\n";
    return;
}



$functions = [
	'dc_staging_install_mu_plugin_to_restrict_outgoing_emails',
	'dc_staging_add_staging_prefix_to_site_title',
	'dc_staging_enable_coming_soon',
	'dc_staging_enable_no_index',
	'dc_staging_enable_debug_mode',
	'dc_staging_deactivate_plugins',
	'dc_staging_disable_custom_admin_monitor',
	'dc_staging_disable_order_auto_sync_to_erp',
	'dc_staging_change_admin_email',
	'dc_staging_change_wc_email_recipients',
];


// Your logic
echo "\n";

foreach ($functions as $function) {
	ob_start();

	try {
		call_user_func( $function );
	} catch ( Throwable $e ) {
		echo "FAIL: Exception in {$function}: {$e->getMessage()}\nTrace:\n{$e->getTraceAsString()}";
	}

	$echo = ob_get_clean();

	if( trim($echo) ){
		echo "-> WP: $echo";
		echo "\n";
		echo "\n";
	}
}

function dc_staging_add_staging_prefix_to_site_title(){
	$site_title = get_option('blogname');

	if( strpos($site_title, 'STAGING') === 0 ){
		echo 'Site name already has the STAGING prefix.';
		return;
	}

	update_option('blogname', "STAGING $site_title");

	if( strpos(get_option('blogname'), 'STAGING') === 0 ){
		echo 'Site name now has the STAGING prefix!';
	}else{
		echo 'FAIL: Could not add STAGING prefix to site name.';
	}

}

function dc_staging_enable_coming_soon(){

	// Fully qualified class name
	$fqcn = '\Automattic\WooCommerce\Admin\API\LaunchYourStore';
	$method = 'initialize_coming_soon';

	if ( !class_exists( $fqcn ) || !method_exists( $fqcn , $method ) ) {
		echo "FAIL: Coming Soon not enabled. Use plugin or htaccess for this scope";
		return;
	}

	update_option( 'woocommerce_coming_soon', 'yes' );
	update_option( 'woocommerce_store_pages_only', 'no' );
	update_option( 'woocommerce_feature_site_visibility_badge_enabled', 'yes' );

	if ( get_option( 'woocommerce_coming_soon') == 'yes' && get_option( 'woocommerce_store_pages_only') == 'no' && get_option( 'woocommerce_feature_site_visibility_badge_enabled') == 'yes' ) {
		echo "Coming Soon initialized successfully";
	} else {
		echo "FAIL: Coming Soon not enabled. Use plugin or htaccess for this scope";
	}
}

function dc_staging_enable_no_index(){

	update_option( 'blog_public', '0' );

	if ( get_option( 'blog_public', 0) == 0 ) {
		echo "NO-INDEX enabled successfully";
	} else {
		echo "FAIL: NO-INDEX failed to enable";
	}
}


function dc_staging_enable_debug_mode() {
	$config_file = ABSPATH . 'wp-config.php';

	// WP_DEBUG must be exactly true
	$wp_debug_needs_update = !defined('WP_DEBUG') || WP_DEBUG !== true;

	// WP_DEBUG_LOG must be non-falsy (true or string). Update only if missing or false.
	$wp_debug_log_needs_update = !defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG;

	if (!$wp_debug_needs_update && !$wp_debug_log_needs_update) {
		echo 'WP_DEBUG & WP_DEBUG_LOG are already enabled!';
		return;
	}

	if (!file_exists($config_file) || !is_writable($config_file)) {
		echo "FAIL: wp-config.php is not writable, WP_DEBUG & WP_DEBUG_LOG cannot be enabled";
		return;
	}

	$content = file_get_contents($config_file);
	$is_inside_block_comment = false;

	// Process file with callback to safely replace defines outside comments
	$content = preg_replace_callback(
		'/(\/\*|\*\/|\/\/.*$|define\s*\(\s*[\'"](WP_DEBUG|WP_DEBUG_LOG)[\'"].*?\))/m',
		function($matches) use (&$is_inside_block_comment, &$wp_debug_needs_update, &$wp_debug_log_needs_update) {
			$match = $matches[0];

			// Track start of block comment
			if (strpos($match, '/*') !== false) {
				$is_inside_block_comment = true;
				return $match;
			}

			// Track end of block comment
			if (strpos($match, '*/') !== false) {
				$is_inside_block_comment = false;
				return $match;
			}

			// Ignore anything inside block comment
			if ($is_inside_block_comment) {
				return $match;
			}

			// Ignore single-line comments
			if (strpos($match, '//') === 0) {
				return $match;
			}

			// Replace defines if needed
			if ((stripos($match, "'WP_DEBUG'") !== false || stripos($match, '"WP_DEBUG"') !== false) && $wp_debug_needs_update) {
				$wp_debug_needs_update = false;
				return "define('WP_DEBUG', true)";
			}

			if ((stripos($match, "'WP_DEBUG_LOG'") !== false || stripos($match, '"WP_DEBUG_LOG"') !== false) && $wp_debug_log_needs_update) {
				$wp_debug_log_needs_update = false;
				return "define('WP_DEBUG_LOG', true)";
			}


			return $match;
		},
		$content
	);

	// If defines are still missing, insert after <?php
	if ($wp_debug_needs_update || $wp_debug_log_needs_update) {
		$content = preg_replace_callback(
			'/<\?php/',
			function ($matches) use ($wp_debug_needs_update, $wp_debug_log_needs_update) {
				$insert = $matches[0] . "\n";
				if ($wp_debug_needs_update) {
					$insert .= "define('WP_DEBUG', true);\n";
				}
				if ($wp_debug_log_needs_update) {
					$insert .= "define('WP_DEBUG_LOG', true);\n";
				}
				return $insert;
			},
			$content,
			1 // only the first <?php
		);
	}

	// Write the modified content back
	$putting_result = file_put_contents($config_file, $content);

	if($putting_result) {
		echo 'WP_DEBUG & WP_DEBUG_LOG are successfully enabled!';
	}else{
		echo "FAIL: wp-config.php is not writable, WP_DEBUG & WP_DEBUG_LOG cannot be enabled";
	}
}

function dc_staging_deactivate_plugins(){
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$plugins_to_deactivate = [
		'wp-rocket/wp-rocket.php',
		'litespeed-cache/litespeed-cache.php',
		'wp-fastest-cache/wpFastestCache.php',
		'redis-cache/redis-cache.php',
		'wp-all-import-pro/wp-all-import-pro.php',
		'wp-all-export-pro/wp-all-export-pro.php',
		'wpai-acf-add-on/wpai-acf-add-on.php',
		'wpai-woocommerce-add-on/wpai-woocommerce-add-on.php',
		'wpae-woocommerce-add-on/wpae-woocommerce-add-on.php',
		'wpae-acf-add-on/wpae-acf-add-on-pro.php',
		'dc-skroutz-bestprice-feed/dc-skroutz-bestprice-feed.php',
		'xml-feed-for-skroutz-for-woocommerce/xml-feed-for-skroutz-for-woocommerce.php',
		'dc-skroutz-sc/dc-skroutz-sc.php',
		'webexpert-skroutz-xml-feed/webexpert-skroutz-xml-feed.php',
		'webexpert-woocommerce-skroutz-smart-cart/webexpert-woocommerce-skroutz-smart-cart.php',
		'woo-xml-feed-for-skroutzgr-bestpricegr/wooshop-skroutzxml.php',
		'skroutz-analytics-woocommerce/wc-skroutz-analytics.php',
		'skroutz-marketplace-xml-for-woocommerce/wocommerce-skroutz-smart-cart.php',
		'pixelyoursite/facebook-pixel-master.php',
		'pixelyoursite-pro/pixelyoursite-pro.php',
		'pixelyoursite-super-pack/pixelyoursite-super-pack.php',
		'microsoft-clarity/clarity.php',
		'moosend-email-marketing/index.php',
		'webappick-product-feed-for-woocommerce/woo-feed.php',
		'webappick-product-feed-for-woocommerce-pro/webappick-product-feed-for-woocommerce-pro.php',
		'facebook-for-woocommerce/facebook-for-woocommerce.php',
		'enhanced-e-commerce-for-woocommerce-store/enhanced-ecommerce-google-analytics.php',
		'mainwp-child/mainwp-child.php',
		'mainwp-child-reports/mainwp-child-reports.php',
		'google-pagespeed-insights/google-pagespeed-insights.php',
		'rac/recoverabandoncart.php',
		'woocommerce-apg-sms-notifications/apg-sms.php',
		'glami-feed-generator-pixel-for-woocommerce-main/glami-feed-generator-pixel-for-woocommerce.php',
		'woo-product-feed-pro/woocommerce-sea.php',
		'product-catalog-feed-pro/product-catalog-feed-pro.php',
		'dc-shopflix-sc/dc-shopflix-sc.php',
		'smartsupp-live-chat/smartsupp.php',
		'chaty/cht-icons.php',
		'tawkto-live-chat/tawkto.php',
		'tidio-live-chat/tidio-elements.php',
		'chat-viber/chat-viber-lite.php',
		'wp-whatsapp/whatsapp.php',
		'facebook-messenger-customer-chat/facebook-messenger-customer-chat.php',
		'burst-statistics/burst.php',
		'google-listings-and-ads/google-listings-and-ads.php',
		'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php',
		'google-analytics-for-wordpress/googleanalytics.php',
		'woo_xml_feed/wc_xml_feed.php',
		'google-analytics-dashboard-for-wp/gadwp.php'
	];

	$plugins_names = array_map( fn($plg) => $plg['Name'] , get_plugins() );

	foreach($plugins_to_deactivate as $file_path){
		if( $name = $plugins_names[$file_path] ?? null ){

			try {
				deactivate_plugins( $file_path );
				echo "$name plugin deactivated successfully!";
			} catch ( Throwable $e ) {
				echo "FAIL: Exception deactivating $name plugin: {$e->getMessage()}\nTrace:\n{$e->getTraceAsString()}";
			}
			echo "\n";

		}
	}

	echo "Plugins' deactivation finished!";

}

function dc_staging_disable_custom_admin_monitor(){

	delete_option('dc_ca_feeds_list');
	delete_option('dc_ca_wpai_imports_to_check');

	if ( get_option( 'dc_ca_feeds_list', null) === null && get_option( 'dc_ca_wpai_imports_to_check', null) === null ) {
		echo "Custom Admin plugin's Monitor has been disabled successfully";
	} else {
		echo "FAIL: Custom Admin plugin's Monitor failed to be disabled!";
	}
}

function dc_staging_disable_order_auto_sync_to_erp(){
	$b4e_settings = get_option('b4e_settings');
	if( !$b4e_settings ){
		return; // return without message
	}

	if( empty( $b4e_settings['orders_options_status_trigger'] ) ){
		echo 'Order auto sync to ERP is already disabled!';
		return;
	}

	if( $b4e_settings['orders_options_status_trigger'] == 'off' ){
		echo 'Order auto sync to ERP is already disabled!';
		return;
	}

	$b4e_settings['orders_options_status_trigger'] = 'off';
	update_option('b4e_settings', $b4e_settings);

	// if success
	$b4e_settings = get_option('b4e_settings');
	if( $b4e_settings['orders_options_status_trigger'] == 'off' ){
		echo 'Order auto sync to ERP has been disabled!';
	}

}

function dc_staging_change_admin_email(){

	update_option( 'admin_email', 'pexlechris@gmail.com' );

	if ( get_option( 'admin_email') == 'pexlechris@gmail.com' ) {
		echo "Admin email is now pexlechris@gmail.com";
	} else {
		echo "FAIL: Fail to change admin email to pexlechris@gmail.com";
	}
}

function dc_staging_change_wc_email_recipients() {
	global $wpdb;

	$admin_email = get_option('admin_email');


	// Find all WooCommerce email options ending with "woocommerce_*_order_settings"
	// If the relevant option is not set, by default recipient is the admin email
	$options = $wpdb->get_results(
		"SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'woocommerce\_%\_order\_settings'"
	);

	if ( empty( $options ) ) {
		echo "All the woo emails that accepts recipient has been changed to $admin_email";
		return;
	}

	foreach ( $options as $option ) {
		$settings = get_option( $option->option_name );

		// Proceed only if option is an array AND has a non-empty recipient, change it
		if ( is_array( $settings ) && ! empty( $settings['recipient'] ) ) {

			$email_slug = preg_replace('/^woocommerce_(.+)_settings$/', '$1', $option->option_name);

			// Update recipients
			$settings['recipient'] = $admin_email;

			// Save changes
			update_option( $option->option_name, $settings );

			// if success
			$settings = get_option($option->option_name);
			if( $settings['recipient'] != $admin_email ){
				$has_fail = true;
				echo "FAIL: Woo email $email_slug — recipient hasn't been set to $admin_email!";
			}

		}
	}


	if( !empty($has_fail) ) { // == true
		echo "All the other woo emails that accepts recipient has been changed to $admin_email";
	}else{
		echo "All the woo emails that accepts recipient has been changed to $admin_email";
	}
}

function dc_staging_install_mu_plugin_to_restrict_outgoing_emails(){
	$plugin_code = <<<'PHP'
<?php
/**
 * Plugin Name: DC Staging Email Restriction
 * Description: Allow outgoing emails only to @pexlechris.dev and pexlechris@gmail.com (including aliases).
 */

add_filter('wp_mail', 'dc_staging_remove_outside_my_email_addresses', 999);
function dc_staging_remove_outside_my_email_addresses( $mail_data ){
    $to = !is_array( $mail_data['to'] ) ? explode(',', $mail_data['to'] ) : $mail_data['to'];
	$mail_data['to'] = array_filter( $to, 'dc_staging_is_email_address_allowed' );
	return $mail_data;
}

add_filter('pre_wp_mail', 'dc_staging_restrict_outgoing_emails', 999, 2);
function dc_staging_restrict_outgoing_emails( $default_value, $mail_data ){
	if ( empty($mail_data['to']) ) {
		do_action('wp_mail_failed', new WP_Error('wp_mail_failed','Outgoing emails are restricted. Only @pexlechris.dev addresses and pexlechris@gmail.com (including its aliases) are allowed.', $mail_data));
		return false;
	}

	return $default_value;
}

function dc_staging_is_email_address_allowed( $email_address ) {
	$email_address = strtolower(trim($email_address));

	// Allow *@pexlechris.dev using preg_match
	if ( preg_match('/@pexlechris\.dev$/', $email_address) ) {
		return true;
	}

	if ( preg_match('/^(.*?)@(gmail\.com|googlemail\.com)$/', $email_address, $matches) ) {
		$local_part = $matches[1];

		// Remove dots & +anything (because they are alias)
		$local_part = str_replace('.', '', $local_part);
		$local_part = preg_replace('/\+.*/', '', $local_part);

		if ( $local_part === 'pexlechris' ) { // only allow aliases of pexlechris@gmail.com
			return true;
		}
	}

	return false;
}

add_action('admin_notices', 'dc_staging_email_restriction_notice');
function dc_staging_email_restriction_notice() {
	?>
	<div class="notice notice-info">
		<p><strong>Outgoing emails are restricted.</strong> Only <code>@pexlechris.dev</code> addresses and <code>pexlechris@gmail.com</code> (including its aliases) are allowed.</p>
	</div>
	<?php
}
PHP;

	wp_mkdir_p(WPMU_PLUGIN_DIR);

	// Path to MU-Plugin
	$mu_plugin_path = trailingslashit(WPMU_PLUGIN_DIR) . 'restrict-outgoing-emails.php';

	// Write file
	$data_written = file_put_contents($mu_plugin_path, $plugin_code);

	if($data_written){
		echo 'MU Plugin that restricts outgoing emails has been installed! Only @pexlechris.dev addresses and pexlechris@gmail.com (including its aliases) are allowed.';
	}else{
		echo 'FAIL: Could not install MU plugin that restricts outgoing emails!';
	}
}
