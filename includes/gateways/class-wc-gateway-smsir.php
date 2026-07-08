<?php
/**
 * پروایدر وب‌سرویس Sms.ir.
 *
 * این کلاس با استفاده از اندپوینت رسمی REST API نسخه ۱ Sms.ir مخصوص
 * ارسال پیامک‌های تایید (Verify/Template)، کدهای یکبار مصرف را برای
 * کاربران ارسال می‌کند.
 *
 * @see https://app.sms.ir/developer مستندات وب‌سرویس REST نسخه ۱ Sms.ir
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس درگاه پیامکی Sms.ir.
 */
class WC_Gateway_SmsIr implements WC_SMS_Gateway_Interface {

	/**
	 * آدرس اندپوینت ارسال پیامک تایید (Verify) در Sms.ir.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://api.sms.ir/v1/send/verify';

	/**
	 * کلید API درگاه (از پنل کاربری Sms.ir، بخش وب‌سرویس/API).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * شماره خط ارسال‌کننده (در متد Verify معمولاً از قبل به قالب متصل است).
	 *
	 * @var string
	 */
	private $sender_number;

	/**
	 * سازنده کلاس؛ خواندن تنظیمات ذخیره‌شده درگاه از Options API.
	 */
	public function __construct() {
		$this->api_key       = get_option( 'wc_sms_auth_smsir_api_key', '' );
		$this->sender_number = get_option( 'wc_sms_auth_smsir_sender_number', '' );
	}

	/**
	 * ارسال پیامک کد یکبار مصرف از طریق اندپوینت Verify نسخه ۱ Sms.ir.
	 *
	 * @param string $phone      شماره موبایل مقصد (mobile).
	 * @param string $code       کد یکبار مصرف تولید شده (مقدار پارامتر قالب).
	 * @param string $pattern_id شناسه عددی قالب (templateId)؛ در صورت خالی بودن، از تنظیمات ذخیره‌شده خوانده می‌شود.
	 *
	 * @return true|WP_Error
	 */
	public function send_otp( $phone, $code, $pattern_id ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_api_key',
				__( 'کلید API درگاه Sms.ir تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		if ( empty( $pattern_id ) ) {
			$pattern_id = get_option( 'wc_sms_auth_smsir_pattern_code', '' );
		}

		if ( empty( $pattern_id ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_pattern',
				__( 'شناسه قالب (Template ID) درگاه Sms.ir تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		$request_body = array(
			'mobile'     => $phone,
			'templateId' => absint( $pattern_id ),
			// نام پارامتر "Code" باید دقیقاً با نام متغیر تعریف‌شده در قالب تاییدشده پنل مطابقت داشته باشد.
			'parameters' => array(
				array(
					'name'  => 'Code',
					'value' => $code,
				),
			),
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'X-API-KEY'    => $this->api_key,
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );

		$gateway_status = isset( $response_data['status'] ) ? (int) $response_data['status'] : 0;
		$is_success     = ( $response_code >= 200 && $response_code < 300 ) && ( 1 === $gateway_status );

		if ( ! $is_success ) {
			$error_message = ! empty( $response_data['message'] )
				? $response_data['message']
				: __( 'خطای نامشخص در ارسال پیامک از درگاه Sms.ir.', 'wc-sms-auth' );

			return new WP_Error( 'wc_sms_auth_gateway_error', $error_message, $response_data );
		}

		return true;
	}
}
