<?php
/**
 * Plugin Name:       WooCommerce SMS Auth Modal
 * Plugin URI:        https://example.com/wc-sms-auth-modal
 * Description:       سیستم احراز هویت مستقل و سراسری بر پایه رمز یکبار مصرف (OTP) پیامکی در قالب یک مودال پاپ‌آپ برای ووکامرس، بدون دستکاری فیلدهای پیش‌فرض تسویه حساب.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * Text Domain:       wc-sms-auth
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

// تعریف ثابت نسخه افزونه.
if ( ! defined( 'WC_SMS_AUTH_VERSION' ) ) {
	define( 'WC_SMS_AUTH_VERSION', '1.1.5' );
}

// تعریف ثابت مسیر فیزیکی پوشه افزونه (با اسلش انتهایی).
if ( ! defined( 'WC_SMS_AUTH_PLUGIN_DIR' ) ) {
	define( 'WC_SMS_AUTH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// تعریف ثابت آدرس URL پوشه افزونه (با اسلش انتهایی).
if ( ! defined( 'WC_SMS_AUTH_PLUGIN_URL' ) ) {
	define( 'WC_SMS_AUTH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// تعریف ثابت مسیر کامل فایل اصلی افزونه.
if ( ! defined( 'WC_SMS_AUTH_PLUGIN_FILE' ) ) {
	define( 'WC_SMS_AUTH_PLUGIN_FILE', __FILE__ );
}

// تعریف ثابت مسیر پایه فایل افزونه (پوشه/فایل.php) جهت استفاده در plugin_basename.
if ( ! defined( 'WC_SMS_AUTH_PLUGIN_BASENAME' ) ) {
	define( 'WC_SMS_AUTH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * بررسی می‌کند که آیا افزونه ووکامرس در سایت فعال است یا خیر.
 * (سازگار با حالت تک‌سایتی و همچنین شبکه چندسایتی وردپرس - Multisite).
 *
 * @return bool
 */
function wc_sms_auth_modal_is_woocommerce_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( 'woocommerce/woocommerce.php' );
}

/**
 * کال‌بک اجرا شونده هنگام فعال‌سازی افزونه.
 *
 * در صورتی که افزونه ووکامرس فعال نباشد، فرآیند فعال‌سازی متوقف شده،
 * خود افزونه به صورت خودکار غیرفعال می‌گردد و پیام خطای مناسب
 * به همراه لینک بازگشت به کاربر ادمین نمایش داده می‌شود.
 */
function wc_sms_auth_modal_activate() {
	if ( wc_sms_auth_modal_is_woocommerce_active() ) {
		return;
	}

	// غیرفعال‌سازی خودکار همین افزونه به دلیل عدم وجود پیش‌نیاز.
	deactivate_plugins( WC_SMS_AUTH_PLUGIN_BASENAME );

	wp_die(
		esc_html__( 'این افزونه برای کارکرد به ووکامرس نیاز دارد. لطفاً ابتدا افزونه ووکامرس را نصب و فعال نمایید.', 'wc-sms-auth' ),
		esc_html__( 'خطا در فعال‌سازی افزونه', 'wc-sms-auth' ),
		array( 'back_link' => true )
	);
}
register_activation_hook( WC_SMS_AUTH_PLUGIN_FILE, 'wc_sms_auth_modal_activate' );

/**
 * کلاس اصلی و هسته مرکزی افزونه.
 *
 * این کلاس با پیاده‌سازی دیزاین پترن Singleton، از نمونه‌سازی مکرر افزونه
 * جلوگیری کرده و به عنوان نقطه ورود مرکزی برای راه‌اندازی زیرسیستم‌ها
 * (Autoloader، پنل ادمین، REST API و غیره) عمل می‌کند.
 *
 * @package WC_SMS_Auth_Modal
 */
final class WC_SMS_Auth_Modal_Main {

	/**
	 * تنها نمونه (Instance) موجود از این کلاس.
	 *
	 * @var WC_SMS_Auth_Modal_Main|null
	 */
	private static $instance = null;

