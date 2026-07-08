/**
 * اسکریپت اصلی فرانت‌اند مودال احراز هویت پیامکی.
 *
 * شامل: باز/بسته شدن مودال با انیمیشن، Event Delegation تریگر باز شدن،
 * ولیدیشن و ارسال فرم مرحله اول ثبت‌نام/ورود به اندپوینت REST /send-otp
 * (با استفاده از مقادیر WCSMSAuthData)، هدایت به مرحله دوم (کد تایید) و
 * راه‌اندازی/بازنشانی تایمر شمارش معکوس OTP همراه با دکمه ارسال مجدد.
 * منطق تایید نهایی کد (اتصال به اندپوینت /verify-otp) در پرامپت‌های
 * بعدی فاز ۷ تکمیل خواهد شد.
 */
( function () {
	'use strict';

	/**
	 * شناسه المان Backdrop اصلی مودال در مارک‌آپ تزریق‌شده در فوتر.
	 */
	var MODAL_ID = 'authModal';

	/**
	 * الگوی معتبر شماره موبایل ایران (۱۱ رقم، شروع‌شده با ۰۹) جهت
	 * ولیدیشن سریع سمت کلاینت، پیش از ارسال درخواست به بک‌اند (که خودش
	 * نیز طبق Prompt 29 همین ولیدیشن را به‌صورت مستقل تکرار می‌کند).
	 */
	var IRANIAN_MOBILE_REGEX = /^09\d{9}$/;

	/**
	 * مدت زمان (میلی‌ثانیه) ترنزیشن محو شدن کانتینر پاپ‌آپ، هم‌راستا با
	 * کلاس Tailwind «duration-500» تعریف‌شده روی خودِ کانتینر در HTML؛
	 * قبل از پایان این فاصله زمانی، بک‌دراپ نباید مخفی (pointer-events-none)
	 * شود، در غیر این صورت انیمیشن بستن به‌صورت ناقص/بریده دیده می‌شود.
	 */
	var CLOSE_ANIMATION_DELAY_MS = 400;

	/**
	 * فاصله زمانی جزئی قبل از شروع انیمیشن Scale/Opacity کانتینر پس از
	 * نمایش بک‌دراپ؛ تضمین می‌کند مرورگر یک فریم رندر بین حذف
	 * pointer-events-none و افزودن کلاس‌های انیمیشن فاصله بگذارد تا
	 * ترنزیشن CSS به‌درستی (به‌جای پرش ناگهانی) اجرا شود.
	 */
	var OPEN_ANIMATION_DELAY_MS = 50;

	/**
	 * مدت زمان (میلی‌ثانیه) ترنزیشن اسلاید بین مرحله اول و دوم فرم
	 * ثبت‌نام/ورود (هم‌راستا با کلاس Tailwind «duration-300» تعریف‌شده
	 * روی خودِ استپ‌ها در HTML).
	 */
	var STEP_TRANSITION_DELAY_MS = 300;

	/**
	 * مدت زمان (میلی‌ثانیه) ترنزیشن سوییچ کامل بین کانتینر فرم ورود و
	 * ثبت‌نام (هم‌راستا با کلاس Tailwind «duration-500» تعریف‌شده روی
	 * خودِ فرم‌ها در HTML).
	 */
	var FORM_SWITCH_TRANSITION_DELAY_MS = 400;

	/**
	 * قفل ساده جهت جلوگیری از ارسال هم‌زمان/تکراری درخواست send-otp،
	 * مثلاً در صورت چند بار کلیک سریع کاربر روی دکمه ارسال.
	 */
	var isOtpRequestInFlight = false;

	/**
	 * شناسه بازگشتی setInterval تایمر شمارش معکوس OTP جاری (جهت
	 * توقف/بازنشانی آن با clearInterval هنگام شروع تایمر جدید یا بسته
	 * شدن مودال).
	 */
	var otpTimerIntervalId = null;

	/**
	 * ثانیه‌های باقی‌مانده تایمر جاری.
	 */
	var otpTimerSecondsLeft = 0;

	/**
	 * مقدار چک‌باکس «مرا به خاطر بسپار» که در مرحله اول فرم (ثبت‌نام یا
	 * ورود) توسط کاربر انتخاب شده؛ چون این مقدار باید همراه درخواست
	 * نهایی /verify-otp (در مرحله دوم) به بک‌اند ارسال شود، تا رسیدن
	 * به آن مرحله در همین متغیر سطح-ماژول نگه‌داری می‌شود.
	 */
	var pendingRememberMe = false;

	/**
	 * تبدیل اعداد فارسی و عربی به معادل انگلیسی.
	 *
	 * @param {string} str رشته ورودی حاوی اعداد فارسی/عربی.
	 * @return {string} رشته اصلاح‌شده با اعداد انگلیسی.
	 */
	function toEnglishDigits( str ) {
		if ( typeof str !== 'string' ) {
			return str;
		}
		var persianDigits = [/۰/g, /۱/g, /۲/g, /۳/g, /۴/g, /۵/g, /۶/g, /۷/g, /۸/g, /۹/g];
		var arabicDigits  = [/٠/g, /١/g, /٢/g, /٣/g, /٤/g, /٥/g, /٦/g, /٧/g, /٨/g, /٩/g];
		for ( var i = 0; i < 10; i++ ) {
			str = str.replace( persianDigits[i], i ).replace( arabicDigits[i], i );
		}
		return str;
	}

	/**
	 * دریافت المان Backdrop و کانتینر داخلی (Popup Wrapper) مودال.
	 *
	 * @return {{backdrop: (Element|null), container: (Element|null)}}
	 */
	function getModalElements() {
		var backdrop = document.getElementById( MODAL_ID );

		return {
			backdrop: backdrop,
			container: backdrop ? backdrop.querySelector( '.relative' ) : null,
		};
	}

	/**
	 * باز کردن مودال احراز هویت با انیمیشن کلاسیک Tailwind:
	 * ابتدا بک‌دراپ (پس‌زمینه تیره/بلور) نمایان می‌شود (opacity-0 -> opacity-100
	 * و pointer-events-none -> pointer-events-auto)، سپس با یک فاصله
	 * کوتاه، کانتینر اصلی پاپ‌آپ با ترنزیشن Scale/Opacity (scale-95/opacity-0
	 * -> scale-100/opacity-100) به نمایش کامل می‌رسد.
	 */
	function openAuthModal() {
		var elements = getModalElements();

		if ( ! elements.backdrop ) {
			return;
		}

		elements.backdrop.classList.remove( 'opacity-0', 'pointer-events-none' );
		elements.backdrop.classList.add( 'opacity-100', 'pointer-events-auto' );

		setTimeout( function () {
			if ( elements.container ) {
				elements.container.classList.remove( 'scale-95', 'opacity-0' );
				elements.container.classList.add( 'scale-100', 'opacity-100' );
			}
		}, OPEN_ANIMATION_DELAY_MS );

		// جلوگیری از اسکرول محتوای پشت مودال تا زمانی که مودال باز است.
		document.body.classList.add( 'overflow-hidden' );
	}

	/**
	 * بستن مودال احراز هویت با انیمیشن معکوس نمایش:
	 * ابتدا کانتینر پاپ‌آپ کوچک و محو می‌شود (scale-100/opacity-100 ->
	 * scale-95/opacity-0)، و پس از اتمام کامل ترنزیشن آن، بک‌دراپ نیز
	 * مخفی (opacity-0 و pointer-events-none) می‌شود تا کلیک‌های بعدی
	 * دوباره از پشت مودال به سایت اصلی برسند.
	 */
	function closeAuthModal() {
		var elements = getModalElements();

		if ( ! elements.backdrop ) {
			return;
		}

		if ( elements.container ) {
			elements.container.classList.remove( 'scale-100', 'opacity-100' );
			elements.container.classList.add( 'scale-95', 'opacity-0' );
		}

		setTimeout( function () {
			elements.backdrop.classList.remove( 'opacity-100', 'pointer-events-auto' );
			elements.backdrop.classList.add( 'opacity-0', 'pointer-events-none' );

			document.body.classList.remove( 'overflow-hidden' );

			// توقف تایمر شمارش معکوس OTP در صورت باز بودن، تا در پس‌زمینه
			// (پس از بسته شدن مودال) بی‌مورد به شمارش ادامه ندهد.
			clearInterval( otpTimerIntervalId );

			// ریست فرم‌ها/سوییچ به حالت پیش‌فرض.
			if ( 'function' === typeof window.resetAuthModalForms ) {
				window.resetAuthModalForms();
			}
		}, CLOSE_ANIMATION_DELAY_MS );
	}

	/**
	 * سوییچ بین فرم «ثبت‌نام» و فرم «ورود» با همان انیمیشن اسلاید/فید
	 * افقی که در مارک‌آپ HTML تعریف شده (کلاس‌های translate-x-12 و
	 * opacity-0 روی هر دو فرم). این تابع از طریق ویژگی‌های
	 * onclick="toggleForms('login')" / onclick="toggleForms('signup')"
	 * روی دکمه‌های «بازگشت به ورود» و «عضویت در سایت» در مارک‌آپ فراخوانی می‌شود.
	 *
	 * @param {string} target مقصد سوییچ؛ 'login' یا 'signup'.
	 */
	function toggleForms( target ) {
		var signupForm = document.getElementById( 'signupForm' );
		var loginForm = document.getElementById( 'loginForm' );
		var signupFormTitle = document.getElementById( 'signupFormTitle' );
		var loginFormTitle = document.getElementById( 'loginFormTitle' );

		// توقف تایمر OTP احتمالاً در حال اجرا، چون با سوییچ فرم، مرحله
		// تایید کد دیگر روی صفحه نیست.
		clearInterval( otpTimerIntervalId );

		if ( 'signup' === target ) {
			// هنگام بازگشت به فرم ثبت‌نام، همیشه مرحله اول (اطلاعات پایه) را
			// نمایش بده، نه مرحله دوم (تایید کد) که ممکن است قبلاً باز مانده باشد.
			var step1 = document.getElementById( 'signupStep1' );
			var step2 = document.getElementById( 'signupStep2' );

			if ( step2 ) {
				step2.classList.add( 'hidden', 'opacity-0', 'translate-x-12' );
			}

			if ( step1 ) {
				step1.classList.remove( 'hidden', 'opacity-0', '-translate-x-12' );
				step1.classList.add( 'opacity-100', 'translate-x-0' );
			}

			// تایتل «ثبت نام در سایت» متعلق به مرحله اول است؛ چون اکنون
			// مرحله اول دوباره نمایان شده، تایتل هم باید دوباره نمایان شود.
			if ( signupFormTitle ) {
				signupFormTitle.classList.remove( 'hidden' );
			}
		}

		if ( 'login' === target ) {
			if ( signupForm ) {
				signupForm.classList.add( 'opacity-0', '-translate-x-12' );
				signupForm.classList.remove( 'translate-x-0', 'opacity-100' );
			}

			if ( signupFormTitle ) {
				signupFormTitle.classList.add( 'hidden' );
			}

			setTimeout( function () {
				if ( signupForm ) {
					signupForm.classList.add( 'hidden' );
				}

				if ( loginForm ) {
					loginForm.classList.remove( 'hidden' );
					// خواندن offsetHeight باعث Force Reflow می‌شود تا انیمیشن
					// ترنزیشن (به‌جای پرش ناگهانی) به‌درستی اجرا شود.
					loginForm.offsetHeight;
					loginForm.classList.remove( 'opacity-0', 'translate-x-12' );
					loginForm.classList.add( 'opacity-100', 'translate-x-0' );
				}

				if ( loginFormTitle ) {
					loginFormTitle.classList.remove( 'hidden' );
				}
			}, FORM_SWITCH_TRANSITION_DELAY_MS );

			return;
		}

		if ( loginForm ) {
			loginForm.classList.add( 'opacity-0', 'translate-x-12' );
			loginForm.classList.remove( 'translate-x-0', 'opacity-100' );
		}

		if ( loginFormTitle ) {
			loginFormTitle.classList.add( 'hidden' );
		}

		setTimeout( function () {
			if ( loginForm ) {
				loginForm.classList.add( 'hidden' );
			}

			if ( signupForm ) {
				signupForm.classList.remove( 'hidden' );
				signupForm.offsetHeight;
				signupForm.classList.remove( 'opacity-0', '-translate-x-12' );
				signupForm.classList.add( 'opacity-100', 'translate-x-0' );
			}
		}, FORM_SWITCH_TRANSITION_DELAY_MS );
	}

	/**
	 * بازگشت از مرحله دوم (تایید کد پیامکی) به مرحله اول فرم ثبت‌نام
	 * (اصلاح نام/شماره تماس)، با انیمیشن اسلاید معکوس نسبت به
	 * goToRegisterStep2. از طریق onclick="goBackToRegisterStep1()" روی
	 * دکمه «اصلاح شماره تماس» فراخوانی می‌شود.
	 */
	function goBackToRegisterStep1() {
		clearInterval( otpTimerIntervalId );

		var step1 = document.getElementById( 'signupStep1' );
		var step2 = document.getElementById( 'signupStep2' );
		var signupFormTitle = document.getElementById( 'signupFormTitle' );

		if ( step2 ) {
			step2.classList.add( 'opacity-0', 'translate-x-12' );
			step2.classList.remove( 'translate-x-0', 'opacity-100' );
		}

		setTimeout( function () {
			if ( step2 ) {
				step2.classList.add( 'hidden' );
			}

			if ( step1 ) {
				step1.classList.remove( 'hidden' );
				step1.offsetHeight;
				step1.classList.remove( 'opacity-0', '-translate-x-12' );
				step1.classList.add( 'opacity-100', 'translate-x-0' );
			}

			// تایتل «ثبت نام در سایت» فقط متعلق به مرحله اول است؛ مرحله دوم
			// (تأیید تلفن) تایتل مستقل خودش را دارد، پس با بازگشت به مرحله
			// اول، این تایتل هم باید دوباره نمایان شود.
			if ( signupFormTitle ) {
				signupFormTitle.classList.remove( 'hidden' );
			}
		}, STEP_TRANSITION_DELAY_MS );
	}

	/**
	 * بازنشانی کامل فیلدهای ورودی و سوییچ به حالت پیش‌فرض (فرم ثبت‌نام،
	 * مرحله اول) هنگام بسته شدن مودال؛ تا دفعه بعد که کاربر مودال را باز
	 * می‌کند، با اطلاعات/مرحله باقی‌مانده از تلاش قبلی مواجه نشود.
	 */
	function resetAuthModalForms() {
		toggleForms( 'signup' );

		var textFieldsToClear = [ 'reg-name', 'reg-phone', 'reg-otp', 'login-phone' ];

		textFieldsToClear.forEach( function ( fieldId ) {
			var field = document.getElementById( fieldId );

			if ( field ) {
				field.value = '';
			}
		} );

		var checkboxesToUncheck = [ 'reg-remember', 'login-remember' ];

		checkboxesToUncheck.forEach( function ( checkboxId ) {
			var checkbox = document.getElementById( checkboxId );

			if ( checkbox ) {
				checkbox.checked = false;
			}
		} );

		pendingRememberMe = false;
	}

	/**
	 * راه‌اندازی/بازنشانی تایمر شمارش معکوس OTP بر پایه مقدار
	 * WCSMSAuthData.timerSeconds (مقداری که ادمین در تب «تنظیمات عمومی»
	 * پنل تعیین کرده و در Prompt 26 توسط wp_localize_script به فرانت
	 * پاس داده شد). تا پایان تایمر، دکمه «ارسال مجدد کد» غیرفعال
	 * می‌ماند؛ به محض صفر شدن، همان دکمه فعال می‌شود.
	 */
	function startOTPTimer() {
		var timerDisplay = document.getElementById( 'countdown-display' );
		var resendBtn = document.getElementById( 'resend-code-btn' );

		clearInterval( otpTimerIntervalId );

		otpTimerSecondsLeft = parseInt( WCSMSAuthData && WCSMSAuthData.timerSeconds, 10 ) || 120;

		if ( resendBtn ) {
			resendBtn.disabled = true;
			resendBtn.classList.add( 'text-gray-400', 'cursor-not-allowed' );
			resendBtn.classList.remove( 'text-[#E7A439]', 'hover:text-[#cf902f]', 'cursor-pointer' );
		}

		var updateTimerDisplay = function () {
			var minutes = Math.floor( otpTimerSecondsLeft / 60 );
			var seconds = otpTimerSecondsLeft % 60;

			var minutesText = String( minutes ).padStart( 2, '0' );
			var secondsText = String( seconds ).padStart( 2, '0' );

			if ( timerDisplay ) {
				timerDisplay.textContent = minutesText + ':' + secondsText;
			}

			if ( otpTimerSecondsLeft <= 0 ) {
				clearInterval( otpTimerIntervalId );

				if ( resendBtn ) {
					resendBtn.disabled = false;
					resendBtn.classList.remove( 'text-gray-400', 'cursor-not-allowed' );
					resendBtn.classList.add( 'text-[#E7A439]', 'hover:text-[#cf902f]', 'cursor-pointer' );
				}

				showToast( 'شما هم‌اکنون می‌توانید کد تایید را دوباره درخواست کنید.' );
			} else {
				otpTimerSecondsLeft--;
			}
		};

		updateTimerDisplay();
		otpTimerIntervalId = setInterval( updateTimerDisplay, 1000 );
	}

	/**
	 * کال‌بک دکمه «ارسال مجدد کد» (فراخوانی‌شده از طریق onclick="resendOTPCode()"
	 * در مارک‌آپ HTML؛ این دکمه تا پایان تایمر، با ویژگی disabled غیرفعال
	 * است). شماره موبایل هدف را از همان متنی که در مرحله قبل روی
	 * #targetPhoneDisplay نوشته شده می‌خواند، فیلد کد قبلی را خالی کرده
	 * و مجدداً درخواست به اندپوینت /send-otp ارسال می‌کند؛ در صورت
	 * موفقیت، تایمر از نو شروع می‌شود.
	 */
	function resendOTPCode() {
		var resendBtn = document.getElementById( 'resend-code-btn' );

		// محافظت اضافی در برابر فراخوانی برنامه‌نویسی مستقیم تابع، چون
		// ویژگی disabled به‌تنهایی رویداد کلیک مرورگر را مسدود می‌کند
		// اما مانع صدا زدن مستقیم تابع در کنسول/کد نمی‌شود.
		if ( resendBtn && resendBtn.disabled ) {
			return;
		}

		if ( isOtpRequestInFlight ) {
			return;
		}

		var phoneDisplay = document.getElementById( 'targetPhoneDisplay' );
		var phone = phoneDisplay ? phoneDisplay.textContent.trim() : '';

		if ( ! IRANIAN_MOBILE_REGEX.test( phone ) ) {
			showToast( 'شماره موبایل معتبر یافت نشد؛ لطفاً فرآیند را از ابتدا آغاز کنید.' );
			return;
		}

		var otpInput = document.getElementById( 'reg-otp' );

		if ( otpInput ) {
			otpInput.value = '';
		}

		isOtpRequestInFlight = true;

		requestOtpCode( phone )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					var errorMessage = ( response && response.message )
						? response.message
						: 'ارسال مجدد کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.';

					showToast( errorMessage );
					return;
				}

				var successMessage = ( response.data && response.data.message )
					? response.data.message
					: 'کد تایید جدید مجدداً برای شما ارسال شد.';

				showToast( successMessage );
				startOTPTimer();
			} )
			.catch( function () {
				showToast( 'برقراری ارتباط با سرور ممکن نشد. لطفاً اتصال اینترنت خود را بررسی کنید.' );
			} )
			.finally( function () {
				isOtpRequestInFlight = false;
			} );
	}

	/**
	 * نمایش کوتاه یک پیام Toast (اعلان شناور) با استفاده از مارک‌آپ
	 * #toast/#toastMessage موجود در فوتر (تزریق‌شده در Prompt 24).
	 *
	 * @param {string} message متن پیام جهت نمایش به کاربر.
	 */
	function showToast( message ) {
		var toast = document.getElementById( 'toast' );
		var toastMessage = document.getElementById( 'toastMessage' );

		if ( ! toast || ! toastMessage ) {
			return;
		}

		toastMessage.textContent = message;
		toast.classList.remove( 'translate-y-[-100px]', 'opacity-0' );
		toast.classList.add( 'translate-y-0', 'opacity-100' );

		setTimeout( function () {
			toast.classList.add( 'translate-y-[-100px]', 'opacity-0' );
			toast.classList.remove( 'translate-y-0', 'opacity-100' );
		}, 3500 );
	}

	/**
	 * ارسال درخواست POST به اندپوینت REST «/send-otp» جهت تولید و
	 * ارسال پیامکی کد یکبار مصرف برای شماره موبایل ورودی.
	 *
	 * هدر امنیتی X-WP-Nonce با مقدار توکنی که وردپرس از طریق
	 * wp_localize_script (آبجکت WCSMSAuthData - Prompt 26) در اختیار
	 * فرانت قرار داده، همراه درخواست ارسال می‌شود تا permission_callback
	 * سمت بک‌اند (Prompt 21) آن را معتبر بداند.
	 *
	 * @param {string} phone شماره موبایل معتبرشده کاربر.
	 * @return {Promise<Object>} بدنه JSON پاسخ سرور با ساختار استاندارد { success, data|message }.
	 */
	function requestOtpCode( phone, action ) {
		var requestBody = { phone: phone };
		if ( action ) {
			requestBody.action = action;
		}

		return fetch( WCSMSAuthData.restUrl + 'send-otp', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': WCSMSAuthData.nonce,
			},
			body: JSON.stringify( requestBody ),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * ارسال درخواست POST به اندپوینت REST «/verify-otp» جهت اعتبارسنجی
	 * کد یکبار مصرف وارد شده توسط کاربر در مرحله دوم فرم.
	 *
	 * @param {string}  phone    شماره موبایلی که کد برایش ارسال شده بود.
	 * @param {string}  code     کد یکبار مصرف وارد شده توسط کاربر.
	 * @param {boolean} remember مقدار چک‌باکس «مرا به خاطر بسپار» انتخاب‌شده در مرحله اول.
	 * @return {Promise<Object>} بدنه JSON پاسخ سرور با ساختار استاندارد { success, data|message }.
	 */
	function requestVerifyOtp( phone, code, remember ) {
		return fetch( WCSMSAuthData.restUrl + 'verify-otp', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': WCSMSAuthData.nonce,
			},
			body: JSON.stringify( { phone: phone, code: code, remember: !! remember } ),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * کپچر رویداد Submit فرم مرحله دوم (کد تایید) - #otpSubmitForm.
	 * (فراخوانی‌شده از طریق onsubmit="handleVerificationSubmit(event)" در
	 * مارک‌آپ HTML). کد وارد شده را به اندپوینت /verify-otp ارسال
	 * می‌کند؛ هر خطای متمایز بازگردانده‌شده از بک‌اند (Prompt 34: کد
	 * منقضی/یافت‌نشده در برابر کد اشتباه) با همان پیام دقیق سرور به
	 * صورت Toast به کاربر نمایش داده می‌شود.
	 *
	 * @param {SubmitEvent} event شیء رویداد Submit فرم.
	 */
	function handleVerificationSubmit( event ) {
		event.preventDefault();

		if ( isOtpRequestInFlight ) {
			return;
		}

		var otpInput = document.getElementById( 'reg-otp' );
		var code = otpInput ? toEnglishDigits( otpInput.value.trim() ) : '';

		if ( ! code ) {
			showToast( 'لطفاً رمز عبور پیامکی ارسالی را وارد نمایید.' );
			return;
		}

		var phoneDisplay = document.getElementById( 'targetPhoneDisplay' );
		var phone = phoneDisplay ? toEnglishDigits( phoneDisplay.textContent.trim() ) : '';

		if ( ! IRANIAN_MOBILE_REGEX.test( phone ) ) {
			showToast( 'شماره موبایل معتبر یافت نشد؛ لطفاً فرآیند را از ابتدا آغاز کنید.' );
			return;
		}

		isOtpRequestInFlight = true;

		requestVerifyOtp( phone, code, pendingRememberMe )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					var errorMessage = ( response && response.message )
						? response.message
						: 'اعتبارسنجی کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.';

					showToast( errorMessage );
					return;
				}

				var successMessage = ( response.data && response.data.message )
					? response.data.message
					: 'اعتبارسنجی با موفقیت انجام شد.';

				showToast( successMessage );
				clearInterval( otpTimerIntervalId );

				var redirectUrl = response.data && response.data.redirectUrl;

				// در صورتی که بک‌اند آدرس صفحه «حساب کاربری من» ووکامرس را
				// برگردانده باشد (Prompt 38)، کاربر پس از دیدن پیام موفقیت
				// Toast، مستقیماً به آن صفحه هدایت می‌شود؛ فاصله زمانی کوتاه
				// فقط برای این است که کاربر فرصت دیدن پیام Toast را داشته باشد.
				if ( redirectUrl ) {
					setTimeout( function () {
						window.location.href = redirectUrl;
					}, 1000 );
					return;
				}

				// در صورت نبود آدرس ریدایرکت (مثلاً ووکامرس صفحه حساب کاربری
				// را تنظیم نکرده)، صرفاً مودال بسته می‌شود.
				setTimeout( function () {
					closeAuthModal();
				}, 1000 );
			} )
			.catch( function () {
				showToast( 'برقراری ارتباط با سرور ممکن نشد. لطفاً اتصال اینترنت خود را بررسی کنید.' );
			} )
			.finally( function () {
				isOtpRequestInFlight = false;
			} );
	}

	/**
	 * هدایت انیمیشنی رابط کاربری به مرحله دوم (ورود کد تایید پیامکی).
	 *
	 * چون در مارک‌آپ مودال تنها یک فرم کد تایید (#signupStep2) وجود دارد
	 * که هم برای مسیر «ثبت‌نام» و هم مسیر «ورود» استفاده می‌شود، در
	 * صورتی که درخواست از فرم ورود آمده باشد (fromLoginForm === true)،
	 * ابتدا با همان ترنزیشن اسلاید موجود در toggleForms از فرم ورود به
	 * فرم ثبت‌نام سوییچ می‌شویم و مستقیماً مرحله دوم را نمایان می‌کنیم؛
	 * در غیر این صورت (درخواست از مرحله اول ثبت‌نام)، فقط بین دو استپ
	 * همان فرم سوییچ می‌شود.
	 *
	 * @param {string}  phone         شماره موبایلی که کد برایش ارسال شده (جهت نمایش در UI).
	 * @param {boolean} fromLoginForm true اگر درخواست از فرم ورود آمده باشد.
	 */
	function revealOtpVerificationStep( phone, fromLoginForm ) {
		var loginForm = document.getElementById( 'loginForm' );
		var signupForm = document.getElementById( 'signupForm' );
		var step1 = document.getElementById( 'signupStep1' );
		var step2 = document.getElementById( 'signupStep2' );
		var phoneDisplay = document.getElementById( 'targetPhoneDisplay' );
		var signupFormTitle = document.getElementById( 'signupFormTitle' );
		var loginFormTitle = document.getElementById( 'loginFormTitle' );

		if ( phoneDisplay ) {
			phoneDisplay.textContent = phone;
		}

		var showStep2 = function () {
			if ( ! step2 ) {
				return;
			}

			step2.classList.remove( 'hidden' );
			// خواندن offsetHeight باعث Force Reflow می‌شود تا مرورگر تغییر
			// وضعیت «hidden» را قبل از افزودن کلاس‌های ترنزیشن اعمال کرده
			// باشد، در غیر این صورت انیمیشن Fade/Slide اجرا نمی‌شود.
			step2.offsetHeight;
			step2.classList.remove( 'opacity-0', 'translate-x-12' );
			step2.classList.add( 'opacity-100', 'translate-x-0' );

			// مرحله دوم (تأیید تلفن) تایتل مستقل خودش را دارد؛ تایتل
			// «ثبت نام در سایت» (مخصوص مرحله اول) باید مخفی شود تا با آن
			// تداخل/دوتاشدگی نداشته باشد.
			if ( signupFormTitle ) {
				signupFormTitle.classList.add( 'hidden' );
			}

			// شروع تایمر شمارش معکوس OTP به محض نمایان شدن کامل مرحله دوم.
			startOTPTimer();
		};

		if ( fromLoginForm ) {
			if ( loginForm ) {
				loginForm.classList.add( 'opacity-0', 'translate-x-12' );
				loginForm.classList.remove( 'translate-x-0', 'opacity-100' );
			}

			if ( loginFormTitle ) {
				loginFormTitle.classList.add( 'hidden' );
			}

			setTimeout( function () {
				if ( loginForm ) {
					loginForm.classList.add( 'hidden' );
				}

				if ( signupForm ) {
					signupForm.classList.remove( 'hidden' );
					// خواندن offsetHeight باعث Force Reflow می‌شود تا مرورگر تغییر
					// وضعیت «hidden» را قبل از افزودن کلاس‌های ترنزیشن اعمال کرده
					// باشد؛ در غیر این صورت انیمیشن اجرا نمی‌شود.
					signupForm.offsetHeight;
					// حیاتی: بدون این دو خط، فرم ثبت‌نام با کلاس‌های opacity-0
					// و -translate-x-12 (که toggleForms('login') قبلاً روی آن
					// گذاشته بود) نامرئی باقی می‌ماند، حتی بعد از حذف کلاس hidden.
					signupForm.classList.remove( 'opacity-0', '-translate-x-12' );
					signupForm.classList.add( 'opacity-100', 'translate-x-0' );
				}

				if ( step1 ) {
					step1.classList.add( 'hidden', 'opacity-0', '-translate-x-12' );
					step1.classList.remove( 'opacity-100', 'translate-x-0' );
				}

				showStep2();
			}, FORM_SWITCH_TRANSITION_DELAY_MS );

			return;
		}

		if ( step1 ) {
			step1.classList.add( 'opacity-0', '-translate-x-12' );
			step1.classList.remove( 'translate-x-0', 'opacity-100' );
		}

		setTimeout( function () {
			if ( step1 ) {
				step1.classList.add( 'hidden' );
			}

			showStep2();
		}, STEP_TRANSITION_DELAY_MS );
	}

	/**
	 * منطق مشترک اعتبارسنجی پاسخ سرور و هدایت به مرحله دوم؛ توسط هر دو
	 * مسیر ثبت‌نام (goToRegisterStep2) و ورود (handleLoginFormSubmit) صدا
	 * زده می‌شود تا کد تکراری نداشته باشیم.
	 *
	 * @param {string}  phone         شماره موبایل معتبرشده کاربر.
	 * @param {boolean} fromLoginForm true اگر درخواست از فرم ورود آمده باشد.
	 */
	function sendOtpAndProceed( phone, fromLoginForm ) {
		if ( isOtpRequestInFlight ) {
			return;
		}

		isOtpRequestInFlight = true;

		var action = fromLoginForm ? 'login' : 'register';

		requestOtpCode( phone, action )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					var errorMessage = ( response && response.message )
						? response.message
						: 'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.';

					showToast( errorMessage );
					return;
				}

				var successMessage = ( response.data && response.data.message )
					? response.data.message
					: 'کد تایید یکبار مصرف برای شما ارسال شد.';

				showToast( successMessage );
				revealOtpVerificationStep( phone, fromLoginForm );
			} )
			.catch( function () {
				showToast( 'برقراری ارتباط با سرور ممکن نشد. لطفاً اتصال اینترنت خود را بررسی کنید.' );
			} )
			.finally( function () {
				isOtpRequestInFlight = false;
			} );
	}

	/**
	 * کال‌بک عمومی دکمه «ثبت نام با کد تایید» در مرحله اول فرم ثبت‌نام
	 * (فراخوانی‌شده از طریق onclick="goToRegisterStep2()" در مارک‌آپ HTML).
	 * نام و شماره موبایل را ولیدیت کرده و در صورت معتبر بودن، درخواست
	 * ارسال کد را ثبت می‌کند.
	 */
	function goToRegisterStep2() {
		var nameInput = document.getElementById( 'reg-name' );
		var phoneInput = document.getElementById( 'reg-phone' );
		var rememberInput = document.getElementById( 'reg-remember' );
		var name = nameInput ? nameInput.value.trim() : '';
		var phone = phoneInput ? toEnglishDigits( phoneInput.value.trim() ) : '';

		if ( ! name || ! phone ) {
			showToast( 'لطفاً ابتدا تمامی مشخصات مرحله اول را تکمیل نمایید.' );
			return;
		}

		if ( ! IRANIAN_MOBILE_REGEX.test( phone ) ) {
			showToast( 'لطفاً یک شماره موبایل معتبر وارد نمایید (مانند ۰۹۱۲۳۴۵۶۷۸۹).' );
			return;
		}

		pendingRememberMe = !! ( rememberInput && rememberInput.checked );

		sendOtpAndProceed( phone, false );
	}

	/**
	 * کپچر رویداد Submit فرم مرحله اول «ورود» (#loginSubmitForm).
	 * جلوی رفتار پیش‌فرض ارسال فرم (رفرش صفحه) گرفته می‌شود، شماره
	 * موبایل ولیدیت و سپس درخواست ارسال کد ثبت می‌گردد.
	 *
	 * @param {SubmitEvent} e شیء رویداد Submit فرم.
	 */
	function handleLoginFormSubmit( e ) {
		e.preventDefault();

		var phoneInput = document.getElementById( 'login-phone' );
		var rememberInput = document.getElementById( 'login-remember' );
		var phone = phoneInput ? toEnglishDigits( phoneInput.value.trim() ) : '';

		if ( ! IRANIAN_MOBILE_REGEX.test( phone ) ) {
			showToast( 'لطفاً یک شماره موبایل معتبر وارد نمایید (مانند ۰۹۱۲۳۴۵۶۷۸۹).' );
			return;
		}

		pendingRememberMe = !! ( rememberInput && rememberInput.checked );

		sendOtpAndProceed( phone, true );
	}

	// در دسترس قرار دادن توابع مورد نیاز مارک‌آپ HTML در Scope سراسری،
	// چون ویژگی‌های onclick (دکمه بستن مودال و دکمه مرحله اول ثبت‌نام)
	// این توابع را از بیرون این IIFE صدا می‌زنند.
	window.openAuthModal = openAuthModal;
	window.closeAuthModal = closeAuthModal;
	window.toggleForms = toggleForms;
	window.goToRegisterStep2 = goToRegisterStep2;
	window.goBackToRegisterStep1 = goBackToRegisterStep1;
	window.resendOTPCode = resendOTPCode;
	window.handleVerificationSubmit = handleVerificationSubmit;
	window.resetAuthModalForms = resetAuthModalForms;

	// Event Delegation: به کل سند برای رویداد کلیک گوش می‌دهیم تا هر
	// المان تریگری (دکمه، لینک، آیکون و ...) با کلاس open-auth-modal یا
	// open-auth-modal-redirect که در هر نقطه‌ای از سایت قرار گرفته باشد،
	// بدون نیاز به بایند مجدد رویداد، به‌درستی هندل شود.
	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '.open-auth-modal, .open-auth-modal-redirect' );

		if ( ! trigger ) {
			return;
		}

		// اگر کاربر از قبل در وردپرس وارد شده باشد (بر پایه is_user_logged_in
		// سمت سرور، نه صرفاً یک کلاس CSS)، دیگر نباید مودال ورود/ثبت‌نام
		// دوباره باز شود؛ در عوض مستقیماً به صفحه «حساب کاربری من» ووکامرس
		// هدایت می‌شود.
		if ( WCSMSAuthData && WCSMSAuthData.isLoggedIn ) {
			if ( WCSMSAuthData.myAccountUrl ) {
				e.preventDefault();
				window.location.href = WCSMSAuthData.myAccountUrl;
			}

			// در صورت نبود آدرس حساب کاربری (مثلاً ووکامرس فعال نیست)،
			// از preventDefault صرف‌نظر می‌کنیم تا اگر خودِ لینک/دکمه href
			// معتبری داشته باشد (مثلاً ست‌شده توسط اسکریپت سفارشی سایت)،
			// رفتار پیش‌فرض مرورگر طبیعی اجرا شود.
			return;
		}

		// جلوگیری از رفتار پیش‌فرض لینک/دکمه (مثل ناوبری به # یا ارسال فرم).
		e.preventDefault();

		// اگر دکمه دارای کلاس باز کردن در صفحه جدید (ریداریکت) بود
		if ( trigger.classList.contains( 'open-auth-modal-redirect' ) ) {
			if ( WCSMSAuthData && WCSMSAuthData.myAccountUrl ) {
				var redirectUrl = WCSMSAuthData.myAccountUrl;
				// اضافه کردن پارامتر open-auth-modal=1 به انتهای URL
				if ( redirectUrl.indexOf( '?' ) !== -1 ) {
					redirectUrl += '&open-auth-modal=1';
				} else {
					redirectUrl += '?open-auth-modal=1';
				}
				window.location.href = redirectUrl;
				return;
			}
		}

		openAuthModal();
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof lucide !== 'undefined' ) {
			lucide.createIcons();
		}

		// بستن مودال با کلیک روی فضای خالی Backdrop (خارج از کادر پاپ‌آپ).
		// چون e.target === این Backdrop فقط زمانی درست است که کلیک مستقیماً
		// روی خودِ لایه تیره اتفاق افتاده باشد (نه روی فرزندانش مثل کانتینر
		// یا دکمه‌های داخلی)، این شرط دقیقاً همان «کلیک خارج از کادر» است.
		var backdrop = document.getElementById( MODAL_ID );

		if ( backdrop ) {
			backdrop.addEventListener( 'click', function ( e ) {
				if ( e.target === backdrop ) {
					closeAuthModal();
				}
			} );
		}

		// بررسی پارامتر کوئری در URL جهت باز کردن خودکار مودال در صورت ریدایرکت
		if ( window.location.search.indexOf( 'open-auth-modal=1' ) !== -1 ) {
			if ( WCSMSAuthData && ! WCSMSAuthData.isLoggedIn ) {
				// اعمال تاخیر کوتاه جهت اطمینان از بارگذاری کامل استایل‌ها و المان‌ها
				setTimeout( openAuthModal, 300 );
			}
		}

		// شنود فیلدهای ورودی جهت تبدیل خودکار و آنی اعداد فارسی/عربی به انگلیسی در زمان تایپ
		document.addEventListener( 'input', function ( e ) {
			var target = e.target;
			if ( target && ( target.id === 'reg-phone' || target.id === 'login-phone' || target.id === 'reg-otp' ) ) {
				var cleaned = toEnglishDigits( target.value );
				if ( cleaned !== target.value ) {
					target.value = cleaned;
				}
			}
		} );

		// کپچر رویداد Submit فرم مرحله اول «ورود» جهت ارسال Fetch به /send-otp.
		var loginSubmitForm = document.getElementById( 'loginSubmitForm' );

		if ( loginSubmitForm ) {
			loginSubmitForm.addEventListener( 'submit', handleLoginFormSubmit );
		}
	} );
} )();
