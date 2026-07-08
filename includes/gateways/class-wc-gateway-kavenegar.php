<?php
/**
 * پروایدر وب‌سرویس کاوه‌نگار (Kavenegar).
 *
 * این کلاس با استفاده از متد رسمی Verify Lookup کاوه‌نگار
 * (مخصوص ارسال پیامک‌های الگو/پترن مانند کد یکبار مصرف)، کدهای OTP را
 * برای کاربران ارسال می‌کند.
 *
 * @see https://kavenegar.com/rest.html متد Verify - Lookup
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس درگاه پیامکی کاوه‌نگار.
 */
class WC_Gateway_Kavenegar implements WC_SMS_Gateway_Interface {

	/**
	 * آدرس پایه REST API کاوه‌نگار.
	 *
	 * @var string
	 */
	const API_BASE_URL = 'https://api.kavenegar.com/v1/';

	/**
	 * کلید API اختصاصی حساب کاربری کاوه‌نگار.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * شماره خط ارسال‌کننده (در بیشتر موارد توسط خودِ الگوی Verify در پنل کاوه‌نگار مدیریت می‌شود).
	 *
	 * @var string
	 */
	private $sender_number;

	/**
	 * سازنده کلاس؛ خواندن تنظیمات ذخیره‌شده درگاه از Options API.
	 */
	public function __construct() {
		$this->api_key       = get_option( 'wc_sms_auth_kavenegar_api_key', '' );
		$this->sender_number = get_option( 'wc_sms_auth_kavenegar_sender_number', '' );
	}

	/**
	 * ارسال پیامک کد یکبار مصرف از طریق متد Verify Lookup کاوه‌نگار.
	 *
	 * @param string $phone      شماره موبایل مقصد (Receptor).
	 * @param string $code       کد یکبار مصرف تولید شده (Token).
	 * @param string $pattern_id نام الگوی از پیش تاییدشده (Template)؛ در صورت خالی بودن، از تنظیمات ذخیره‌شده خوانده می‌شود.
	 *
	 * @return true|WP_Error
	 */
	public function send_otp( $phone, $code, $pattern_id ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_api_key',
				__( 'کلید API درگاه کاوه‌نگار تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		if ( empty( $pattern_id ) ) {
			$pattern_id = get_option( 'wc_sms_auth_kavenegar_pattern_code', '' );
		}

		if ( empty( $pattern_id ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_pattern',
				__( 'نام الگوی (Template) درگاه کاوه‌نگار تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		$endpoint = self::API_BASE_URL . rawurlencode( $this->api_key ) . '/verify/lookup.json';

		// طبق مستندات کاوه‌نگار، پارامترهای متد Verify/Lookup شامل receptor، token و template است.
		$request_args = array(
			'receptor' => $phone,
			'token'    => $code,
			'template' => $pattern_id,
			'type'     => 'sms',
		);

		$request_url = add_query_arg( $request_args, $endpoint );

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );

		$gateway_status = isset( $response_data['return']['status'] ) ? (int) $response_data['return']['status'] : 0;
		$is_success     = ( 200 === (int) $response_code ) && ( 200 === $gateway_status );

		if ( ! $is_success ) {
			$error_message = ! empty( $response_data['return']['message'] )
				? $response_data['return']['message']
				: __( 'خطای نامشخص در ارسال پیامک از درگاه کاوه‌نگار.', 'wc-sms-auth' );

			return new WP_Error( 'wc_sms_auth_gateway_error', $error_message, $response_data );
		}

		return true;
	}
}