	/**
	 * پوشه‌های مجاز جهت جستجوی فایل کلاس‌ها/اینترفیس‌ها توسط Autoloader.
	 *
	 * @var array
	 */
	private $autoload_directories = array(
		'includes/',
		'includes/admin/',
		'includes/api/',
		'includes/gateways/',
	);

	/**
	 * سازنده خصوصی؛ برای جلوگیری از نمونه‌سازی مستقیم کلاس با new.
	 */
	private function __construct() {
		$this->register_autoloader();
		$this->init_hooks();
	}

	/**
	 * ثبت Autoloader داخلی افزونه بر پایه استاندارد PSR-4.
	 *
	 * تمام کلاس‌ها/اینترفیس‌های افزونه (با پیشوند WC_SMS_ یا WC_Gateway_)
	 * را در پوشه‌های includes، includes/admin، includes/api و
	 * includes/gateways جستجو و به صورت خودکار بارگذاری می‌کند.
	 */
	private function register_autoloader() {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * کال‌بک Autoloader؛ نام کلاس را به مسیر فایل متناظر تبدیل و بارگذاری می‌کند.
	 *
	 * قرارداد نام‌گذاری:
	 * - کلاس‌های عادی      -> class-{نام-کلاس-با-خط-تیره-کوچک}.php
	 * - اینترفیس‌ها (پسوند _Interface) -> interface-{نام-بدون-پیشوند-WC_-و-پسوند-Interface}.php
	 *
	 * @param string $class_name نام کامل کلاس/اینترفیس در حال بارگذاری.
	 */
	private function autoload( $class_name ) {
		// فقط کلاس‌ها/اینترفیس‌های مخصوص همین افزونه را مدیریت کن.
		$is_plugin_class = ( 0 === strpos( $class_name, 'WC_SMS_' ) || 0 === strpos( $class_name, 'WC_Gateway_' ) );

		if ( ! $is_plugin_class ) {
			return;
		}

		// تشخیص اینترفیس از روی پسوند "_Interface" در انتهای نام کلاس.
		if ( '_Interface' === substr( $class_name, -10 ) ) {
			$base_name = substr( $class_name, 0, -10 );
			$base_name = preg_replace( '/^WC_/', '', $base_name );
			$file_name = 'interface-' . strtolower( str_replace( '_', '-', $base_name ) ) . '.php';
		} else {
			$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		}

		foreach ( $this->autoload_directories as $directory ) {
			$file_path = WC_SMS_AUTH_PLUGIN_DIR . $directory . $file_name;

			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}

	/**
	 * جلوگیری از شبیه‌سازی (Clone) نمونه Singleton.
	 */
	private function __clone() {}

	/**
	 * جلوگیری از بازسازی نمونه از طریق unserialize.
	 */
	public function __wakeup() {}

	/**
	 * دریافت تنها نمونه موجود از کلاس اصلی افزونه (ایجاد نمونه در صورت عدم وجود).
	 *
	 * @return WC_SMS_Auth_Modal_Main
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * نمونه کلاس مدیریت پنل ادمین (فقط در محیط پیشخوان بارگذاری می‌شود).
	 *
	 * @var WC_SMS_Auth_Admin|null
	 */
	private $admin = null;

	/**
	 * نمونه کلاس کنترلر REST API (در تمام درخواست‌ها بارگذاری می‌شود).
	 *
	 * @var WC_SMS_Auth_API|null
	 */
	private $api = null;

	/**
	 * ثبت هوک‌های داخلی مورد نیاز پس از راه‌اندازی افزونه.
	 *
	 * توجه: نمونه‌سازی کلاس‌های ادمین و API عمداً اینجا (که در حین اکشن
	 * plugins_loaded اجرا می‌شود) انجام نمی‌شود، بلکه به متد init() که
	 * به اکشن init وردپرس متصل است موکول شده. دلیل: سازنده کلاس ادمین
	 * از تابع ترجمه __() برای ساخت آرایه تب‌ها/درگاه‌ها استفاده می‌کند؛
	 * اگر این نمونه‌سازی زودتر از فراخوانی load_plugin_textdomain() (که
	 * خودش باید در اکشن init یا بعد از آن اجرا شود) رخ دهد، وردپرس ۶.۷+
	 * هشدار "_load_textdomain_just_in_time" صادر می‌کند.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_modal_markup' ) );
	}

	/**
	 * راه‌اندازی زیرسیستم‌های افزونه پس از بارگذاری کامل هسته وردپرس (اکشن init).
	 *
	 * ترتیب اجرای این متد اهمیت دارد: ابتدا فایل‌های ترجمه (Text Domain)
	 * افزونه بارگذاری می‌شوند و فقط پس از آن، کلاس‌های ادمین/API که ممکن
	 * است بلافاصله در سازنده خودشان از رشته‌های ترجمه‌شده استفاده کنند
	 * نمونه‌سازی می‌گردند.
	 */
	public function init() {
		load_plugin_textdomain(
			'wc-sms-auth',
			false,
			dirname( WC_SMS_AUTH_PLUGIN_BASENAME ) . '/languages'
		);

		// راه‌اندازی پنل ادمین فقط در محیط پیشخوان وردپرس.
		if ( is_admin() ) {
			$this->admin = new WC_SMS_Auth_Admin();
		}

		// راه‌اندازی کنترلر REST API در تمام درخواست‌ها (فرانت و ادمین).
		// چون اکشن rest_api_init (که سازنده این کلاس روی آن هوک می‌شود)
		// همیشه بعد از اکشن init در چرخه اجرای وردپرس فراخوانی می‌شود،
		// این جابجایی هیچ تاخیری در ثبت روت‌های REST ایجاد نمی‌کند.
		$this->api = new WC_SMS_Auth_API();
	}

	/**
	 * بارگذاری کتابخانه‌ها و اسکریپت اختصاصی فرانت‌اند در تمام صفحات سایت.
	 *
	 * چون دکمه تریگر مودال (کلاس open-auth-modal) ممکن است در هر نقطه‌ای
	 * از سایت (هدر، منو، صفحات ساخته‌شده با المنتور، بالای صفحه تسویه
	 * حساب) قرار گرفته باشد، این فایل‌ها به‌صورت سراسری و در تمام صفحات
	 * فرانت‌اند بارگذاری می‌شوند.
	 */
	public function enqueue_frontend_assets() {
		// فایل CSS استایل‌دهی شده با تیلویند به صورت محلی (جایگزین نسخه CDN)
		wp_enqueue_style(
			'wc-sms-auth-tailwind',
			WC_SMS_AUTH_PLUGIN_URL . 'assets/css/style.css',
			array(),
			WC_SMS_AUTH_VERSION
		);

		// استایل تقویتی اختصاصی مودال؛ وابسته به فایل تیلویند
		wp_enqueue_style(
			'wc-sms-auth-frontend',
			WC_SMS_AUTH_PLUGIN_URL . 'assets/css/modal-frontend.css',
			array( 'wc-sms-auth-tailwind' ),
			WC_SMS_AUTH_VERSION
		);

		// کتابخانه آیکون‌های Lucide به صورت محلی (جایگزین نسخه CDN)
		wp_enqueue_script(
			'wc-sms-auth-lucide',
			WC_SMS_AUTH_PLUGIN_URL . 'assets/js/lucide.min.js',
			array(),
			WC_SMS_AUTH_VERSION,
			true
		);

		// اسکریپت اختصاصی مودال احراز هویت (منطق فرانت، Event Delegation و ارتباط با REST API).
		wp_enqueue_script(
			'wc-sms-auth-modal',
			WC_SMS_AUTH_PLUGIN_URL . 'assets/js/modal-auth.js',
			array( 'wc-sms-auth-lucide' ),
			WC_SMS_AUTH_VERSION,
			true
		);

		// انتقال مقادیر مورد نیاز بک‌اند (آدرس REST، نانس امنیتی، زمان تایمر
		// و وضعیت لاگین کاربر) به جاوااسکریپت فرانت.
		//
		// isLoggedIn/myAccountUrl: چون تشخیص لاگین بودن کاربر صرفاً سمت
		// جاوااسکریپت (مثلاً از روی کلاس body.logged-in) به منطق تریگر
		// باز شدن مودال (assets/js/modal-auth.js) متصل نبود، تا پیش از
		// این با کلیک روی هر دکمه‌ای با کلاس open-auth-modal، مودال ورود/
		// ثبت‌نام حتی برای کاربر از‌قبل‌واردشده هم باز می‌شد. با پاس دادن
		// این مقدار (بر پایه is_user_logged_in وردپرس، نه کلاس CSS)،
		// همان تابع Event Delegation می‌تواند کاربر لاگین‌شده را مستقیماً
		// به صفحه «حساب کاربری من» ووکامرس هدایت کند و اصلاً مودال را باز نکند.
		wp_localize_script(
			'wc-sms-auth-modal',
			'WCSMSAuthData',
			array(
				'restUrl'      => esc_url_raw( rest_url( WC_SMS_Auth_API::API_NAMESPACE . '/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'timerSeconds' => absint( get_option( 'wc_sms_auth_otp_timer_seconds', 120 ) ),
				'isLoggedIn'   => is_user_logged_in(),
				'myAccountUrl' => function_exists( 'wc_get_page_permalink' )
					? esc_url_raw( wc_get_page_permalink( 'myaccount' ) )
					: '',
			)
		);
	}

	/**
	 * تزریق مستقیم کدهای HTML مودال احراز هویت در فوتر سایت (هوک wp_footer).
	 *
	 * این مارک‌آپ عیناً از فایل مرجع طراحی (code_artifact-v2.html) گرفته
	 * شده است؛ با این تفاوت که دکمه تریگر دمو حذف شده، چون تریگر واقعی
	 * مودال در سایت، هر المانی با کلاس open-auth-modal خواهد بود (که
	 * توسط Event Delegation در assets/js/modal-auth.js مدیریت می‌شود)
	 * و اسکریپت دمو داخلی فایل مرجع نیز حذف شده تا با منطق واقعی
	 * modal-auth.js که در پرامپت‌های بعدی تکمیل می‌شود جایگزین گردد.
	 */
	public function render_modal_markup() {
		// خواندن تنظیمات داینامیک ذخیره‌شده در تب "تنظیمات عمومی" پنل ادمین (فاز ۲).
		$banner_image_id = absint( get_option( 'wc_sms_auth_banner_image_id', 0 ) );
		$banner_image_url = $banner_image_id
			? wp_get_attachment_image_url( $banner_image_id, 'large' )
			: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=80';

		// توجه: مقدار پیش‌فرض به‌صراحت به get_option پاس داده می‌شود، چون
		// فیلتر پیش‌فرض ثبت‌شده توسط register_setting فقط زمانی توسط
		// وردپرس فعال است که register_general_settings روی اکشن admin_init
		// اجرا شده باشد؛ در فرانت‌اند (که admin_init هرگز اجرا نمی‌شود) بدون
		// این مقدار پیش‌فرض صریح، عنوان/متن دکمه (تا قبل از اولین ذخیره
		// دستی تنظیمات توسط ادمین) خالی نمایش داده می‌شد.
		$login_title       = get_option( 'wc_sms_auth_login_title', __( 'ورود به سایت', 'wc-sms-auth' ) );
		$step1_button_text = get_option( 'wc_sms_auth_step1_button_text', __( 'ورود با کد یکبار مصرف', 'wc-sms-auth' ) );
		?>
		<!-- MODAL BACKDROP OVERLAY -->
		<div
			id="authModal"
			class="fixed inset-0 z-50 flex items-center justify-center p-4 md:p-8 bg-black/60 backdrop-blur-sm opacity-0 pointer-events-none transition-all duration-300 overflow-y-auto"
		>

			<!-- Main Container / Popup Wrapper -->
			<div class="relative w-full max-w-[1128px] min-h-0 h-auto md:h-[736px] bg-[#FFFDFA] rounded-[24px] shadow-[0_25px_60px_rgba(0,0,0,0.2)] overflow-hidden flex flex-col md:flex-row transition-all duration-500 ease-in-out transform scale-95 opacity-0">

				<!-- CLOSE BUTTON (Top Right - Primary Color) -->
				<button
					onclick="closeAuthModal()"
					class="wc-sms-auth-btn-primary wc-sms-auth-close-btn absolute top-2 right-2 md:top-4 md:right-4 z-50 !w-[35px] !h-[35px] !p-0 flex items-center justify-center bg-[#E7A439] hover:bg-[#cf902f] text-[#FDF6EB] rounded-full shadow-md transition-all duration-200"
					aria-label="بستن مودال"
				>
					<i data-lucide="x" class="w-5 h-5"></i>
				</button>

				<!-- RIGHT SIDE: Forms Container (Vertically & Horizontally Centered) -->
				<div class="w-full md:w-1/2 h-full flex flex-col justify-center items-center px-6 pb-6 pt-[100px] md:px-12 md:pb-12 md:pt-[72px] relative min-h-[480px] md:min-h-0 z-10">

					<!--
						تایتل‌های «ثبت نام در سایت» و «ورود به سایت» عمداً به‌صورت
						عنصر مستقل و خواهر (sibling) پیش از دیوهای #signupForm/#loginForm
						قرار گرفته‌اند، نه داخل آن‌ها؛ چون آن دیوها (و فرزندان‌شان مثل
						#signupStep1) از کلاس‌های transform (برای انیمیشن اسلاید) استفاده
						می‌کنند و طبق مشخصات CSS، هر عنصر با transform غیر از none خودش
						Containing Block فرزندان absolute می‌شود. اگر تایتل داخل آن‌ها
						می‌ماند، «top-[95px]» نسبت به همان جعبه‌ی سنتر/ترنسفورم‌شده
						محاسبه می‌شد (نه نسبت به کل کانتینر مودال) و در وسط فرم ظاهر
						می‌شد. با انتقال به این سطح (که transform ندارد)، «top-[95px]»
						دقیقاً نسبت به بالای همین کانتینر (که position: relative دارد)
						محاسبه می‌شود. نمایش/مخفی‌شدن هرکدام هماهنگ با فرم متناظرش توسط
						modal-auth.js مدیریت می‌شود.
					-->
				<h1 id="signupFormTitle" class="absolute top-[35px] md:top-[75px] left-0 right-0 pb-[20px] md:pb-[30px] text-2xl md:text-[28px] font-bold !text-[#E7A439] text-center">
					ثبت نام در سایت
				</h1>
				<h1 id="loginFormTitle" class="hidden absolute top-[35px] md:top-[75px] left-0 right-0 pb-[20px] md:pb-[30px] text-2xl md:text-[28px] font-bold !text-[#E7A439] text-center">
					<?php echo esc_html( $login_title ); ?>
				</h1>

					<!-- FORM 1: REGISTER (ثبت نام در سایت) -->
					<div id="signupForm" class="w-full max-w-[479px] mt-[70px] transition-all duration-500 transform translate-x-0 opacity-100 flex flex-col justify-center">

						<!-- STEP 1: Basic Information -->
						<div id="signupStep1" class="transition-all duration-300 transform translate-x-0 opacity-100 flex flex-col justify-center space-y-5">
							<div>
								<!-- Form Content -->
								<div class="space-y-5 mt-[20px]">
									<!-- Input 1: Full name -->
									<div class="flex flex-col space-y-[20.39px]">
										<label class="text-[#555555] text-[18px] font-medium mr-1" for="reg-name">نام و نام خانوادگی</label>
									<input
										type="text"
										id="reg-name"
										placeholder="هدیه"
										required
										class="w-full max-w-[479px] h-[48px] px-4 rounded-[8px] border border-gray-200 bg-transparent text-gray-800 outline-none transition-all placeholder-gray-400 focus:border-[#E7A439] focus:placeholder-[#E7A439]"
									>
									</div>

									<!-- Input 2: Phone Number -->
									<div class="flex flex-col space-y-[20.39px]">
										<label class="text-[#555555] text-[18px] font-medium mr-1" for="reg-phone">شماره تماس</label>
										<input
											type="tel"
											id="reg-phone"
											placeholder="۰۹۱۲۳۴۵۶۷۸۹"
											required
											class="w-full max-w-[479px] h-[48px] px-4 rounded-[8px] border border-gray-200 bg-transparent text-gray-800 outline-none transition-all placeholder-gray-400 focus:border-[#E7A439] focus:placeholder-[#E7A439] tracking-wider text-left"
										>
									</div>

									<!-- Remember Me Checkbox -->
									<div class="flex items-center pt-1 pb-2">
										<label class="relative !flex items-center cursor-pointer">
											<input type="checkbox" id="reg-remember" class="sr-only peer">
											<div class="w-5 h-5 bg-white border border-gray-300 rounded peer-checked:bg-[#E7A439] peer-checked:border-[#E7A439] transition-all flex items-center justify-center">
												<i data-lucide="check" class="text-[#FDF6EB] w-3.5 h-3.5 opacity-0 peer-checked:opacity-100 transition-opacity"></i>
											</div>
											<span class="mr-2 text-sm text-[#666666] select-none">مرا به خاطر بسپار</span>
										</label>
									</div>

									<!-- Buttons Stack -->
									<div class="flex flex-col space-y-4 max-w-[479px]">
										<!-- Button 1: Proceed with verification code -->
										<button
											type="button"
											onclick="goToRegisterStep2()"
											class="wc-sms-auth-btn-primary w-full h-[44px] bg-[#E7A439] hover:bg-[#cf902f] text-[#FDF6EB] font-medium text-base rounded-[2px] shadow-md transition-all duration-300 flex items-center justify-center"
										>
											<span>ثبت نام با کد تایید</span>
										</button>
									</div>
								</div>
							</div>

							<!-- Back to login footer -->
							<div class="mt-[24px] border-t border-gray-100 max-w-[479px] text-center">
								<button onclick="toggleForms('login')" class="wc-sms-auth-link-muted text-sm text-[#66614D] hover:text-[#4f4a3d] font-semibold transition-all">
									بازگشت به ورود
								</button>
							</div>
						</div>

						<!-- STEP 2: Phone Verification (تأیید تلفن) -->
						<div id="signupStep2" class="hidden transition-all duration-300 transform translate-x-12 opacity-0 flex flex-col justify-center space-y-5">
							<div>
								<!-- Centered Title, Primary Color, No Border -->
								<h1 class="text-2xl md:text-[28px] font-bold text-[#E7A439] text-center w-full block mb-8">
									تأیید تلفن
								</h1>

								<!-- Active verification feedback -->
								<div class="mb-6 p-4 bg-[#E7A439]/5 rounded-[8px] max-w-[479px] border border-[#E7A439]/20 flex items-start space-x-3 space-x-reverse">
									<i data-lucide="smartphone" class="text-[#E7A439] w-5 h-5 mt-0.5"></i>
									<div>
										<p class="text-sm text-[#555555] leading-relaxed">کد تایید یکبار مصرف به شماره زیر ارسال شد:</p>
										<p id="targetPhoneDisplay" class="text-base font-bold text-[#E7A439] mt-1 tracking-wider text-left"></p>
									</div>
								</div>

								<!-- Form Content Step 2 -->
								<form id="otpSubmitForm" class="space-y-4" onsubmit="handleVerificationSubmit(event)">
									<!-- Input: Verification Code with Placeholder "رمز عبور پیامکی" -->
									<div class="flex flex-col space-y-[20.39px]">
										<label class="text-[#555555] text-[18px] font-medium mr-1" for="reg-otp">رمز عبور پیامکی</label>
										<input
											type="text"
											id="reg-otp"
											placeholder="رمز عبور پیامکی"
											required
											class="w-full max-w-[479px] h-[48px] px-4 rounded-[8px] border border-gray-200 bg-transparent text-gray-800 outline-none transition-all placeholder-gray-400 focus:border-[#E7A439] focus:placeholder-[#E7A439] text-center tracking-widest font-semibold"
										>
									</div>

									<!-- Modern Countdown Timer Display & Resend Button -->
									<div class="flex items-center justify-between max-w-[479px] px-1 py-2 text-sm">
										<div class="text-[#555555] flex items-center">
											<i data-lucide="timer" class="w-4 h-4 ml-1.5 text-gray-400"></i>
											<span>زمان باقی‌مانده:</span>
											<span id="countdown-display" class="font-bold text-[#E7A439] mr-1.5 tracking-wider">02:00</span>
										</div>
										<button
											type="button"
											id="resend-code-btn"
											disabled
											onclick="resendOTPCode()"
											class="text-gray-400 font-semibold transition-all duration-300 flex items-center cursor-not-allowed hover:text-gray-500"
										>
											<i data-lucide="rotate-ccw" class="w-4 h-4 ml-1"></i>
											<span>ارسال مجدد کد</span>
										</button>
									</div>

									<!-- Buttons Stack Step 2 -->
									<div class="flex flex-col space-y-4 max-w-[479px] pt-2">
										<!-- Button 1: Verify OTP code -->
									<button
										type="submit"
										class="wc-sms-auth-btn-primary w-full h-[44px] bg-[#E7A439] hover:bg-[#cf902f] text-[#FDF6EB] font-medium text-base rounded-[2px] shadow-md transition-all duration-300 flex items-center justify-center space-x-2 space-x-reverse"
									>
										<i data-lucide="check" class="w-5 h-5"></i>
										<span>اعتبار سنجی</span>
									</button>

										<!-- Button 2: Go back to Step 1 and change phone -->
									<button
										type="button"
										onclick="goBackToRegisterStep1()"
										class="wc-sms-auth-btn-outline !mt-4 w-full h-[44px] border border-[#E7A439] hover:bg-[#E7A439]/5 text-[#E7A439] hover:text-[#66614D] font-medium text-base rounded-[2px] transition-all duration-300 flex items-center justify-center"
									>
										اصلاح شماره تماس
									</button>
									</div>
								</form>
							</div>

							<!-- Footer -->
							<div class="mt-[24px] border-t border-gray-100 max-w-[479px] text-center">
								<button onclick="toggleForms('login')" class="wc-sms-auth-link-muted text-sm text-[#66614D] hover:text-[#4f4a3d] font-semibold transition-all">
									انصراف و بازگشت به ورود
								</button>
							</div>
						</div>

					</div>

					<!-- FORM 2: LOGIN (ورود به سایت) -->
					<div id="loginForm" class="w-full max-w-[479px] mt-[70px] hidden transition-all duration-500 transform translate-x-12 opacity-0 flex flex-col justify-center space-y-5">
						<div>
							<!-- Form Content -->
							<form id="loginSubmitForm" class="space-y-5 mt-[20px]">
								<!-- Input 1: Phone Number -->
								<div class="flex flex-col space-y-[20.39px]">
									<label class="text-[#555555] text-[18px] font-medium mr-1" for="login-phone">شماره تماس</label>
									<input
										type="tel"
										id="login-phone"
										placeholder="۰۹۱۲۳۴۵۶۷۸۹"
										required
										class="w-full max-w-[479px] h-[48px] px-4 rounded-[8px] border border-gray-200 bg-transparent text-gray-800 outline-none transition-all placeholder-gray-400 focus:border-[#E7A439] focus:placeholder-[#E7A439] tracking-wider text-left"
									>
								</div>

								<!-- Remember Me Checkbox -->
								<div class="flex items-center pt-1 pb-2">
									<label class="relative !flex items-center cursor-pointer">
										<input type="checkbox" id="login-remember" class="sr-only peer">
										<div class="w-5 h-5 bg-white border border-gray-300 rounded peer-checked:bg-[#E7A439] peer-checked:border-[#E7A439] transition-all flex items-center justify-center">
											<i data-lucide="check" class="text-[#FDF6EB] w-3.5 h-3.5 opacity-0 peer-checked:opacity-100 transition-opacity"></i>
										</div>
										<span class="mr-2 text-sm text-[#666666] select-none">مرا به خاطر بسپار</span>
									</label>
								</div>

						<!-- Buttons Stack -->
						<div class="flex flex-col space-y-4 max-w-[479px]">
							<!-- Button 1: One-time code login -->
									<button
										type="submit"
										class="wc-sms-auth-btn-primary w-full h-[44px] bg-[#E7A439] hover:bg-[#cf902f] text-[#FDF6EB] font-medium text-base rounded-[2px] shadow-md transition-all duration-300 flex items-center justify-center"
									>
										<?php echo esc_html( $step1_button_text ); ?>
									</button>

									<!-- Button 2: Join site -->
									<button
										type="button"
										onclick="toggleForms('signup')"
										class="wc-sms-auth-btn-outline !mt-4 w-full h-[44px] border border-[#E7A439] hover:bg-[#E7A439]/5 text-[#E7A439] hover:text-[#66614D] font-medium text-base rounded-[2px] transition-all duration-300 flex items-center justify-center"
									>
										عضویت در سایت
									</button>
								</div>
							</form>
						</div>

						<!-- Spacer to keep symmetric height (بدون متن، فقط جهت تقارن ارتفاع با فرم ثبت‌نام) -->
						<div class="mt-[24px] border-t border-gray-100 max-w-[479px] text-center">
							<span class="text-sm text-gray-400">&nbsp;</span>
						</div>
					</div>

				</div>

				<!-- LEFT SIDE: Full Image Area (Constant across transitions) -->
				<div class="w-full md:w-1/2 h-[120px] md:h-full relative overflow-hidden bg-[#E7A439]">
					<img
						src="<?php echo esc_url( $banner_image_url ); ?>"
						alt="تصویر پس‌زمینه"
						class="!absolute !inset-0 !w-full !h-full !object-cover opacity-90 transition-transform duration-700 hover:scale-105"
						onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;100%&quot; height=&quot;100%&quot; viewBox=&quot;0 0 100 100&quot;><rect width=&quot;100%&quot; height=&quot;100%&quot; fill=&quot;%23E7A439&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; font-size=&quot;6&quot; fill=&quot;%23fff&quot; text-anchor=&quot;middle&quot;>خوش آمدید</text></svg>'"
					>
				</div>

			</div>

		</div>

		<!-- Beautiful Modern Toast Notification Box -->
		<div id="toast" class="fixed top-5 left-5 bg-emerald-600 text-white px-6 py-3.5 rounded-lg shadow-xl translate-y-[-100px] opacity-0 transition-all duration-500 ease-out z-[60] flex items-center space-x-3 space-x-reverse pointer-events-none">
			<div class="bg-white/20 p-1.5 rounded-full">
				<i data-lucide="check-circle" class="w-5 h-5 text-white"></i>
			</div>
			<span id="toastMessage" class="font-medium text-sm"></span>
		</div>
		<?php
	}
}

/**
 * تابع کمکی سراسری برای دسترسی سریع به نمونه اصلی افزونه.
 *
 * @return WC_SMS_Auth_Modal_Main
 */
function wc_sms_auth_modal() {
	return WC_SMS_Auth_Modal_Main::get_instance();
}

// نمونه‌سازی هسته اصلی افزونه پس از بارگذاری کامل تمام افزونه‌های فعال.
add_action( 'plugins_loaded', 'wc_sms_auth_modal' );
