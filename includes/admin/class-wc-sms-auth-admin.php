<?php
/**
 * کنترلر پنل تنظیمات پیشخوان (Options API).
 *
 * مسئول ثبت زیرمنوی تنظیمات افزونه در منوی ووکامرس و رندر صفحه تنظیمات.
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس مدیریت پنل ادمین افزونه.
 */
class WC_SMS_Auth_Admin {

	/**
	 * اسلاگ صفحه تنظیمات افزونه در پیشخوان.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'wc-sms-auth-settings';

	/**
	 * نام گروه تنظیمات تب "عمومی" جهت استفاده در register_setting و settings_fields.
	 *
	 * @var string
	 */
	const OPTION_GROUP_GENERAL = 'wc_sms_auth_general_group';

	/**
	 * اسلاگ صفحه مجازی تنظیمات تب "عمومی" جهت استفاده در add_settings_section/do_settings_sections.
	 *
	 * @var string
	 */
	const SETTINGS_PAGE_GENERAL = 'wc_sms_auth_general_settings';

	/**
	 * نام گروه تنظیمات تب "درگاه پیامک" جهت استفاده در register_setting و settings_fields.
	 *
	 * @var string
	 */
	const OPTION_GROUP_GATEWAY = 'wc_sms_auth_gateway_group';

	/**
	 * اسلاگ صفحه مجازی تنظیمات تب "درگاه پیامک" جهت استفاده در add_settings_section/do_settings_sections.
	 *
	 * @var string
	 */
	const SETTINGS_PAGE_GATEWAY = 'wc_sms_auth_gateway_settings';

	/**
	 * هوک‌ساف (Hook Suffix) صفحه تنظیمات، جهت بارگذاری هدفمند استایل/اسکریپت.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * فهرست درگاه‌های پیامکی پشتیبانی‌شده به همراه عنوان فارسی هرکدام.
	 *
	 * @var array
	 */
	private $gateways = array();

	/**
	 * فهرست تب‌های صفحه تنظیمات به همراه عنوان فارسی هرکدام.
	 *
	 * @var array
	 */
	private $tabs = array();

