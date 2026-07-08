/**
 * اسکریپت اختصاصی پنل مدیریت افزونه WC SMS Auth Modal.
 * شامل منطق سوییچ بین تب‌ها (بدون ریلود صفحه) و آپلودر رسانه.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initGatewayToggle();
		initBannerMediaUploader();
		initTestSmsButton();
	} );

	/**
	 * راه‌اندازی منطق سوییچ تب‌ها با استفاده از Event Delegation
	 * تا نیازی به اتچ کردن رویداد جداگانه روی هر تب نباشد.
	 */
	function initTabs() {
		var tabsWrapper = document.getElementById( 'wc-sms-auth-tabs' );

		if ( ! tabsWrapper ) {
			return;
		}

		tabsWrapper.addEventListener( 'click', function ( event ) {
			var tabLink = event.target.closest( '.nav-tab' );

			if ( ! tabLink ) {
				return;
			}

			event.preventDefault();
			activateTab( tabLink.getAttribute( 'data-tab' ) );
		} );
	}

	/**
	 * فعال‌سازی تب انتخاب‌شده و نمایش محتوای متناظر با آن.
	 *
	 * @param {string} tabKey شناسه تب مورد نظر (مثلاً general، gateway، test).
	 */
	function activateTab( tabKey ) {
		if ( ! tabKey ) {
			return;
		}

		var allTabs = document.querySelectorAll( '#wc-sms-auth-tabs .nav-tab' );
		var allContents = document.querySelectorAll( '.wc-sms-auth-tab-content' );

		allTabs.forEach( function ( tab ) {
			var isTarget = tab.getAttribute( 'data-tab' ) === tabKey;
			tab.classList.toggle( 'nav-tab-active', isTarget );
		} );

		allContents.forEach( function ( content ) {
			var isTarget = content.getAttribute( 'data-tab-content' ) === tabKey;
			content.classList.toggle( 'wc-sms-auth-tab-content-active', isTarget );
		} );
	}

	/**
	 * نمایش/مخفی‌سازی خودکار ردیف تنظیمات هر درگاه پیامکی بر اساس
	 * درگاه فعالی که در فیلد Select انتخاب شده است.
	 */
	function initGatewayToggle() {
		var gatewaySelect = document.getElementById( 'wc_sms_auth_active_gateway' );

		if ( ! gatewaySelect ) {
			return;
		}

		var fieldWrappers = document.querySelectorAll( '.wc-sms-auth-gateway-fields' );

		function updateGatewayFieldsVisibility() {
			var activeGateway = gatewaySelect.value;

			fieldWrappers.forEach( function ( wrapper ) {
				var row = wrapper.closest( 'tr' );

				if ( ! row ) {
					return;
				}

				row.style.display = ( wrapper.getAttribute( 'data-gateway' ) === activeGateway ) ? '' : 'none';
			} );
		}

		gatewaySelect.addEventListener( 'change', updateGatewayFieldsVisibility );
		updateGatewayFieldsVisibility();
	}

	/**
	 * راه‌اندازی آپلودر رسانه وردپرس (wp.media) برای فیلد "تصویر بنر مودال".
	 * انتخاب تصویر، شناسه ضمیمه را در فیلد مخفی ذخیره کرده و پیش‌نمایش را
	 * به‌روزرسانی می‌کند؛ دکمه حذف نیز مقدار فیلد و پیش‌نمایش را پاک می‌کند.
	 */
	function initBannerMediaUploader() {
		var selectBtn    = document.getElementById( 'wc_sms_auth_banner_select_btn' );
		var removeBtn    = document.getElementById( 'wc_sms_auth_banner_remove_btn' );
		var hiddenInput  = document.getElementById( 'wc_sms_auth_banner_image_id' );
		var previewImg   = document.getElementById( 'wc_sms_auth_banner_preview' );
		var previewWrap  = document.getElementById( 'wc_sms_auth_banner_preview_wrap' );

		if ( ! selectBtn || ! hiddenInput || typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var mediaFrame = null;

		selectBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( mediaFrame ) {
				mediaFrame.open();
				return;
			}

			mediaFrame = wp.media( {
				title: 'انتخاب تصویر بنر مودال',
				button: { text: 'استفاده از این تصویر' },
				library: { type: 'image' },
				multiple: false
			} );

			mediaFrame.on( 'select', function () {
				var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
				var previewUrl = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;

				hiddenInput.value = attachment.id;

				if ( previewImg ) {
					previewImg.src = previewUrl;
				}

				if ( previewWrap ) {
					previewWrap.style.display = '';
				}

				if ( removeBtn ) {
					removeBtn.style.display = '';
				}
			} );

			mediaFrame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				hiddenInput.value = '0';

				if ( previewImg ) {
					previewImg.src = '';
				}

				if ( previewWrap ) {
					previewWrap.style.display = 'none';
				}

				removeBtn.style.display = 'none';
			} );
		}
	}

	/**
	 * راه‌اندازی دکمه "ارسال پیامک تست" در تب تست پیامک؛ درخواست را از طریق
	 * admin-ajax.php وردپرس (global ajaxurl) به کال‌بک سمت سرور ارسال کرده
	 * و نتیجه موفقیت/خطای واقعی درگاه پیامکی را در همان صفحه نمایش می‌دهد.
	 */
	function initTestSmsButton() {
		var panel = document.querySelector( '[data-wc-sms-auth="test-sms-panel"]' );

		if ( ! panel ) {
			return;
		}

		var sendButton  = document.getElementById( 'wc_sms_auth_send_test_sms' );
		var phoneInput  = document.getElementById( 'wc_sms_auth_test_phone' );
		var spinner     = document.getElementById( 'wc_sms_auth_test_sms_spinner' );
		var resultBox   = document.getElementById( 'wc_sms_auth_test_sms_result' );

		if ( ! sendButton || ! phoneInput || typeof ajaxurl === 'undefined' ) {
			return;
		}

		sendButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			var phone = phoneInput.value.trim();
			var nonce = sendButton.getAttribute( 'data-nonce' );

			hideResult();

			if ( ! phone ) {
				showResult( false, 'لطفاً شماره موبایل را وارد نمایید.' );
				return;
			}

			toggleLoadingState( true );

			var requestBody = new FormData();
			requestBody.append( 'action', 'wc_sms_auth_send_test_sms' );
			requestBody.append( 'nonce', nonce );
			requestBody.append( 'phone', phone );

			fetch( ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: requestBody
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( response ) {
					toggleLoadingState( false );

					if ( response && response.success ) {
						showResult( true, response.data.message );
					} else {
						var errorMessage = ( response && response.data && response.data.message )
							? response.data.message
							: 'ارسال پیامک تست با خطا مواجه شد.';
						showResult( false, errorMessage );
					}
				} )
				.catch( function () {
					toggleLoadingState( false );
					showResult( false, 'خطا در برقراری ارتباط با سرور. اتصال اینترنت خود را بررسی کنید.' );
				} );
		} );

		/**
		 * فعال/غیرفعال کردن وضعیت لودینگ دکمه و اسپینر حین ارسال درخواست.
		 *
		 * @param {boolean} isLoading وضعیت در حال بارگذاری بودن.
		 */
		function toggleLoadingState( isLoading ) {
			sendButton.disabled = isLoading;

			if ( spinner ) {
				spinner.classList.toggle( 'is-active', isLoading );
			}
		}

		/**
		 * نمایش پیام نتیجه (موفقیت یا خطا) در باکس نتیجه.
		 *
		 * @param {boolean} isSuccess آیا عملیات موفق بوده است.
		 * @param {string}  message  متن پیام جهت نمایش.
		 */
		function showResult( isSuccess, message ) {
			if ( ! resultBox ) {
				return;
			}

			resultBox.textContent = message;
			resultBox.classList.remove( 'notice-success', 'notice-error' );
			resultBox.classList.add( isSuccess ? 'notice-success' : 'notice-error' );
			resultBox.style.display = 'block';
		}

		/**
		 * مخفی‌سازی باکس نتیجه قبل از ارسال درخواست جدید.
		 */
		function hideResult() {
			if ( ! resultBox ) {
				return;
			}

			resultBox.style.display = 'none';
			resultBox.textContent = '';
		}
	}
} )();
