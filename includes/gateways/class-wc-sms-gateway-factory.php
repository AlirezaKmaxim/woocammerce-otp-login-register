<?php
/**
 * کارخانه مدیریت و سوئیچینگ داینامیک بین درگاه‌های پیامکی.
 *
 * این کلاس با پیاده‌سازی دیزاین پترن Factory، از هاردکد شدن نام کلاس
 * پروایدر پیامکی در کدهای مصرف‌کننده (مانند کنترلر REST API) جلوگیری
 * می‌کند و بر اساس تنظیم ذخیره‌شده در پیشخوان، نمونه درگاه فعال را
 * به صورت داینامیک می‌سازد و بازمی‌گرداند.
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس فکتوری درگاه‌های پیامکی.
 */
final class WC_SMS_Gateway_Factory {

	/**
	 * نگاشت کلید درگاه (ذخیره‌شده در آپشن wc_sms_auth_active_gateway) به نام کلاس پروایدر متناظر.
	 *
	 * @var array
	 */
	private static $gateway_map = array(
		'farazsms'     => 'WC_Gateway_FarazSMS',
		'kavenegar'    => 'WC_Gateway_Kavenegar',
		'mellipayamak' => 'WC_Gateway_MelliPayamak',
		'smsir'        => 'WC_Gateway_SmsIr',
	);

	/**
	 * سازنده خصوصی؛ این کلاس صرفاً یک Static Factory است و نیازی به نمونه‌سازی ندارد.
	 */
	private function __construct() {}

	/**
	 * ساخت و بازگرداندن نمونه درگاه پیامکی فعال بر اساس تنظیمات ادمین.
	 *
	 * @return WC_SMS_Gateway_Interface|WP_Error نمونه درگاه فعال، یا WP_Error در صورت نامعتبر بودن تنظیمات.
	 */
	public static function get_active_gateway() {
		$active_gateway_key = get_option( 'wc_sms_auth_active_gateway', 'farazsms' );

		if ( ! array_key_exists( $active_gateway_key, self::$gateway_map ) ) {
			return new WP_Error(
				'wc_sms_auth_invalid_gateway',
				__( 'درگاه پیامکی انتخاب‌شده در تنظیمات نامعتبر است.', 'wc-sms-auth' )
			);
		}

		$gateway_class_name = self::$gateway_map[ $active_gateway_key ];

		// فراخوانی class_exists با پارامتر autoload=true باعث فراخوانی خودکار Autoloader
		// و بارگذاری تنها فایل کلاس درگاه فعال می‌شود (نه همه درگاه‌ها).
		if ( ! class_exists( $gateway_class_name, true ) ) {
			return new WP_Error(
				'wc_sms_auth_gateway_class_missing',
				sprintf(
					/* translators: %s: نام کلاس درگاه پیامکی. */
					__( 'کلاس درگاه پیامکی %s یافت نشد.', 'wc-sms-auth' ),
					$gateway_class_name
				)
			);
		}

		$gateway_instance = new $gateway_class_name();

		if ( ! ( $gateway_instance instanceof WC_SMS_Gateway_Interface ) ) {
			return new WP_Error(
				'wc_sms_auth_gateway_interface_mismatch',
				sprintf(
					/* translators: %s: نام کلاس درگاه پیامکی. */
					__( 'کلاس درگاه پیامکی %s اینترفیس لازم (WC_SMS_Gateway_Interface) را پیاده‌سازی نکرده است.', 'wc-sms-auth' ),
					$gateway_class_name
				)
			);
		}

		return $gateway_instance;
	}
}
