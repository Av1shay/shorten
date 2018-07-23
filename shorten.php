<?php
/**
 * Plugin Name: Shorten
 * Description: A simple image compression plugin. Using ShortPixel image compression API.
 * Author: Avishay Guttman
 * Version: 1.0.0
 * Text Domain: shorten
 * Domain Path: /languages
 */

namespace Avishay;

use ShortPixel, ShortPixel\Exception, Katzgrau, Psr;

if ( ! defined('ABSPATH') ) {
	exit;
}

require_once('vendor/autoload.php');

if ( !class_exists('Shorten') ) :

class Shorten{

	/**
	 * @var bool
	 */
	private $enable_image_compression;

	/**
	 * @var string
	 */
	private $shortpixel_api;

	/**
	 * @var string
	 */
	private $compression_level;

	/**
	 * @var Katzgrau\KLogger\Logger|null
	 */
	private $logger = null;

	public function init(){
		$class = __CLASS__;
		new $class;
	}

	public function __construct() {
	    $uploads = wp_upload_dir();
		$this->enable_image_compression =  get_option('enable_image_compression', '') == 'yes' ? true : false;
		$this->shortpixel_api = get_option('shortpixel_api_key', '');
		$this->compression_level = get_option('compression_level', '');

		if ( wp_is_writable($uploads['basedir']) ) {
			$this->logger = new Katzgrau\KLogger\Logger( $uploads['basedir'], Psr\Log\LogLevel::ERROR, array('filename' => 'shorten.log') );
		}

		if ( !empty($this->shortpixel_api) ) {
			ShortPixel\setKey($this->shortpixel_api);
		}

		add_action('admin_init', array($this, 'register_shorten_settings'));
		add_action('admin_menu', array($this, 'add_shorten_menu_page'));
		add_filter('wp_generate_attachment_metadata', array($this, 'compress_uploaded_images'), 10, 2);
	}

	public function register_shorten_settings(){
		add_settings_section('shorten-settings', '', array($this, 'nothing'), 'shorten_image_compression');
		add_settings_field('enable-image-compression', __('Enable Image Compression', 'shorten'), array($this, 'enable_image_compression'), 'shorten_image_compression', 'shorten-settings', array('label_for' => 'enable-image-compression'));
		add_settings_field('shortpixel-api-key', __('ShortPixel API Key', 'shorten'), array($this, 'shortpixel_api_field'), 'shorten_image_compression', 'shorten-settings', array('label_for' => 'shortpixel-api-key'));
		add_settings_field('compression-level', __('Compression Level', 'shorten'), array($this, 'compression_level_field'), 'shorten_image_compression', 'shorten-settings');
		register_setting('shorten_image_compression', 'enable_image_compression');
		register_setting('shorten_image_compression', 'shortpixel_api_key');
		register_setting('shorten_image_compression', 'compression_level');
	}

	public function add_shorten_menu_page(){
		add_options_page(__('Shorten Image Compression', 'shorten'),
			__('Shorten', 'shorten'),
			'manage_options',
			'shorten_image_compression',
			array($this, 'menu_page_content'));
	}

	public function menu_page_content(){
		if ( ! current_user_can('manage_options') ) {
			wp_die('You do not have sufficient permissions to access this page.');
		}
		?>

		<div class="wrap">
			<h1><?php _e('Shorten Settings', 'shorten') ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields('shorten_image_compression'); ?>
				<?php do_settings_sections('shorten_image_compression'); ?>
				<?php submit_button(); ?>
			</form>
		</div>
	<?php
	}

	/**
	 * WP settings API forces us to register function that output section content, but we don't have any content to print yet.
	 */
	public function nothing(){
		// do nothing.
	}

	/*--------- Setting Fields ---------*/
	public function enable_image_compression(){
		$enable_image_compression = get_option('enable_image_compression', '');
		echo '<input id="enable-image-compression" type="checkbox" name="enable_image_compression" value="yes" '.checked($enable_image_compression, 'yes', false).'/>';
	}
	public function shortpixel_api_field(){
		$shortpixel_api_key = get_option('shortpixel_api_key', '');
		echo '<input id="shortpixel-api-key" type="password" name="shortpixel_api_key" value="'.esc_attr($shortpixel_api_key).'"/>';
	}
	public function compression_level_field(){
		$compression_level = get_option('compression_level', '');
		echo '<label for="lossless-compression" style="display: block; margin: .25em 0 .5em!important;"><input id="lossless-compression" type="radio" name="compression_level" value="lossless" '.checked($compression_level, 'lossless', false).'/>'.__('Lossless', 'shorten').'</label>';
		echo '<label for="lossy-compression" style="display: block; margin: .25em 0 .5em!important;"><input id="lossy-compression" type="radio" name="compression_level" value="lossy" '.checked($compression_level, 'lossy', false).'/>'.__('Lossy', 'shorten').'</label>';
		echo '<label for="glossy-compression" style="display: block; margin: .25em 0 .5em!important;"><input id="glossy-compression" type="radio" name="compression_level" value="glossy" '.checked($compression_level, 'glossy', false).'/>'.__('Glossy', 'shorten').'</label>';
	}

	/**
	 * Compress each uploaded image (including image sizes).
	 *
     * @see https://github.com/short-pixel-optimizer/shortpixel-php
     *
	 * @param $metadata
	 * @param $id
	 *
	 * @return mixed
	 */
	public function compress_uploaded_images($metadata, $id){
		$mime_type = get_post_mime_type($id);
		$upload_dir = wp_upload_dir();
		$compress_level = $this->get_compression_level_int();
		$path = str_replace('\\', '/', $upload_dir['path']);

		// bail if we don't have image or if the user don't want to compress the images
		if ( ($mime_type != 'image/jpeg' && $mime_type != 'image/png') || ! $this->enable_image_compression ) {
			return $metadata;
		}
		// check if current upload dir is writable
		if ( ! is_writable($upload_dir['path']) ) {
			return $metadata;
		}

		// first compress the main image
		$file = get_attached_file($id);
		try {
			ShortPixel\fromFile($file)->optimize($compress_level)->toFiles($path);
		} catch ( Exception $e ) {
		    if ( ! is_null($this->logger) ) {
			    $this->logger->error($e->getMessage());
            }
			return $metadata;
		}
		// compress the image sizes also
		if ( isset($metadata['sizes']) ) {
			foreach ( $metadata['sizes'] as $size ) {
				$file = $path . '/' . $size['file'];
				if ( ! file_exists($file) ) continue;
				try {
					ShortPixel\fromFile($file)->optimize($compress_level)->toFiles($path);
				} catch ( Exception $e ) {
					if ( ! is_null($this->logger) ) {
						$this->logger->error($e->getMessage());
					}
					// don't stop if we fail to compress an image size
					continue;
				}
			}
		}

		return $metadata;
	}

	/**
	 * Get compression level int by the level string.
	 *
	 * @return int
	 */
	private function get_compression_level_int(){
		switch ( $this->compression_level ) {
			case 'lossless':
				return 0;
			case 'lossy':
				return 1;
			case 'glossy':
				return 2;
			default:
				return 1;
		}
	}
}

endif;

add_action('plugins_loaded', array('Avishay\Shorten', 'init'));