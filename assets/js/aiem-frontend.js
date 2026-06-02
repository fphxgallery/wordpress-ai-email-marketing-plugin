/* AI Email Marketing — Frontend subscribe form */
jQuery(function ($) {
	'use strict';

	$('.aiem-form').on('submit', function (e) {
		e.preventDefault();

		var $form   = $(this);
		var $btn    = $form.find('.aiem-btn');
		var $msg    = $form.find('.aiem-form-message');
		var listId  = $form.data('list-id');
		var formId  = $form.data('form-id') || 0;

		$btn.prop('disabled', true).text('…');
		$msg.hide().removeClass('success error');

		$.post(aiemFrontend.ajaxUrl, {
			action:       'aiem_subscribe',
			nonce:        aiemFrontend.nonce,
			list_id:      listId,
			form_id:      formId,
			email:        $form.find('[name="email"]').val(),
			first_name:   $form.find('[name="first_name"]').val() || '',
			last_name:    $form.find('[name="last_name"]').val() || '',
			gdpr_consent: $form.find('[name="gdpr_consent"]').is(':checked') ? 1 : 0,
			aiem_hp:      $form.find('[name="aiem_hp"]').val() || '',
		}, function (res) {
			$btn.prop('disabled', false).text($btn.data('original-text') || 'Subscribe');

			if (res.success) {
				$msg.addClass('success').text(res.data.message).show();
				$form.find('input[type="email"]').val('');
				$form.find('input[type="text"]').val('');
				$form.find('input[type="checkbox"]').prop('checked', false);
			} else {
				$msg.addClass('error').text(res.data.message).show();
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			$msg.addClass('error').text('Something went wrong. Please try again.').show();
		});
	});

	$('.aiem-btn').each(function () {
		$(this).data('original-text', $(this).text());
	});
});
