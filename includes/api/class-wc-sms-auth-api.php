<?php
/**
 * کنترلر ثبت روت‌ها و اندپوینت‌های REST API افزونه.
 *
 * تمام ارتباط فرانت‌اند (مودال ورود/ثبت‌نام) با بک‌اند از طریق همین
 * کنترلر و در قالب اندپوینت‌های WP REST API انجام می‌شود؛ اندپوینت‌های
 * ارسال کد (/send-otp) و تایید کد (/verify-otp) در پرامپت‌های بعدی
 * فاز ۴ به متد register_routes اضافه خواهند شد.
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس کنترلر REST API افزونه.
 */
class WC_SMS_Auth_API {

	/**
	 * نیم‌اسپیس اختصاصی روت‌های REST API این افزونه.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'wc-sms-auth/v1';

	/**
	 * پیشوند کلید Transient ذخیره کد OTP هر شماره موبایل (کلید نهایی
	 * به شکل wc_sms_auth_otp_09123456789 خواهد بود). این پیشوند
	 * اختصاصی افزونه، معادل امن‌تر و بدون تصادم الگوی otp_{شماره}
	 * است تا با Transient سایر افزونه‌ها تداخل پیدا نکند.
	 *
	 * @var string
	 */
	const OTP_TRANSIENT_PREFIX = 'wc_sms_auth_otp_';

	/**
	 * مدت زمان (ثانیه) انقضای کوکی احراز هویت وقتی کاربر گزینه «مرا به
	 * خاطر بسپار» را فعال کرده باشد. پیش‌فرض خودِ وردپرس برای حالت
	 * Remember Me، ۱۴ روز (۲ هفته) است؛ این مقدار را طبق نیاز پروژه به
	 * یک ماه (MONTH_IN_SECONDS) افزایش می‌دهیم.
	 *
	 * @var int
	 */
	const REMEMBER_ME_COOKIE_EXPIRATION = MONTH_IN_SECONDS;

	/**
	 * پیشوند کلید Transient شمارنده محدودیت نرخ درخواست (Rate Limiting)
	 * اندپوینت /send-otp، به ازای هر ترکیب یکتای IP و شماره موبایل.
	 *
	 * @var string
	 */
	const RATE_LIMIT_TRANSIENT_PREFIX = 'wc_sms_auth_rl_';

	/**
	 * حداکثر تعداد درخواست ارسال OTP مجاز، به ازای هر ترکیب IP+شماره
	 * موبایل، در بازه زمانی RATE_LIMIT_WINDOW_SECONDS.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX_REQUESTS = 3;

	/**
	 * طول بازه زمانی (ثانیه) پنجره محدودیت نرخ درخواست؛ ۱۰ دقیقه.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW_SECONDS = 600;

	/**
	 * سازنده کلاس؛ اتصال ثبت روت‌ها به هوک rest_api_init وردپرس، و
	 * فیلتر انقضای کوکی احراز هویت جهت پشتیبانی از «مرا به خاطر بسپار».
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'auth_cookie_expiration', array( $this, 'filter_remember_me_cookie_expiration' ), 10, 3 );
	}

	/**
	 * فیلتر مدت انقضای کوکی احراز هویت وردپرس (auth_cookie_expiration).
	 *
	 * وردپرس این فیلتر را از داخل wp_set_auth_cookie() با پارامتر سوم
	 * $remember فراخوانی می‌کند؛ اگر کاربر «مرا به خاطر بسپار» را فعال
	 * کرده باشد، مدت انقضای پیش‌فرض (معمولاً ۱۴ روز) را به مقدار
	 * اختصاصی افزونه (یک ماه) افزایش می‌دهیم، در غیر این صورت مقدار
	 * پیش‌فرض وردپرس بدون تغییر باقی می‌ماند.
	 *
	 * @param int  $expiration مدت انقضای پیش‌فرض (ثانیه) که وردپرس محاسبه کرده.
	 * @param int  $user_id    شناسه کاربری که کوکی برایش صادر می‌شود.
	 * @param bool $remember   مقدار Remember Me ارسالی به wp_set_auth_cookie.
	 * @return int مدت انقضای نهایی (ثانیه).
	 */
	public function filter_remember_me_cookie_expiration( $expiration, $user_id, $remember ) {
		if ( $remember ) {
			return self::REMEMBER_ME_COOKIE_EXPIRATION;
		}

		return $expiration;
	}

