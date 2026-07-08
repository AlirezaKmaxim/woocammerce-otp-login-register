<?php
/**
 * پروایدر وب‌سرویس ملی‌پیامک (MelliPayamak).
 *
 * این کلاس با استفاده از اندپوینت رسمی REST ملی‌پیامک برای ارسال
 * پیامک با متن ثابت/پترن (BaseServiceNumber)، کدهای یکبار مصرف را
 * برای کاربران ارسال می‌کند.
 *
 * توجه: وب‌سرویس ملی‌پیامک بر خلاف سایر درگاه‌ها، به‌جای یک کلید API
 * منفرد، به «نام کاربری» و «رمز عبور» پنل نیاز دارد؛ به همین دلیل مقدار
 * فیلد «کلید API» در تنظیمات این درگاه باید به فرمت زیر وارد شود:
 * نام‌کاربری:رمزعبور
 *
 * @see https://www.melipayamak.com/api/ مستندات وب‌سرویس REST ملی‌پیامک
 *
 * @package WC_SMS_Auth_Modal
 */

// جلوگیری از دسترسی مستقیم به فایل.
defined( 'ABSPATH' ) || exit;

/**
 * کلاس درگاه پیامکی ملی‌پیامک.
 */
class WC_Gateway_MelliPayamak implements WC_SMS_Gateway_Interface {

	/**
	 * آدرس اندپوینت ارسال پیامک با متن ثابت (پترن) ملی‌پیامک.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

	/**
	 * نام کاربری پنل ملی‌پیامک.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * رمز عبور پنل ملی‌پیامک.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * شماره خط ارسال‌کننده (در متد BaseServiceNumber معمولاً از قبل به پترن متصل است).
	 *
	 * @var string
	 */
	private $sender_number;

	/**
	 * سازنده کلاس؛ خواندن و تجزیه تنظیمات ذخیره‌شده درگاه از Options API.
	 */
	public function __construct() {
		$credentials         = get_option( 'wc_sms_auth_mellipayamak_api_key', '' );
		$this->sender_number = get_option( 'wc_sms_auth_mellipayamak_sender_number', '' );

		$credential_parts = explode( ':', $credentials, 2 );
		$this->username    = isset( $credential_parts[0] ) ? trim( $credential_parts[0] ) : '';
		$this->password    = isset( $credential_parts[1] ) ? trim( $credential_parts[1] ) : '';
	}

	/**
	 * ارسال پیامک کد یکبار مصرف از طریق اندپوینت BaseServiceNumber ملی‌پیامک.
	 *
	 * @param string $phone      شماره موبایل مقصد (to).
	 * @param string $code       کد یکبار مصرف تولید شده (جایگزین متغیر پترن در text).
	 * @param string $pattern_id شناسه عددی پترن (bodyId)؛ در صورت خالی بودن، از تنظیمات ذخیره‌شده خوانده می‌شود.
	 *
	 * @return true|WP_Error
	 */
	public function send_otp( $phone, $code, $pattern_id ) {
		if ( empty( $this->username ) || empty( $this->password ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_credentials',
				__( 'نام کاربری/رمز عبور درگاه ملی‌پیامک تنظیم نشده است (فرمت صحیح فیلد کلید API: نام‌کاربری:رمزعبور).', 'wc-sms-auth' )
			);
		}

		if ( empty( $pattern_id ) ) {
			$pattern_id = get_option( 'wc_sms_auth_mellipayamak_pattern_code', '' );
		}

		if ( empty( $pattern_id ) ) {
			return new WP_Error(
				'wc_sms_auth_missing_pattern',
				__( 'شناسه پترن (BodyId) درگاه ملی‌پیامک تنظیم نشده است.', 'wc-sms-auth' )
			);
		}

		$request_body = array(
			'username' => $this->username,
			'password' => $this->password,
			'text'     => $code,
			'to'       => $phone,
			'bodyId'   => absint( $pattern_id ),
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );

		$ret_status = isset( $response_data['RetStatus'] ) ? (int) $response_data['RetStatus'] : 0;
		$is_success = ( 200 === $response_code ) && ( 1 === $ret_status );

		if ( ! $is_success ) {
			$error_message = ! empty( $response_data['StrRetStatus'] )
				? $response_data['StrRetStatus']
				: __( 'خطای نامشخص در ارسال پیامک از درگاه ملی‌پیامک.', 'wc-sms-auth' );

			return new WP_Error( 'wc_sms_auth_gateway_error', $error_message, $response_data );
		}

		return true;
	}
}
