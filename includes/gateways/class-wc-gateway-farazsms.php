<?php
/**
 * پروایدر وب‌سرویس فراز اس‌ام‌اس (Iran Payamak).
 *
 * این کلاس بر اساس مستندات رسمی ارسال‌شده توسط پشتیبانی فراز اس‌ام‌اس
 * (وب‌سرویس نسخه ۱ - https://api.iranpayamak.com/ws/v1) و با استفاده از
 * اندپوینت ارسال پترن، کدهای یکبار مصرف را برای کاربران پیامک می‌کند.
 *
 * نمونه cURL مرجع (ارائه‌شده توسط پشتیبانی فراز اس‌ام‌اس):
 *
 * curl --location --request POST 'https://api.iranpayamak.com/ws/v1/sms/pattern' \
 * --header 'Accept: application/json' \
 * --header 'Content-Type: application/json' \
 * --header 'Api-Key: **********' \
 * --data-raw '{
 *   "code": "SJ3FgPrE0C",
 *   "attributes": { "var1": "1", "var2": "2" },
 *   "recipient": "09120000000",
 *   "line_number": "50002178584000",
 *   "number_format": "english"
 * }'
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس درگاه پیامکی فراز اس‌ام‌اس.
 */
class WC_Gateway_FarazSMS implements WC_SMS_Gateway_Interface {

	/**
	 * آدرس اندپوینت ارسال پیامک پترن در وب‌سرویس فراز اس‌ام‌اس.
	 *
	 * @var string
	 */
	const API_ENDPOINT_PATTERN = 'https://api.iranpayamak.com/ws/v1/sms/pattern';

	/**
	 * کلید API درگاه (دریافت‌شده از پنل کاربری فراز اس‌ام‌اس).
	 * توجه: طبق مستندات این وب‌سرویس، کلید API در هدر درخواست با نام Api-Key ارسال می‌شود.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * شماره خط ارسال‌کننده (line_number).
	 *
	 * @var string
	 */
	private $sender_number;

	/**
	 * سازنده کلاس؛ خواندن تنظیمات ذخیره‌شده درگاه از Options API.
	 */
	public function __construct() {
		$this->api_key       = get_option( 'wc_sms_auth_farazsms_api_key', '' );
		$this->sender_number = get_option( 'wc_sms_auth_farazsms_sender_number', '' );
	}

	/**
	 * ارسال پیامک کد یکبار مصرف از طریق اندپوینت ارسال پترن فراز اس‌ام‌اس.
	 *
	 * @param string $phone      شماره موبایل مقصد (recipient).
	 * @param string $code       کد یکبار مصرف تولید شده.
	 * @param string $pattern_id کد پترن تاییدشده در پنل؛ در صورت خالی بودن، از تنظیمات ذخیره‌شده خوانده می‌شود.
	 *
	 * @return true|WP_Error
	 */
	public function send_otp( $phone, $code, $pattern_id ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_api_key',
				__( 'کلید API درگاه فراز اس‌ام‌اس تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		if ( empty( $this->sender_number ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_sender',
				__( 'شماره خط ارسال‌کننده (line_number) درگاه فراز اس‌ام‌اس تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		if ( empty( $pattern_id ) ) {
			$pattern_id = get_option( 'wc_sms_auth_farazsms_pattern_code', '' );
		}

		if ( empty( $pattern_id ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_pattern',
				__( 'کد پترن درگاه فراز اس‌ام‌اس تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		$request_body = array(
			'code'          => $pattern_id,
			// نام متغیر "code" باید دقیقاً با متغیر تعریف‌شده در پترن تاییدشده پنل مطابقت داشته باشد.
			'attributes'    => array(
				'code' => $code,
			),
			'recipient'     => $phone,
			'line_number'   => $this->sender_number,
			'number_format' => 'english',
		);

		$response = wp_remote_post(
			self::API_ENDPOINT_PATTERN,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'Api-Key'      => $this->api_key,
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );

		$http_success       = ( $response_code >= 200 && $response_code < 300 );
		$body_marks_failure = is_array( $response_data )
			&& array_key_exists( 'success', $response_data )
			&& false === $response_data['success'];

		$is_success = $http_success && ! $body_marks_failure;

		if ( ! $is_success ) {
			$error_message = ! empty( $response_data['message'] )
				? $response_data['message']
				: __( 'خطای نامشخص در ارسال پیامک از درگاه فراز اس‌ام‌اس.', 'wc-sms-auth' );

			return new WP_Error( 'wc_sms_auth_gateway_error', $error_message, $response_data );
		}

		return true;
	}
}