	/**
	 * ثبت روت‌های REST API افزونه در نیم‌اسپیس اختصاصی wc-sms-auth/v1.
	 *
	 * ولیدیشن ساختار ورودی‌ها و محدودیت نرخ درخواست (Rate Limiting) هر
	 * دو اندپوینت، در پرامپت‌های بعدی فاز ۴ اضافه می‌شود.
	 */
	public function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/send-otp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_send_otp' ),
				'permission_callback' => array( $this, 'verify_nonce_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/verify-otp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_verify_otp' ),
				'permission_callback' => array( $this, 'verify_nonce_permission' ),
			)
		);
	}

	/**
	 * اعتبارسنجی امنیتی مشترک (permission_callback) هر دو اندپوینت.
	 *
	 * توکن امنیتی ارسالی در هدر X-WP-Nonce درخواست را با wp_verify_nonce
	 * در برابر اکشن استاندارد wp_rest اعتبارسنجی می‌کند تا از حملات CSRF
	 * روی اندپوینت‌های احراز هویت جلوگیری شود (مطابق مشخصات امنیتی معماری افزونه).
	 *
	 * @param WP_REST_Request $request شیء درخواست REST دریافتی.
	 * @return true|WP_Error نتیجه true در صورت معتبر بودن نانس، یا WP_Error با کد وضعیت ۴۰۳.
	 */
	public function verify_nonce_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'wc_sms_auth_invalid_nonce',
				__( 'توکن امنیتی درخواست نامعتبر یا منقضی شده است.', 'wc-sms-auth' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * کال‌بک اندپوینت POST /send-otp.
	 *
	 * منطق کامل: ولیدیشن شماره موبایل، بررسی محدودیت نرخ درخواست
	 * (Rate Limiting) بر پایه IP+شماره موبایل، تولید/ذخیره‌سازی کد OTP
	 * در Transient، دریافت درگاه پیامکی فعال از طریق WC_SMS_Gateway_Factory
	 * و ارسال واقعی پیامک.
	 *
	 * @param WP_REST_Request $request شیء درخواست REST دریافتی.
	 * @return WP_REST_Response
	 */
	public function handle_send_otp( WP_REST_Request $request ) {
		$phone  = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$action = sanitize_text_field( (string) $request->get_param( 'action' ) );

		if ( ! $this->is_valid_iranian_mobile_number( $phone ) ) {
			return $this->format_error_response(
				__( 'شماره موبایل وارد شده معتبر نیست. لطفاً شماره را به فرمت صحیح موبایل ایران (مانند 09123456789) و بدون فاصله یا کاراکتر اضافه وارد نمایید.', 'wc-sms-auth' ),
				422
			);
		}

		if ( 'register' === $action && $this->user_exists_by_phone( $phone ) ) {
			return $this->format_error_response(
				__( 'این شماره موبایل قبلاً در سایت ثبت شده است. لطفاً از بخش ورود وارد شوید.', 'wc-sms-auth' ),
				409
			);
		}

		// لایه امنیتی ضد بمب‌باران پیامکی: قبل از هر پردازش/هزینه واقعی
		// ارسال پیامک، تعداد درخواست‌های اخیر همین ترکیب IP+شماره موبایل
		// بررسی می‌شود تا از سوءاستفاده (اسپم کردن یک شماره با پیامک، یا
		// تخلیه اعتبار پنل درگاه پیامکی) جلوگیری شود.
		if ( $this->is_send_otp_rate_limited( $phone ) ) {
			return $this->format_error_response(
				__( 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً حدود ۱۰ دقیقه دیگر دوباره تلاش نمایید.', 'wc-sms-auth' ),
				429
			);
		}

		$otp_code = $this->generate_and_store_otp_code( $phone );

		$gateway = WC_SMS_Gateway_Factory::get_active_gateway();

		if ( is_wp_error( $gateway ) ) {
			return $this->format_error_response( $gateway->get_error_message(), 500 );
		}

		// پارامتر سوم (pattern_id) خالی پاس داده می‌شود تا هر کلاس درگاه،
		// طبق منطق داخلی خودش، کد پترن اختصاصی ارسال OTP را از تنظیمات
		// ذخیره‌شده در پیشخوان بخواند (همان الگویی که در دکمه تست پیامک
		// ادمین - handle_test_sms_ajax - نیز استفاده شده است).
		$send_result = $gateway->send_otp( $phone, $otp_code, '' );

		if ( is_wp_error( $send_result ) ) {
			return $this->format_error_response( $send_result->get_error_message(), 500 );
		}

		return $this->format_success_response(
			array(
				'message' => __( 'کد تایید با موفقیت برای شما ارسال شد.', 'wc-sms-auth' ),
			)
		);
	}

	/**
	 * بررسی می‌کند که شماره موبایل ورودی دقیقاً مطابق ساختار موبایل ایران باشد:
	 * ۱۱ رقم انگلیسی، بدون فاصله/خط‌تیره/پیش‌شماره کشور، و شروع‌شده با «09».
	 *
	 * @param string $phone شماره موبایل (پس از sanitize_text_field).
	 * @return bool
	 */
	private function is_valid_iranian_mobile_number( $phone ) {
		if ( empty( $phone ) ) {
			return false;
		}

		return (bool) preg_match( '/^09\d{9}$/', $phone );
	}

	/**
	 * بررسی می‌کند که آیا کاربری با این شماره موبایل در متای billing_phone وجود دارد یا خیر.
	 *
	 * @param string $phone شماره موبایل معتبرشده.
	 * @return bool
	 */
	private function user_exists_by_phone( $phone ) {
		$existing_user_query = new WP_User_Query(
			array(
				'meta_key'   => 'billing_phone',
				'meta_value' => $phone,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		$existing_user_ids = $existing_user_query->get_results();

		return ! empty( $existing_user_ids );
	}

	/**
	 * بررسی محدودیت نرخ درخواست اندپوینت /send-otp بر پایه ترکیب یکتای
	 * IP کاربر و شماره موبایل درخواستی. اگر تعداد درخواست‌های موفق ثبت‌شده
	 * در بازه RATE_LIMIT_WINDOW_SECONDS اخیر (پیش‌فرض ۱۰ دقیقه) به سقف
	 * RATE_LIMIT_MAX_REQUESTS (پیش‌فرض ۳) رسیده باشد، true برمی‌گرداند
	 * و درخواست جدید ثبت نمی‌شود؛ در غیر این صورت، درخواست جاری هم به
	 * فهرست زمان‌های ثبت‌شده اضافه شده و false برگردانده می‌شود.
	 *
	 * زمان‌های هر تلاش (نه فقط یک شمارنده ساده عددی) در Transient نگه‌داری
	 * می‌شود تا یک پنجره زمانی «غلتان» (Sliding Window) واقعی پیاده‌سازی
	 * شود؛ یعنی همیشه دقیقاً «۳ درخواست در هر ۱۰ دقیقه اخیر» بررسی
	 * می‌شود، نه یک بازه ثابتی که با هر درخواست جدید از نو شمارش شود.
	 *
	 * @param string $phone شماره موبایل معتبرشده درخواستی.
	 * @return bool true اگر درخواست باید به دلیل عبور از سقف مجاز رد شود.
	 */
	private function is_send_otp_rate_limited( $phone ) {
		$rate_limit_key = self::RATE_LIMIT_TRANSIENT_PREFIX . md5( $this->get_client_ip() . '|' . $phone );

		$recent_attempts = get_transient( $rate_limit_key );

		if ( ! is_array( $recent_attempts ) ) {
			$recent_attempts = array();
		}

		$window_start_time = time() - self::RATE_LIMIT_WINDOW_SECONDS;

		// حذف تلاش‌های قدیمی‌تر از شروع بازه زمانی جاری، تا فقط تلاش‌های
		// واقعاً «اخیر» در محاسبه سقف مجاز لحاظ شوند.
		$recent_attempts = array_values(
			array_filter(
				$recent_attempts,
				function ( $attempt_time ) use ( $window_start_time ) {
					return $attempt_time >= $window_start_time;
				}
			)
		);

		if ( count( $recent_attempts ) >= self::RATE_LIMIT_MAX_REQUESTS ) {
			return true;
		}

		$recent_attempts[] = time();

		set_transient( $rate_limit_key, $recent_attempts, self::RATE_LIMIT_WINDOW_SECONDS );

		return false;
	}

	/**
	 * دریافت آدرس IP کاربر درخواست‌دهنده، جهت استفاده در کلید محدودیت
	 * نرخ درخواست. فقط REMOTE_ADDR (که توسط خودِ وب‌سرور و نه کاربر
	 * تعیین می‌شود) در نظر گرفته می‌شود، نه هدرهایی مثل X-Forwarded-For
	 * که به‌راحتی توسط کلاینت قابل جعل هستند و بدون تنظیم صریح پروکسی
	 * مورد اعتماد، استفاده از آن‌ها یک ریسک امنیتی (دور زدن Rate Limit) است.
	 *
	 * @return string آدرس IP کاربر، یا رشته خالی در صورت عدم دسترس بودن.
	 */
	private function get_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		return filter_var( $ip_address, FILTER_VALIDATE_IP ) ? $ip_address : '';
	}

	/**
	 * تولید یک کد یکبار مصرف ۵ رقمی تصادفی و ذخیره آن در یک Transient
	 * اختصاصی به ازای شماره موبایل کاربر، به مدت زمانی که ادمین در تب
	 * «تنظیمات عمومی» پنل تحت عنوان زمان انقضای تایمر مشخص کرده است.
	 *
	 * ذخیره بر پایه Transient (به‌جای مثلاً یک جدول یا آپشن مجزا) انتخاب
	 * شده چون خودِ هسته وردپرس انقضای خودکار آن را پس از گذشت مدت زمان
	 * تعیین‌شده مدیریت می‌کند و نیازی به Cron یا پاکسازی دستی نیست.
	 *
	 * @param string $phone شماره موبایل معتبرشده (خروجی is_valid_iranian_mobile_number).
	 * @return string کد OTP ۵ رقمی تولید و ذخیره‌شده.
	 */
	private function generate_and_store_otp_code( $phone ) {
		// wp_rand به‌جای rand/mt_rand استفاده می‌شود چون وردپرس آن را با
		// منبع تصادفی امن‌تر (در صورت وجود) پیاده‌سازی کرده است.
		$otp_code = (string) wp_rand( 10000, 99999 );

		$expiration_seconds = absint( get_option( 'wc_sms_auth_otp_timer_seconds', 120 ) );

		set_transient( self::OTP_TRANSIENT_PREFIX . $phone, $otp_code, $expiration_seconds );

		return $otp_code;
	}

	/**
	 * کال‌بک اندپوینت POST /verify-otp.
	 *
	 * منطق کامل: ولیدیشن شماره و کد ورودی، مقایسه کد با مقدار ذخیره‌شده
	 * در Transient (خروجی generate_and_store_otp_code در handle_send_otp)،
	 * بازگرداندن خطای متمایز برای هر حالت (کد منقضی/یافت‌نشده در برابر
	 * کد اشتباه)، جستجو/ثبت‌نام کاربر بر پایه شماره موبایل، اتصال
	 * سفارش‌های مهمان قبلی، و در نهایت ورود عملی کاربر با
	 * wp_set_auth_cookie (با رعایت گزینه «مرا به خاطر بسپار» ارسالی از فرانت).
	 *
	 * @param WP_REST_Request $request شیء درخواست REST دریافتی؛ شامل ورودی‌های شماره موبایل، کد تایید و remember.
	 * @return WP_REST_Response
	 */
	public function handle_verify_otp( WP_REST_Request $request ) {
		$phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );

		if ( ! $this->is_valid_iranian_mobile_number( $phone ) ) {
			return $this->format_error_response(
				__( 'شماره موبایل وارد شده معتبر نیست. لطفاً فرآیند را از ابتدا آغاز کنید.', 'wc-sms-auth' ),
				422
			);
		}

		if ( empty( $code ) ) {
			return $this->format_error_response(
				__( 'لطفاً کد تایید ارسال‌شده را وارد نمایید.', 'wc-sms-auth' ),
				422
			);
		}

		$stored_code = get_transient( self::OTP_TRANSIENT_PREFIX . $phone );

		// حالت اول (متمایز): هیچ کدی برای این شماره ذخیره نشده یا Transient
		// از قبل منقضی شده است؛ این با حالت «کد اشتباه» کاملاً فرق دارد،
		// چون کاربر باید دوباره از دکمه «ارسال مجدد کد» کد جدید بگیرد.
		if ( false === $stored_code ) {
			return $this->format_error_response(
				__( 'کد تایید منقضی شده یا برای این شماره درخواستی ثبت نشده است. لطفاً با استفاده از دکمه «ارسال مجدد کد» یک کد جدید درخواست نمایید.', 'wc-sms-auth' ),
				410
			);
		}

		// حالت دوم (متمایز): Transient هنوز معتبر است اما کد وارد شده با
		// مقدار ذخیره‌شده مطابقت ندارد.
		if ( ! hash_equals( (string) $stored_code, $code ) ) {
			return $this->format_error_response(
				__( 'کد تایید وارد شده صحیح نیست. لطفاً دوباره بررسی و تلاش نمایید.', 'wc-sms-auth' ),
				401
			);
		}

		// حذف فوری Transient پس از تایید موفق، تا از استفاده مجدد همان
		// کد (حمله Replay) در صورت افشای احتمالی آن جلوگیری شود.
		delete_transient( self::OTP_TRANSIENT_PREFIX . $phone );

		$user_id = $this->find_or_create_user_by_phone( $phone );

		if ( is_wp_error( $user_id ) ) {
			return $this->format_error_response( $user_id->get_error_message(), 500 );
		}

		// مقدار چک‌باکس «مرا به خاطر بسپار» که فرانت از مرحله اول فرم
		// (ثبت‌نام/ورود) همراه درخواست تایید کد ارسال می‌کند.
		$remember = rest_sanitize_boolean( $request->get_param( 'remember' ) );

		$this->log_user_in( $user_id, $remember );

		// آدرس صفحه «حساب کاربری من» ووکامرس، جهت هدایت نهایی کاربر در
		// فرانت پس از ورود موفق (به‌جای هاردکد کردن یک مسیر ثابت، از
		// تابع اختصاصی ووکامرس استفاده می‌شود تا با صفحه واقعی
		// انتخاب‌شده در تنظیمات ووکامرس - WooCommerce > Settings > Advanced -
		// همیشه هماهنگ بماند).
		$redirect_url = wc_get_page_permalink( 'myaccount' );

		return $this->format_success_response(
			array(
				'message'     => __( 'شماره موبایل شما با موفقیت تایید شد.', 'wc-sms-auth' ),
				'phone'       => $phone,
				'user_id'     => $user_id,
				'redirectUrl' => $redirect_url ? esc_url_raw( $redirect_url ) : '',
			)
		);
	}

	/**
	 * ورود عملی کاربر به وردپرس پس از تایید موفق کد OTP.
	 *
	 * علاوه بر wp_set_auth_cookie (که خودِ کوکی احراز هویت مرورگر را
	 * صادر می‌کند)، wp_set_current_user نیز صدا زده می‌شود تا کاربر
	 * جاری بلافاصله در همین چرخه اجرای درخواست هم لاگین‌شده در نظر
	 * گرفته شود، و اکشن استاندارد wp_login (که wp_signon هم آن را در
	 * فرآیند لاگین معمولی وردپرس اجرا می‌کند) شلیک می‌شود تا سایر
	 * افزونه‌ها (از جمله خودِ ووکامرس، برای ادغام سبد خرید/سشن) به‌درستی
	 * از این رویداد ورود مطلع شوند.
	 *
	 * @param int  $user_id  شناسه کاربر تایید‌شده.
	 * @param bool $remember مقدار «مرا به خاطر بسپار»؛ true یعنی طول عمر کوکی باید افزایش یابد
	 *                       (طبق فیلتر filter_remember_me_cookie_expiration).
	 */
	private function log_user_in( $user_id, $remember ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember );

		/** @var WP_User $user */
		$user = get_userdata( $user_id );

		if ( $user ) {
			do_action( 'wp_login', $user->user_login, $user );
		}
	}

	/**
	 * جستجوی کاربر موجود بر اساس شماره موبایل ذخیره‌شده در متای
	 * billing_phone (متای استاندارد ووکامرس)، یا در صورت عدم وجود،
	 * ثبت‌نام خودکار یک کاربر جدید با نقش «مشتری» (customer) ووکامرس.
	 *
	 * چون این افزونه یک سیستم احراز هویت مستقل و بدون رمز عبور (Passwordless)
	 * است، کاربر هیچ‌گاه نیازی به وارد کردن نام کاربری/ایمیل/رمز عبور
	 * ندارد؛ این مقادیر صرفاً جهت رعایت الزامات ساختاری wp_insert_user
	 * به‌صورت تصادفی و یکتا تولید می‌شوند.
	 *
	 * @param string $phone شماره موبایل معتبرشده کاربر.
	 * @return int|WP_Error شناسه عددی کاربر موجود/تازه‌ایجادشده، یا WP_Error در صورت خطای ثبت‌نام.
	 */
	private function find_or_create_user_by_phone( $phone ) {
		$existing_user_query = new WP_User_Query(
			array(
				'meta_key'   => 'billing_phone',
				'meta_value' => $phone,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		$existing_user_ids = $existing_user_query->get_results();

		if ( ! empty( $existing_user_ids ) ) {
			return (int) $existing_user_ids[0];
		}

		// تولید نام کاربری یکتا با الگوی «کاربر [عدد تصادفی] [نام سایت]»
		// (مثال: «کاربر 49 سلامت فراور»)؛ در صورت برخورد تصادفی با نام
		// کاربری موجود (بسیار نامحتمل)، تا سقف چند تلاش، عدد تصادفی جدیدی
		// تولید و دوباره امتحان می‌شود.
		$username  = '';
		$site_name = get_bloginfo( 'name' );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate_username = sprintf(
				/* translators: 1: عدد تصادفی یکتاساز، 2: نام سایت. */
				__( 'کاربر %1$d %2$s', 'wc-sms-auth' ),
				wp_rand( 1, 999999 ),
				$site_name
			);

			if ( ! username_exists( $candidate_username ) ) {
				$username = $candidate_username;
				break;
			}
		}

		if ( empty( $username ) ) {
			return new WP_Error(
				'wc_sms_auth_username_generation_failed',
				__( 'امکان تولید نام کاربری یکتا برای ثبت‌نام خودکار وجود نداشت. لطفاً دوباره تلاش کنید.', 'wc-sms-auth' )
			);
		}

		// چون کاربر ایمیلی وارد نکرده، یک ایمیل ساختگی اما یکتا (بر پایه
		// شماره موبایل و دامنه سایت) صرفاً جهت عبور از اعتبارسنجی یکتایی
		// ایمیل در wp_insert_user تولید می‌شود؛ به این آدرس هرگز پیامی
		// ارسال نخواهد شد.
		$site_domain    = wp_parse_url( home_url(), PHP_URL_HOST );
		$fallback_email = $phone . '@' . ( ! empty( $site_domain ) ? $site_domain : 'wc-sms-auth.invalid' );

		$new_user_id = wp_insert_user(
			array(
				'user_login' => $username,
				'user_pass'  => wp_generate_password( 24, true, true ),
				'user_email' => $fallback_email,
				'role'       => 'customer',
			)
		);

		if ( is_wp_error( $new_user_id ) ) {
			return $new_user_id;
		}

		update_user_meta( $new_user_id, 'billing_phone', $phone );

		// چون این کاربر تازه ثبت‌نام کرده، ممکن است پیش‌تر (پیش از ساخت
		// حساب) به‌صورت مهمان (Guest) با همین شماره موبایل چند سفارش در
		// وکامرس ثبت کرده باشد؛ این سفارش‌ها به حساب تازه‌ساخته‌شده متصل
		// می‌شوند تا سابقه خریدش یکپارچه/هماهنگ شود.
		$this->link_guest_orders_to_user( $phone, $new_user_id );

		return (int) $new_user_id;
	}

	/**
	 * جستجوی تمام سفارش‌های مهمان (Guest Orders - سفارش‌هایی که هیچ
	 * کاربر عضوی به آن‌ها متصل نیست، customer_id برابر ۰) که با شماره
	 * موبایل ورودی در فیلد billing_phone ثبت شده‌اند، و اتصال آن‌ها به
	 * شناسه کاربر تازه‌ثبت‌نام‌شده (متای _customer_user هر سفارش).
	 *
	 * از wc_get_orders (به‌جای کوئری مستقیم روی جدول پست‌ها) استفاده
	 * شده تا با هر دو حالت ذخیره‌سازی سفارش ووکامرس (جدول‌های سفارش
	 * اختصاصی HPOS و روش سنتی مبتنی بر Custom Post Type) به‌صورت
	 * یکسان و سازگار کار کند.
	 *
	 * @param string $phone   شماره موبایل معتبرشده کاربر.
	 * @param int    $user_id شناسه کاربر تازه‌ثبت‌نام‌شده جهت اتصال سفارش‌ها به آن.
	 */
	private function link_guest_orders_to_user( $phone, $user_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$guest_order_ids = wc_get_orders(
			array(
				'customer_id' => 0,
				'meta_key'    => '_billing_phone',
				'meta_value'  => $phone,
				'limit'       => -1,
				'return'      => 'ids',
			)
		);

		if ( empty( $guest_order_ids ) ) {
			return;
		}

		foreach ( $guest_order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$order->set_customer_id( $user_id );
			$order->save();
		}
	}

	/**
	 * ساخت پاسخ استاندارد موفق با ساختار یکسان { "success": true, "data": [...] }.
	 *
	 * @param mixed $data داده‌های خروجی که باید به فرانت‌اند بازگردانده شود.
	 * @param int   $status کد وضعیت HTTP پاسخ (پیش‌فرض ۲۰۰).
	 * @return WP_REST_Response
	 */
	private function format_success_response( $data = array(), $status = 200 ) {
		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);

		$response->set_status( $status );

		return $response;
	}

	/**
	 * ساخت پاسخ استاندارد خطا با ساختار یکسان { "success": false, "message": "..." }.
	 *
	 * @param string $message متن خطا جهت نمایش به کاربر/توسعه‌دهنده فرانت‌اند.
	 * @param int    $status  کد وضعیت HTTP پاسخ (پیش‌فرض ۴۰۰).
	 * @return WP_REST_Response
	 */
	private function format_error_response( $message, $status = 400 ) {
		$response = rest_ensure_response(
			array(
				'success' => false,
				'message' => $message,
			)
		);

		$response->set_status( $status );

		return $response;
	}
}