	/**
	 * سازنده کلاس؛ اتصال هوک‌های مورد نیاز پنل ادمین.
	 */
	public function __construct() {
		$this->tabs = array(
			'general' => __( 'تنظیمات عمومی', 'wc-sms-auth' ),
			'gateway' => __( 'تنظیمات درگاه پیامک', 'wc-sms-auth' ),
			'test'    => __( 'تست پیامک', 'wc-sms-auth' ),
		);

		$this->gateways = array(
			'farazsms'     => __( 'فراز اس‌ام‌اس (FarazSMS)', 'wc-sms-auth' ),
			'kavenegar'    => __( 'کاوه‌نگار (Kavenegar)', 'wc-sms-auth' ),
			'mellipayamak' => __( 'ملی‌پیامک (MelliPayamak)', 'wc-sms-auth' ),
			'smsir'        => __( 'اس‌ام‌اس.آی‌آر (Sms.ir)', 'wc-sms-auth' ),
		);

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'register_general_settings' ) );
		add_action( 'admin_init', array( $this, 'register_gateway_settings' ) );
		add_action( 'wp_ajax_wc_sms_auth_send_test_sms', array( $this, 'handle_test_sms_ajax' ) );
	}

	/**
	 * ثبت زیرمنوی "تنظیمات ورود پیامکی" در منوی اصلی ووکامرس.
	 */
	public function register_admin_menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			esc_html__( 'تنظیمات ورود پیامکی', 'wc-sms-auth' ),
			esc_html__( 'تنظیمات ورود پیامکی', 'wc-sms-auth' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * بارگذاری استایل و اسکریپت اختصاصی پنل ادمین، فقط در صفحه تنظیمات همین افزونه.
	 *
	 * علاوه بر فایل‌های اختصاصی افزونه، اسکریپت‌های هسته مدیریت رسانه وردپرس
	 * (wp_enqueue_media) نیز فقط در همین صفحه بارگذاری می‌شوند تا کاربر
	 * بتواند از طریق کتابخانه رسانه، تصویر بنر مودال را انتخاب/آپلود کند.
	 *
	 * @param string $hook_suffix هوک‌ساف صفحه جاری ادمین که وردپرس ارسال می‌کند.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( empty( $this->page_hook ) || $hook_suffix !== $this->page_hook ) {
			return;
		}

		// بارگذاری اسکریپت‌های هسته کتابخانه رسانه وردپرس (Media Uploader).
		wp_enqueue_media();

		wp_enqueue_style(
			'wc-sms-auth-admin',
			WC_SMS_AUTH_PLUGIN_URL . 'assets/css/modal-admin.css',
			array(),
			WC_SMS_AUTH_VERSION
		);

		wp_enqueue_script(
			'wc-sms-auth-admin',
			WC_SMS_AUTH_PLUGIN_URL . 'assets/js/modal-admin.js',
			array(),
			WC_SMS_AUTH_VERSION,
			true
		);
	}

	/**
	 * ثبت تنظیمات (Options) تب "تنظیمات عمومی" با استفاده از Settings API وردپرس.
	 *
	 * فیلدهای این تب شامل: شناسه تصویر بنر مودال، متن عنوان فرم ورود،
	 * متن دکمه ارسال مرحله اول و زمان انقضای تایمر (بر حسب ثانیه) است.
	 */
	public function register_general_settings() {
		register_setting(
			self::OPTION_GROUP_GENERAL,
			'wc_sms_auth_banner_image_id',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			self::OPTION_GROUP_GENERAL,
			'wc_sms_auth_login_title',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'ورود به سایت', 'wc-sms-auth' ),
			)
		);

		register_setting(
			self::OPTION_GROUP_GENERAL,
			'wc_sms_auth_step1_button_text',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'ورود با کد یکبار مصرف', 'wc-sms-auth' ),
			)
		);

		register_setting(
			self::OPTION_GROUP_GENERAL,
			'wc_sms_auth_otp_timer_seconds',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_timer_seconds' ),
				'default'           => 120,
			)
		);

		add_settings_section(
			'wc_sms_auth_general_section',
			'',
			'__return_false',
			self::SETTINGS_PAGE_GENERAL
		);

		add_settings_field(
			'wc_sms_auth_banner_image_id',
			__( 'تصویر بنر مودال', 'wc-sms-auth' ),
			array( $this, 'render_banner_image_field' ),
			self::SETTINGS_PAGE_GENERAL,
			'wc_sms_auth_general_section'
		);

		add_settings_field(
			'wc_sms_auth_login_title',
			__( 'متن عنوان فرم ورود', 'wc-sms-auth' ),
			array( $this, 'render_login_title_field' ),
			self::SETTINGS_PAGE_GENERAL,
			'wc_sms_auth_general_section'
		);

		add_settings_field(
			'wc_sms_auth_step1_button_text',
			__( 'متن دکمه ارسال مرحله اول', 'wc-sms-auth' ),
			array( $this, 'render_step1_button_text_field' ),
			self::SETTINGS_PAGE_GENERAL,
			'wc_sms_auth_general_section'
		);

		add_settings_field(
			'wc_sms_auth_otp_timer_seconds',
			__( 'زمان انقضای تایمر (ثانیه)', 'wc-sms-auth' ),
			array( $this, 'render_otp_timer_seconds_field' ),
			self::SETTINGS_PAGE_GENERAL,
			'wc_sms_auth_general_section'
		);
	}

	/**
	 * ولیدیشن و محدودسازی زمان انقضای تایمر بین ۳۰ تا ۱۲۰ ثانیه.
	 * (سقف ۱۲۰ ثانیه مطابق مشخصات امنیتی معماری افزونه رعایت می‌شود.)
	 *
	 * @param mixed $value مقدار ارسالی از فرم تنظیمات.
	 * @return int
	 */
	public function sanitize_timer_seconds( $value ) {
		$value = absint( $value );

		if ( $value < 30 ) {
			$value = 30;
		} elseif ( $value > 120 ) {
			$value = 120;
		}

		return $value;
	}

	/**
	 * ثبت تنظیمات (Options) تب "تنظیمات درگاه پیامک" با استفاده از Settings API وردپرس.
	 *
	 * شامل فیلد انتخاب درگاه فعال و به ازای هر درگاه: کلید API،
	 * خط ارسال‌کننده و کد پترن ارسال کد یکبار مصرف.
	 */
	public function register_gateway_settings() {
		register_setting(
			self::OPTION_GROUP_GATEWAY,
			'wc_sms_auth_active_gateway',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_active_gateway' ),
				'default'           => 'farazsms',
			)
		);

		foreach ( array_keys( $this->gateways ) as $gateway_key ) {
			register_setting(
				self::OPTION_GROUP_GATEWAY,
				"wc_sms_auth_{$gateway_key}_api_key",
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);

			register_setting(
				self::OPTION_GROUP_GATEWAY,
				"wc_sms_auth_{$gateway_key}_sender_number",
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);

			register_setting(
				self::OPTION_GROUP_GATEWAY,
				"wc_sms_auth_{$gateway_key}_pattern_code",
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);
		}

		add_settings_section(
			'wc_sms_auth_gateway_section',
			'',
			'__return_false',
			self::SETTINGS_PAGE_GATEWAY
		);

		add_settings_field(
			'wc_sms_auth_active_gateway',
			__( 'درگاه پیامکی فعال', 'wc-sms-auth' ),
			array( $this, 'render_active_gateway_field' ),
			self::SETTINGS_PAGE_GATEWAY,
			'wc_sms_auth_gateway_section'
		);

		foreach ( $this->gateways as $gateway_key => $gateway_label ) {
			add_settings_field(
				"wc_sms_auth_{$gateway_key}_fields",
				/* translators: %s: نام درگاه پیامکی. */
				sprintf( __( 'تنظیمات %s', 'wc-sms-auth' ), $gateway_label ),
				array( $this, 'render_gateway_fields_row' ),
				self::SETTINGS_PAGE_GATEWAY,
				'wc_sms_auth_gateway_section',
				array( 'gateway_key' => $gateway_key )
			);
		}
	}

	/**
	 * ولیدیشن مقدار درگاه فعال؛ فقط مقادیر مجاز (whitelist) پذیرفته می‌شوند.
	 *
	 * @param mixed $value مقدار ارسالی از فرم تنظیمات.
	 * @return string
	 */
	public function sanitize_active_gateway( $value ) {
		$value = sanitize_key( $value );

		if ( ! array_key_exists( $value, $this->gateways ) ) {
			return 'farazsms';
		}

		return $value;
	}

	/**
	 * رندر فیلد Select انتخاب درگاه پیامکی فعال.
	 */
	public function render_active_gateway_field() {
		$current = get_option( 'wc_sms_auth_active_gateway', 'farazsms' );
		?>
		<select id="wc_sms_auth_active_gateway" name="wc_sms_auth_active_gateway">
			<?php foreach ( $this->gateways as $gateway_key => $gateway_label ) : ?>
				<option value="<?php echo esc_attr( $gateway_key ); ?>" <?php selected( $current, $gateway_key ); ?>>
					<?php echo esc_html( $gateway_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'درگاهی که برای ارسال واقعی پیامک کد یکبار مصرف استفاده خواهد شد.', 'wc-sms-auth' ); ?>
		</p>
		<?php
	}

	/**
	 * رندر مجموعه فیلدهای اختصاصی یک درگاه پیامکی (کلید API، خط ارسال‌کننده، کد پترن).
	 *
	 * @param array $args آرگومان‌های ارسالی از add_settings_field؛ شامل کلید gateway_key.
	 */
	public function render_gateway_fields_row( $args ) {
		$gateway_key = isset( $args['gateway_key'] ) ? $args['gateway_key'] : '';

		if ( empty( $gateway_key ) || ! array_key_exists( $gateway_key, $this->gateways ) ) {
			return;
		}

		$api_key       = get_option( "wc_sms_auth_{$gateway_key}_api_key", '' );
		$sender_number = get_option( "wc_sms_auth_{$gateway_key}_sender_number", '' );
		$pattern_code  = get_option( "wc_sms_auth_{$gateway_key}_pattern_code", '' );
		?>
		<div class="wc-sms-auth-gateway-fields" data-gateway="<?php echo esc_attr( $gateway_key ); ?>">
			<p>
				<label for="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_api_key">
					<?php esc_html_e( 'کلید API', 'wc-sms-auth' ); ?>
				</label><br />
				<input
					type="text"
					dir="ltr"
					class="regular-text"
					id="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_api_key"
					name="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_api_key"
					value="<?php echo esc_attr( $api_key ); ?>"
				/>
			</p>
			<p>
				<label for="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_sender_number">
					<?php esc_html_e( 'خط ارسال‌کننده', 'wc-sms-auth' ); ?>
				</label><br />
				<input
					type="text"
					dir="ltr"
					class="regular-text"
					id="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_sender_number"
					name="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_sender_number"
					value="<?php echo esc_attr( $sender_number ); ?>"
				/>
			</p>
			<p>
				<label for="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_pattern_code">
					<?php esc_html_e( 'کد پترن ارسال کد تایید (OTP)', 'wc-sms-auth' ); ?>
				</label><br />
				<input
					type="text"
					dir="ltr"
					class="regular-text"
					id="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_pattern_code"
					name="wc_sms_auth_<?php echo esc_attr( $gateway_key ); ?>_pattern_code"
					value="<?php echo esc_attr( $pattern_code ); ?>"
				/>
			</p>
		</div>
		<?php
	}

	/**
	 * رندر فیلد شناسه تصویر بنر مودال به همراه دکمه‌های آپلودر رسانه وردپرس
	 * (انتخاب تصویر از کتابخانه رسانه / حذف تصویر) و پیش‌نمایش زنده.
	 */
	public function render_banner_image_field() {
		$image_id  = absint( get_option( 'wc_sms_auth_banner_image_id', 0 ) );
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<div class="wc-sms-auth-banner-uploader" data-wc-sms-auth="banner-uploader">
			<div
				class="wc-sms-auth-banner-preview-wrap"
				id="wc_sms_auth_banner_preview_wrap"
				style="<?php echo esc_attr( $image_id ? '' : 'display:none;' ); ?>"
			>
				<img
					id="wc_sms_auth_banner_preview"
					src="<?php echo esc_url( $image_url ); ?>"
					alt=""
				/>
			</div>

			<input
				type="hidden"
				id="wc_sms_auth_banner_image_id"
				name="wc_sms_auth_banner_image_id"
				value="<?php echo esc_attr( $image_id ); ?>"
			/>

			<p>
				<button
					type="button"
					class="button"
					id="wc_sms_auth_banner_select_btn"
					data-wc-sms-auth-action="select-banner-image"
				>
					<?php esc_html_e( 'انتخاب تصویر', 'wc-sms-auth' ); ?>
				</button>
				<button
					type="button"
					class="button"
					id="wc_sms_auth_banner_remove_btn"
					data-wc-sms-auth-action="remove-banner-image"
					style="<?php echo esc_attr( $image_id ? '' : 'display:none;' ); ?>"
				>
					<?php esc_html_e( 'حذف تصویر', 'wc-sms-auth' ); ?>
				</button>
			</p>

			<p class="description">
				<?php esc_html_e( 'تصویر بنر نمایش داده شده در سمت تصویری مودال ورود و ثبت‌نام.', 'wc-sms-auth' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * رندر فیلد متن عنوان فرم ورود.
	 */
	public function render_login_title_field() {
		$value = get_option( 'wc_sms_auth_login_title', __( 'ورود به سایت', 'wc-sms-auth' ) );
		?>
		<input
			type="text"
			class="regular-text"
			id="wc_sms_auth_login_title"
			name="wc_sms_auth_login_title"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<?php
	}

	/**
	 * رندر فیلد متن دکمه ارسال مرحله اول (ارسال کد تایید).
	 */
	public function render_step1_button_text_field() {
		$value = get_option( 'wc_sms_auth_step1_button_text', __( 'ورود با کد یکبار مصرف', 'wc-sms-auth' ) );
		?>
		<input
			type="text"
			class="regular-text"
			id="wc_sms_auth_step1_button_text"
			name="wc_sms_auth_step1_button_text"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<?php
	}

	/**
	 * رندر فیلد زمان انقضای تایمر ارسال مجدد کد (بر حسب ثانیه).
	 */
	public function render_otp_timer_seconds_field() {
		$value = absint( get_option( 'wc_sms_auth_otp_timer_seconds', 120 ) );
		?>
		<input
			type="number"
			min="30"
			max="120"
			step="1"
			class="small-text"
			id="wc_sms_auth_otp_timer_seconds"
			name="wc_sms_auth_otp_timer_seconds"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<span><?php esc_html_e( 'ثانیه', 'wc-sms-auth' ); ?></span>
		<p class="description">
			<?php esc_html_e( 'حداقل ۳۰ و حداکثر ۱۲۰ ثانیه مطابق سیاست امنیتی افزونه.', 'wc-sms-auth' ); ?>
		</p>
		<?php
	}

	/**
	 * رندر ساختار اولیه صفحه تنظیمات افزونه شامل تب‌بندی سه‌گانه.
	 * (فیلدهای واقعی هر تب با Options API در پرامپت‌های بعدی تکمیل می‌شوند.)
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap wc-sms-auth-settings-wrap">
			<h1><?php echo esc_html__( 'تنظیمات ورود پیامکی', 'wc-sms-auth' ); ?></h1>

			<h2 class="nav-tab-wrapper" id="wc-sms-auth-tabs">
				<?php
				$is_first = true;
				foreach ( $this->tabs as $tab_key => $tab_label ) :
					$active_class = $is_first ? ' nav-tab-active' : '';
					$is_first     = false;
					?>
					<a
						href="#<?php echo esc_attr( $tab_key ); ?>"
						class="nav-tab<?php echo esc_attr( $active_class ); ?>"
						data-tab="<?php echo esc_attr( $tab_key ); ?>"
					>
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			$is_first = true;
			foreach ( $this->tabs as $tab_key => $tab_label ) :
				$active_class = $is_first ? ' wc-sms-auth-tab-content-active' : '';
				$is_first     = false;
				?>
				<div
					id="wc-sms-auth-tab-<?php echo esc_attr( $tab_key ); ?>"
					class="wc-sms-auth-tab-content<?php echo esc_attr( $active_class ); ?>"
					data-tab-content="<?php echo esc_attr( $tab_key ); ?>"
				>
					<?php $this->render_tab_content( $tab_key ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * رندر محتوای اختصاصی هر تب بر اساس شناسه تب.
	 * تب‌های "گیت‌وی" و "تست پیامک" هنوز جای‌گیر (placeholder) هستند
	 * و در پرامپت‌های بعدی فاز ۲ تکمیل می‌شوند.
	 *
	 * @param string $tab_key شناسه تب جاری.
	 */
	private function render_tab_content( $tab_key ) {
		if ( 'general' === $tab_key ) {
			$this->render_general_tab_form();
			return;
		}

		if ( 'gateway' === $tab_key ) {
			$this->render_gateway_tab_form();
			return;
		}

		if ( 'test' === $tab_key ) {
			$this->render_test_tab_content();
			return;
		}

		?>
		<p><?php echo esc_html__( 'محتوای این بخش در مراحل بعدی تکمیل خواهد شد.', 'wc-sms-auth' ); ?></p>
		<?php
	}

	/**
	 * رندر فرم کامل تب "تنظیمات عمومی" با استفاده از Settings API.
	 */
	private function render_general_tab_form() {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( self::OPTION_GROUP_GENERAL );
			do_settings_sections( self::SETTINGS_PAGE_GENERAL );
			submit_button( __( 'ذخیره تنظیمات عمومی', 'wc-sms-auth' ) );
			?>
		</form>
		<?php
	}

	/**
	 * رندر فرم کامل تب "تنظیمات درگاه پیامک" با استفاده از Settings API.
	 */
	private function render_gateway_tab_form() {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( self::OPTION_GROUP_GATEWAY );
			do_settings_sections( self::SETTINGS_PAGE_GATEWAY );
			submit_button( __( 'ذخیره تنظیمات درگاه', 'wc-sms-auth' ) );
			?>
		</form>
		<?php
	}

	/**
	 * کال‌بک Ajax مربوط به دکمه "ارسال پیامک تست" در تب تست پیامک.
	 *
	 * شماره موبایل ارسالی را اعتبارسنجی کرده، درگاه پیامکی فعال را از طریق
	 * WC_SMS_Gateway_Factory::get_active_gateway() دریافت می‌کند و یک کد
	 * آزمایشی از طریق آن ارسال می‌کند؛ نتیجه موفقیت یا خطای واقعی وب‌سرویس
	 * به صورت JSON به ادمین بازگردانده می‌شود.
	 */
	public function handle_test_sms_ajax() {
		if ( ! check_ajax_referer( 'wc_sms_auth_test_sms', 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'نشست شما نامعتبر شده است؛ لطفاً صفحه را بارگذاری مجدد کرده و دوباره تلاش کنید.', 'wc-sms-auth' ) ),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شما دسترسی لازم برای انجام این عملیات را ندارید.', 'wc-sms-auth' ) ),
				403
			);
		}

		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شماره موبایل وارد شده معتبر نیست (مثال: 09123456789).', 'wc-sms-auth' ) ),
				400
			);
		}

		$gateway = WC_SMS_Gateway_Factory::get_active_gateway();

		if ( is_wp_error( $gateway ) ) {
			wp_send_json_error(
				array( 'message' => $gateway->get_error_message() ),
				500
			);
		}

		// تولید یک کد آزمایشی ۵ رقمی صرفاً جهت تست واقعی اتصال به وب‌سرویس درگاه فعال.
		$test_code    = (string) wp_rand( 10000, 99999 );
		$send_result  = $gateway->send_otp( $phone, $test_code, '' );

		if ( is_wp_error( $send_result ) ) {
			wp_send_json_error(
				array( 'message' => $send_result->get_error_message() ),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: شماره موبایل مقصد پیامک تست. */
					__( 'پیامک تست با موفقیت به شماره %s ارسال شد.', 'wc-sms-auth' ),
					$phone
				),
			)
		);
	}

	/**
	 * رندر محتوای تب "تست پیامک".
	 *
	 * این بخش فرم واقعی Settings API نیست (چیزی در دیتابیس ذخیره نمی‌کند)؛
	 * صرفاً یک فرم آماده با ویژگی‌های data-* است تا در پرامپت‌های بعدی
	 * توسط جاوااسکریپت گرفته شده و به صورت Ajax به REST API/admin-ajax
	 * ارسال شود تا با درگاه فعال یک پیامک تست واقعی ارسال گردد.
	 */
	private function render_test_tab_content() {
		?>
		<div class="wc-sms-auth-test-wrap" data-wc-sms-auth="test-sms-panel">
			<p class="description">
				<?php esc_html_e( 'برای اطمینان از صحت تنظیمات درگاه فعال، یک شماره موبایل وارد کرده و پیامک تست ارسال کنید.', 'wc-sms-auth' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wc_sms_auth_test_phone"><?php esc_html_e( 'شماره موبایل', 'wc-sms-auth' ); ?></label>
					</th>
					<td>
						<input
							type="tel"
							id="wc_sms_auth_test_phone"
							class="regular-text"
							dir="ltr"
							placeholder="09xxxxxxxxx"
							data-wc-sms-auth-field="test-phone"
						/>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button
					type="button"
					id="wc_sms_auth_send_test_sms"
					class="button button-primary"
					data-wc-sms-auth-action="send-test-sms"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_sms_auth_test_sms' ) ); ?>"
				>
					<?php esc_html_e( 'ارسال پیامک تست', 'wc-sms-auth' ); ?>
				</button>
				<span class="spinner" id="wc_sms_auth_test_sms_spinner" data-wc-sms-auth="test-sms-spinner"></span>
			</p>

			<div
				id="wc_sms_auth_test_sms_result"
				class="notice inline"
				data-wc-sms-auth="test-sms-result"
				style="display: none; padding: 8px 12px;"
			></div>
		</div>
		<?php
	}
}
