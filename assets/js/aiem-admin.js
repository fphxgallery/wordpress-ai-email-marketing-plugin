/* AI Email Marketing — Admin JS */
jQuery(function ($) {
	'use strict';

	var campaignId = parseInt($('#aiem-campaign-id').val() || '0', 10);

	// ── Char counters ───────────────────────────────────────────────────

	function initCharCounter($input, limit) {
		var $counter = $('<span class="aiem-char-counter"></span>').css({
			marginLeft: '8px', fontSize: '12px', color: '#888'
		});
		$input.after($counter);
		function update() {
			var len = $input.val().length;
			$counter.text(len + ' / ' + limit);
			$counter.css('color', len > limit ? '#dc2626' : '#888');
		}
		$input.on('input', update);
		update();
	}

	if ($('#aiem-subject').length)   initCharCounter($('#aiem-subject'),   60);
	if ($('#aiem-preheader').length) initCharCounter($('#aiem-preheader'), 100);

	// ── Regen subject & preview only ────────────────────────────────────

	$('#aiem-regen-subject-btn').on('click', function () {
		var prompt = $('#aiem-ai-prompt').val().trim();
		if (!prompt) {
			$('#aiem-generate-status').text('Please enter a prompt first.');
			return;
		}
		$('#aiem-regen-subject-btn').prop('disabled', true);
		$('#aiem-generate-spinner').show();
		$('#aiem-generate-status').text('Regenerating subject & preview…');
		$.post(aiemAdmin.ajaxUrl, {
			action: 'aiem_generate_subject',
			nonce:  aiemAdmin.nonce,
			prompt: prompt,
		}, function (res) {
			$('#aiem-regen-subject-btn').prop('disabled', false);
			$('#aiem-generate-spinner').hide();
			if (res.success) {
				if (res.data.subject)      { $('#aiem-subject').val(res.data.subject).trigger('input'); }
				if (res.data.preview_text) { $('#aiem-preheader').val(res.data.preview_text).trigger('input'); }
				$('#aiem-generate-status').text('Subject & preview regenerated.');
			} else {
				$('#aiem-generate-status').text('Error: ' + res.data.message);
			}
		}).fail(function () {
			$('#aiem-regen-subject-btn').prop('disabled', false);
			$('#aiem-generate-spinner').hide();
			$('#aiem-generate-status').text('Request failed.');
		});
	});

	// ── Auto-save draft ─────────────────────────────────────────────────

	if (campaignId) {
		setInterval(function () {
			if (!campaignId) { return; }
			doSave(function (res) {
				if (res && res.success) {
					var $status = $('#aiem-autosave-status');
					if (!$status.length) {
						$status = $('<span id="aiem-autosave-status" style="margin-left:12px;font-size:12px;color:#888;"></span>');
						$('#aiem-save-btn').after($status);
					}
					var now = new Date();
					$status.text('Auto-saved ' + now.toLocaleTimeString());
				}
			});
		}, 60000);
	}

	// ── Generate email ──────────────────────────────────────────────────

	$('#aiem-generate-btn').on('click', function () {
		var prompt = $('#aiem-ai-prompt').val().trim();
		if (!prompt) {
			$('#aiem-generate-status').text('Please enter a prompt first.');
			return;
		}

		var $tpl     = $('#aiem-load-template');
		var tplId    = $tpl.val() || 0;
		var tplName  = tplId ? $.trim($tpl.find(':selected').text()) : '';
		var current  = $.trim($('#aiem-html-content').val());
		if (current) {
			var msg = tplId
				? 'Fill the "' + tplName + '" layout in the HTML box with new AI content?\n\nYour layout and styles are kept; the text, images and links are replaced.'
				: 'No template is selected, so the AI will design its own layout and REPLACE everything in the HTML box.\n\nTo keep your design, pick it under "Load from Email Editor" first.\n\nContinue?';
			if (!confirm(msg)) { return; }
		}

		$('#aiem-generate-btn').prop('disabled', true);
		$('#aiem-generate-spinner').show();
		$('#aiem-generate-status').text(tplId ? 'Filling "' + tplName + '"…' : 'Generating…');

		$.post(aiemAdmin.ajaxUrl, {
			action:           'aiem_generate_email',
			nonce:            aiemAdmin.nonce,
			prompt:           prompt,
			use_woo:          $('#aiem-use-woo').is(':checked') ? 1 : 0,
			campaign_id:      campaignId,
			woo_category_ids: JSON.stringify($('#aiem-woo-category-ids').val() || []),
			woo_tag_ids:      JSON.stringify($('#aiem-woo-tag-ids').val() || []),
			template_id:      tplId,
			base_html:        tplId ? current : '',
		}, function (res) {
			$('#aiem-generate-btn').prop('disabled', false);
			$('#aiem-generate-spinner').hide();

			if (res.success) {
				$('#aiem-html-content').val(res.data.html);
				if (res.data.subject)      { $('#aiem-subject').val(res.data.subject); }
				if (res.data.preview_text) { $('#aiem-preheader').val(res.data.preview_text); }
				$('#aiem-generate-status').text(tplId
					? 'Filled "' + tplName + '" — subject, preview text and content generated. Review below.'
					: 'Subject, preview text and content generated (AI layout). Review below.');
				// Show HTML so user sees generated content
				if ($('#aiem-tab-html').length) { switchToHtml(); }
			} else {
				$('#aiem-generate-status').text('Error: ' + res.data.message);
			}
		}).fail(function () {
			$('#aiem-generate-btn').prop('disabled', false);
			$('#aiem-generate-spinner').hide();
			$('#aiem-generate-status').text('Request failed. Check your connection.');
		});
	});

	// ── Preview ─────────────────────────────────────────────────────────

	$('#aiem-preview-btn').on('click', function () {
		var html = $('#aiem-html-content').val();
		if (!html) { return; }
		$('#aiem-preview-container').show();
		var frame = document.getElementById('aiem-preview-frame');
		var doc = frame.contentDocument || frame.contentWindow.document;
		doc.open();
		doc.write(html);
		doc.close();
	});

	// ── Save ─────────────────────────────────────────────────────────────

	// Toggle WooCommerce filter panel with the checkbox
	$('#aiem-use-woo').on('change', function () {
		$('#aiem-woo-filters').toggle($(this).is(':checked'));
	});

	$('#aiem-save-btn').on('click', function () {
		doSave(function (res) {
			setResult(res.success, res.success ? res.data.message : res.data.message);
			if (res.success && !campaignId) {
				campaignId = res.data.campaign_id;
				$('#aiem-campaign-id').val(campaignId);
				history.replaceState(null, '', '?page=aiem-campaign-edit&campaign_id=' + campaignId);
			}
		});
	});

	function doSave(callback) {
		var audienceMode = $('input[name="aiem-audience-mode"]:checked').val() || 'list';
		$.post(aiemAdmin.ajaxUrl, {
			action:            'aiem_save_campaign',
			nonce:             aiemAdmin.nonce,
			campaign_id:       campaignId,
			name:              $('#aiem-name').val(),
			subject:           $('#aiem-subject').val(),
			preheader:         $('#aiem-preheader').val(),
			list_id:           audienceMode === 'list' ? $('#aiem-list-id').val() : '0',
			segment_id:        audienceMode === 'segment' ? $('#aiem-segment-id').val() : '0',
			from_name:         $('#aiem-from-name').val(),
			from_email:        $('#aiem-from-email').val(),
			ai_prompt:         $('#aiem-ai-prompt').val(),
			html_content:      $('#aiem-html-content').val(),
			recur_schedule:    $('#aiem-recur-schedule').val() || '',
			woo_category_ids:  JSON.stringify($('#aiem-woo-category-ids').val() || []),
			woo_tag_ids:       JSON.stringify($('#aiem-woo-tag-ids').val() || []),
			template_id:       $('#aiem-load-template').val() || 0,
		}, callback);
	}

	// ── Test send ─────────────────────────────────────────────────────────

	$('#aiem-test-send-btn').on('click', function () {
		if (!campaignId) {
			doSave(function (res) {
				if (res.success) {
					campaignId = res.data.campaign_id;
					$('#aiem-campaign-id').val(campaignId);
					sendTest();
				}
			});
		} else {
			sendTest();
		}
	});

	function sendTest() {
		var to = prompt('Send test to:', aiemAdmin.adminEmail);
		if (!to) { return; }
		setResult(null, 'Sending test…');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_test_send',
			nonce:       aiemAdmin.nonce,
			campaign_id: campaignId,
			to_email:    to,
		}, function (res) {
			setResult(res.success, res.data.message);
		});
	}

	// ── Send now ──────────────────────────────────────────────────────────

	$('#aiem-send-btn').on('click', function () {
		if (!confirm('Send this campaign now to all subscribers on the selected list?')) { return; }

		doSave(function (saveRes) {
			if (!saveRes.success) {
				setResult(false, saveRes.data.message);
				return;
			}
			campaignId = saveRes.data.campaign_id;
			setResult(null, 'Sending…');

			$.post(aiemAdmin.ajaxUrl, {
				action:      'aiem_send_campaign',
				nonce:       aiemAdmin.nonce,
				campaign_id: campaignId,
			}, function (res) {
				setResult(res.success, res.data.message);
			});
		});
	});

	// ── Schedule ──────────────────────────────────────────────────────────

	$('#aiem-schedule-btn').on('click', function () {
		var scheduledAt = $('#aiem-scheduled-at').val();
		if (!scheduledAt) {
			setResult(false, 'Pick a date and time first.');
			return;
		}

		doSave(function (saveRes) {
			if (!saveRes.success) {
				setResult(false, saveRes.data.message);
				return;
			}
			campaignId = saveRes.data.campaign_id;

			$.post(aiemAdmin.ajaxUrl, {
				action:       'aiem_schedule_campaign',
				nonce:        aiemAdmin.nonce,
				campaign_id:  campaignId,
				scheduled_at: scheduledAt,
			}, function (res) {
				setResult(res.success, res.data.message);
			});
		});
	});

	// ── Import subscribers ────────────────────────────────────────────────

	$('#aiem-import-btn').on('click', function () {
		var file = $('#aiem-import-file')[0].files[0];
		if (!file) {
			$('#aiem-import-result').text('Choose a CSV file first.');
			return;
		}

		var listId  = $(this).data('list-id');
		var formData = new FormData();
		formData.append('action',    'aiem_import_subscribers');
		formData.append('nonce',     aiemAdmin.nonce);
		formData.append('list_id',   listId);
		formData.append('csv_file',  file);

		$('#aiem-import-result').text('Importing…');

		$.ajax({
			url:         aiemAdmin.ajaxUrl,
			type:        'POST',
			data:        formData,
			processData: false,
			contentType: false,
			success: function (res) {
				if (res.success) {
					$('#aiem-import-result').text(res.data.message);
					setTimeout(function () { location.reload(); }, 1500);
				} else {
					$('#aiem-import-result').text('Error: ' + res.data.message);
				}
			},
			error: function () {
				$('#aiem-import-result').text('Upload failed.');
			},
		});
	});

	// ── Delete subscriber ─────────────────────────────────────────────────

	$(document).on('click', '.aiem-delete-subscriber', function () {
		if (!confirm('Delete this subscriber?')) { return; }
		var $btn = $(this);
		var id   = $btn.data('id');

		$.post(aiemAdmin.ajaxUrl, {
			action:        'aiem_delete_subscriber',
			nonce:         aiemAdmin.nonce,
			subscriber_id: id,
		}, function (res) {
			if (res.success) {
				$btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
			} else {
				alert(res.data.message);
			}
		});
	});

	// ── Bulk subscriber actions ──────────────────────────────────────────

	$('#aiem-select-all').on('change', function () {
		$('.aiem-sub-cb').prop('checked', $(this).is(':checked'));
	});

	$('#aiem-bulk-form').on('submit', function (e) {
		var action = $(this).find('[name="bulk_action"]').val();
		if ( ! action ) {
			e.preventDefault();
			alert('Please select a bulk action.');
			return;
		}
		var checked = $('.aiem-sub-cb:checked').length;
		if ( ! checked ) {
			e.preventDefault();
			alert('No subscribers selected.');
			return;
		}
		var label = action === 'delete' ? 'Delete' : 'Unsubscribe';
		if ( ! confirm( label + ' ' + checked + ' subscriber(s)?' ) ) {
			e.preventDefault();
		}
	});

	// ── Helpers ───────────────────────────────────────────────────────────

	function setResult(success, msg) {
		var $el = $('#aiem-action-result');
		$el.text(msg);
		$el.css('color', success === null ? '#6b7280' : (success ? '#166534' : '#dc2626'));
	}

	// ── Campaign audience toggle ──────────────────────────────────────────

	$('input[name="aiem-audience-mode"]').on('change', function () {
		if ($(this).val() === 'list') {
			$('#aiem-list-wrap').show();
			$('#aiem-segment-wrap').hide();
			$('#aiem-segment-id').val('');
		} else {
			$('#aiem-list-wrap').hide();
			$('#aiem-segment-wrap').show();
			$('#aiem-list-id').val('');
		}
	});

	// ── Segment builder ───────────────────────────────────────────────────

	var segCondIdx = 0;

	function segValueHtml(type) {
		if (type === 'status') {
			return '<select class="aiem-seg-val" style="min-width:150px">' +
				'<option value="subscribed">Subscribed</option>' +
				'<option value="unsubscribed">Unsubscribed</option>' +
				'<option value="unconfirmed">Unconfirmed</option>' +
				'<option value="bounced">Bounced</option>' +
				'</select>';
		}
		if (type === 'engagement') {
			return '<select class="aiem-seg-val" style="min-width:220px">' +
				'<option value="opened_any">Has opened any campaign</option>' +
				'<option value="never_opened">Has never opened</option>' +
				'<option value="clicked_any">Has clicked any campaign</option>' +
				'<option value="never_clicked">Has never clicked</option>' +
				'</select>';
		}
		if (type === 'subscribed_after' || type === 'subscribed_before') {
			return '<input type="date" class="aiem-seg-val" />';
		}
		return '';
	}

	function addSegConditionRow(typeVal, valVal) {
		typeVal = typeVal || 'status';
		var idx = segCondIdx++;
		var $row = $('<div class="aiem-seg-condition" style="display:flex;align-items:center;gap:8px;margin-bottom:8px"></div>');
		var $type = $('<select class="aiem-seg-type" style="min-width:160px">' +
			'<option value="status">Status</option>' +
			'<option value="engagement">Engagement</option>' +
			'<option value="subscribed_after">Subscribed after</option>' +
			'<option value="subscribed_before">Subscribed before</option>' +
			'</select>');
		$type.val(typeVal);
		var $valWrap = $('<span class="aiem-seg-val-wrap"></span>');
		$valWrap.html(segValueHtml(typeVal));
		if (valVal) { $valWrap.find('.aiem-seg-val').val(valVal); }
		var $remove = $('<button type="button" class="button-link aiem-danger" style="font-size:18px;line-height:1;padding:0 4px">&times;</button>');
		$remove.on('click', function () { $row.remove(); });
		$type.on('change', function () {
			$valWrap.html(segValueHtml($(this).val()));
		});
		$row.append($type).append($valWrap).append($remove);
		$('#seg-conditions').append($row);
	}

	$('#seg-add-condition').on('click', function () {
		addSegConditionRow();
	});

	function collectSegFilters() {
		var filters = [];
		$('#seg-conditions .aiem-seg-condition').each(function () {
			var type = $(this).find('.aiem-seg-type').val();
			var val  = $(this).find('.aiem-seg-val').val();
			if (type === 'status') {
				filters.push({type: 'status', value: val});
			} else if (type === 'engagement') {
				filters.push({type: 'engagement', operator: val});
			} else if (type === 'subscribed_after') {
				if (val) filters.push({type: 'subscribed_after', value: val});
			} else if (type === 'subscribed_before') {
				if (val) filters.push({type: 'subscribed_before', value: val});
			}
		});
		return filters;
	}

	$('#seg-preview-btn').on('click', function () {
		var listId = $('#seg-list-id').val();
		if (!listId) { $('#seg-preview-text').text('Select a list first.').css('color', '#dc2626'); return; }
		$('#seg-preview-text').text('Counting…').css('color', '#6b7280');
		$.post(aiemAdmin.ajaxUrl, {
			action:  'aiem_preview_segment',
			nonce:   aiemAdmin.nonce,
			list_id: listId,
			filters: JSON.stringify(collectSegFilters()),
		}, function (res) {
			if (res.success) {
				$('#seg-preview-text').text(res.data.count + ' subscriber(s) match.').css('color', '#166534');
			} else {
				$('#seg-preview-text').text('Error: ' + res.data.message).css('color', '#dc2626');
			}
		});
	});

	$('#seg-save-btn').on('click', function () {
		var name   = $('#seg-name').val().trim();
		var listId = $('#seg-list-id').val();
		var $result = $('#seg-save-result');
		if (!name || !listId) {
			$result.css('color', '#dc2626').text('Name and list are required.');
			return;
		}
		$(this).prop('disabled', true);
		$.post(aiemAdmin.ajaxUrl, {
			action:     'aiem_save_segment',
			nonce:      aiemAdmin.nonce,
			segment_id: $('#seg-id').val(),
			name:       name,
			list_id:    listId,
			filters:    JSON.stringify(collectSegFilters()),
		}, function (res) {
			$('#seg-save-btn').prop('disabled', false);
			if (res.success) {
				$result.css('color', '#166534').text(res.data.message + ' (' + res.data.count + ' subscribers)');
				setTimeout(function () { location.reload(); }, 1000);
			} else {
				$result.css('color', '#dc2626').text(res.data.message);
			}
		});
	});

	$(document).on('click', '.aiem-seg-delete', function () {
		if (!confirm('Delete this segment?')) { return; }
		var id = $(this).data('id');
		$.post(aiemAdmin.ajaxUrl, {
			action:     'aiem_delete_segment',
			nonce:      aiemAdmin.nonce,
			segment_id: id,
		}, function (res) {
			if (res.success) {
				$('#seg-row-' + id).fadeOut(300, function () { $(this).remove(); });
			}
		});
	});

	// ── Workflows ─────────────────────────────────────────────────────────

	var triggerDescs = {
		'subscriber_added':        'Fires when a new subscriber joins a list.',
		'subscriber_unsubscribed': 'Fires when a subscriber opts out.',
		'campaign_sent':           'Fires when a campaign finishes sending.',
		'post_published':          'Fires when a new post is published.',
	};

	var baseVars = '<code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{email}}</code> <code>{{site_name}}</code> <code>{{site_url}}</code> <code>{{DATE}}</code>';
	var extraVars = {
		'campaign_sent':  ' <code>{{SUBJECT}}</code> <code>{{COUNT}}</code>',
		'post_published': ' <code>{{post_title}}</code> <code>{{post_url}}</code> <code>{{post_excerpt}}</code> <code>{{post_author}}</code>',
	};

	$('#wf-trigger').on('change', function () {
		var val = $(this).val();

		$('#wf-trigger-desc').text(val ? (triggerDescs[val] || '') : '');

		// Rules section
		$('#wf-no-rules').hide();
		$('#wf-category-wrap').hide();
		$('#wf-filter-campaign-wrap').hide();
		$('#wf-category-id').val('');
		$('#wf-filter-campaign-id').val('0');
		$('#wf-action-list-id').val('');

		if (val === 'post_published') {
			$('#wf-category-wrap').show();
		} else if (val === 'campaign_sent') {
			$('#wf-filter-campaign-wrap').show();
		} else if (val) {
			$('#wf-no-rules').text('This trigger has no additional rules.').show();
		} else {
			$('#wf-no-rules').text('Select a trigger first to see available rules.').show();
		}

		$('#wf-content-vars').html('Variables: ' + baseVars + (extraVars[val] || ''));
	});

	function wfResetForm() {
		$('#wf-id').val('0');
		$('#wf-name').val('');
		$('#wf-trigger').val('').trigger('change');
		$('#wf-send-to').val('{{EMAIL}}');
		$('#wf-subject').val('');
		$('#wf-email-content').val('');
		$('#wf-email-styling').val('none');
		$('#wf-delay-value').val('0');
		$('#wf-delay-unit').val('minutes');
		$('#wf-form-heading').text('Create Workflow');
		$('#wf-save-btn').text('Create Workflow');
		$('#wf-cancel-btn').hide();
		$('#wf-save-result').text('');
	}

	$(document).on('click', '.aiem-wf-edit', function () {
		var wf = $(this).data('wf');
		$('#wf-id').val(wf.id);
		$('#wf-name').val(wf.name);
		$('#wf-trigger').val(wf.trigger_type).trigger('change');
		if (wf.trigger_type === 'campaign_sent') {
			$('#wf-filter-campaign-id').val((wf.trigger_config && wf.trigger_config.filter_campaign_id) ? wf.trigger_config.filter_campaign_id : '0');
		} else if (wf.trigger_type === 'post_published') {
			$('#wf-category-id').val((wf.trigger_config && wf.trigger_config.category_id) ? wf.trigger_config.category_id : '');
			$('#wf-action-list-id').val(wf.action_list_id || '');
		}
		$('#wf-send-to').val(wf.action_send_to || '{{EMAIL}}');
		$('#wf-subject').val(wf.action_subject || '');
		$('#wf-email-content').val(wf.action_content || '');
		$('#wf-email-styling').val(wf.action_email_styling || 'none');
		$('#wf-delay-value').val(wf.delay_value || 0);
		$('#wf-delay-unit').val(wf.delay_unit || 'minutes');
		$('#wf-form-heading').text('Edit Workflow');
		$('#wf-save-btn').text('Update Workflow');
		$('#wf-cancel-btn').show();
		$('#wf-save-result').text('');
		$('html, body').animate({ scrollTop: $('#wf-form-heading').offset().top - 40 }, 200);
	});

	$('#wf-cancel-btn').on('click', wfResetForm);

	$('#wf-save-btn').on('click', function () {
		var name    = $('#wf-name').val().trim();
		var trigger = $('#wf-trigger').val();
		var subject = $('#wf-subject').val().trim();
		var content = $('#wf-email-content').val().trim();
		var $result = $('#wf-save-result');

		if (!name || !trigger) {
			$result.css('color', '#dc2626').text('Name and trigger are required.');
			return;
		}
		if (!subject || !content) {
			$result.css('color', '#dc2626').text('Email subject and content are required.');
			return;
		}
		if (trigger === 'post_published' && !$('#wf-action-list-id').val()) {
			$result.css('color', '#dc2626').text('Post Published trigger requires a subscriber list.');
			return;
		}

		var postData = {
			action:               'aiem_save_workflow',
			nonce:                aiemAdmin.nonce,
			workflow_id:          $('#wf-id').val() || '0',
			name:                 name,
			trigger_type:         trigger,
			action_send_to:       $('#wf-send-to').val(),
			action_subject:       subject,
			action_content:       content,
			action_email_styling: $('#wf-email-styling').val(),
			action_list_id:       $('#wf-action-list-id').val() || '0',
			delay_value:          $('#wf-delay-value').val(),
			delay_unit:           $('#wf-delay-unit').val(),
		};
		if (trigger === 'post_published') {
			postData.category_id = $('#wf-category-id').val();
		} else if (trigger === 'campaign_sent') {
			postData.filter_campaign_id = $('#wf-filter-campaign-id').val();
		}

		$(this).prop('disabled', true);
		$.post(aiemAdmin.ajaxUrl, postData, function (res) {
			$('#wf-save-btn').prop('disabled', false);
			if (res.success) {
				$result.css('color', '#166534').text(res.data.message);
				setTimeout(function () { location.reload(); }, 800);
			} else {
				$result.css('color', '#dc2626').text(res.data.message);
			}
		});
	});

	$(document).on('click', '.aiem-wf-toggle', function () {
		var $btn = $(this);
		var id   = $btn.data('id');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_toggle_workflow',
			nonce:       aiemAdmin.nonce,
			workflow_id: id,
		}, function (res) {
			if (res.success) {
				location.reload();
			}
		});
	});

	$(document).on('click', '.aiem-wf-delete', function () {
		if (!confirm('Delete this workflow?')) { return; }
		var id = $(this).data('id');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_delete_workflow',
			nonce:       aiemAdmin.nonce,
			workflow_id: id,
		}, function (res) {
			if (res.success) {
				$('#wf-row-' + id).fadeOut(300, function () { $(this).remove(); });
			}
		});
	});

	// ── Form builder ─────────────────────────────────────────────────────────

	$('#aiem-field-gdpr').on('change', function () {
		$('#aiem-gdpr-text-wrap').toggle($(this).is(':checked'));
	});
	$('#aiem-field-first-name').on('change', function () {
		$(this).closest('td').find('.aiem-field-placeholder-wrap').toggle($(this).is(':checked'));
	});
	$('#aiem-field-last-name').on('change', function () {
		$(this).closest('td').find('.aiem-field-placeholder-wrap').toggle($(this).is(':checked'));
	});

	$('#aiem-form-save-btn').on('click', function () {
		var formId   = parseInt($('#aiem-form-id').val() || '0', 10);
		var name     = $('#aiem-form-name').val().trim();
		var listId   = $('#aiem-form-list-id').val();
		var $result  = $('#aiem-form-save-result');

		if (!name || !listId) {
			$result.css('color', '#dc2626').text('Name and list are required.');
			return;
		}

		var fields = [];
		if ($('#aiem-field-first-name').is(':checked')) {
			fields.push({type: 'first_name', placeholder: $('#aiem-fn-placeholder').val() || 'First name'});
		}
		if ($('#aiem-field-last-name').is(':checked')) {
			fields.push({type: 'last_name', placeholder: $('#aiem-ln-placeholder').val() || 'Last name'});
		}
		if ($('#aiem-field-gdpr').is(':checked')) {
			fields.push({type: 'gdpr', text: $('#aiem-gdpr-text').val() || 'I agree to receive email updates.'});
		}

		var settings = {
			button_text:      $('#aiem-form-btn-text').val()     || 'Subscribe',
			success_message:  $('#aiem-form-success-msg').val()  || "Thanks! You've been subscribed.",
			title:            $('#aiem-form-title').val()        || '',
			description:      $('#aiem-form-desc').val()         || '',
			button_color:     $('#aiem-form-btn-color-enabled').is(':checked') ? $('#aiem-form-btn-color').val() : '',
			button_text_color: $('#aiem-form-btn-txt-color-enabled').is(':checked') ? $('#aiem-form-btn-txt-color').val() : '',
		};

		$(this).prop('disabled', true);
		$.post(aiemAdmin.ajaxUrl, {
			action:   'aiem_save_form',
			nonce:    aiemAdmin.nonce,
			form_id:  formId,
			name:     name,
			list_id:  listId,
			fields:   JSON.stringify(fields),
			settings: JSON.stringify(settings),
		}, function (res) {
			$('#aiem-form-save-btn').prop('disabled', false);
			if (res.success) {
				$result.css('color', '#166534').text(res.data.message);
				if (!formId && res.data.form_id) {
					var sc = '[aiem_subscribe form_id="' + res.data.form_id + '"]';
					$('#aiem-form-id').val(res.data.form_id);
					if ($('#aiem-form-shortcode-display').length) {
						$('#aiem-form-shortcode-display').text(sc);
					} else {
						$('#aiem-form-save-btn').after(
							'<div class="aiem-panel" style="background:#f9fafb;margin-top:12px">' +
							'<strong>Shortcode:</strong> <code>' + sc + '</code>' +
							'<button type="button" class="button button-small aiem-copy-form-sc" data-shortcode="' + sc + '" style="margin-left:8px">Copy</button></div>'
						);
					}
				}
				// A new form must reload onto its own edit URL, or the page comes back blank as "New Form".
				var target = (!formId && res.data.form_id)
					? location.pathname + '?page=aiem-form-edit&form_id=' + parseInt(res.data.form_id, 10)
					: location.href;
				setTimeout(function () { location.href = target; }, 1000);
			} else {
				$result.css('color', '#dc2626').text(res.data.message);
			}
		});
	});

	$(document).on('click', '.aiem-form-delete', function () {
		if (!confirm('Delete this form?')) { return; }
		var id = $(this).data('id');
		$.post(aiemAdmin.ajaxUrl, {
			action:  'aiem_delete_form',
			nonce:   aiemAdmin.nonce,
			form_id: id,
		}, function (res) {
			if (res.success) {
				$('#aiem-form-row-' + id).fadeOut(300, function () { $(this).remove(); });
			}
		});
	});

	$(document).on('click', '.aiem-copy-form-sc, #aiem-form-copy-sc', function () {
		var sc = $(this).data('shortcode');
		if (navigator.clipboard) {
			navigator.clipboard.writeText(sc).then(function () {
				// silent success
			});
		} else {
			var ta = document.createElement('textarea');
			ta.value = sc;
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			document.body.removeChild(ta);
		}
		var $btn = $(this);
		var orig = $btn.text();
		$btn.text('Copied!');
		setTimeout(function () { $btn.text(orig); }, 1500);
	});

	// Campaign edit: load template into HTML textarea
	$('#aiem-apply-template').on('click', function () {
		var $sel = $('#aiem-load-template');
		var html = $sel.find(':selected').data('html');
		if (!html) { return; }
		if (!confirm('Replace current HTML content with this template?')) { return; }
		$('#aiem-html-content').val(html);
	});

	// Reports: resend to non-openers
	$('#aiem-resend-non-openers').on('click', function () {
		var btn        = $(this);
		var campaignId = btn.data('campaign');
		if (!confirm('Create a new draft campaign targeting non-openers of this campaign?')) { return; }
		btn.prop('disabled', true).text('Creating…');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_resend_non_openers',
			nonce:       aiemAdmin.nonce,
			campaign_id: campaignId,
		}, function (res) {
			if (res.success) {
				window.location.href = res.data.redirect;
			} else {
				alert(res.data.message || 'Error creating campaign.');
				btn.prop('disabled', false).text(btn.attr('data-original-text') || 'Resend to non-openers');
			}
		});
	});

	// Campaigns list: Re-send
	$(document).on('click', '.aiem-send-now-link', function (e) {
		e.preventDefault();
		var link       = $(this);
		var campaignId = link.data('campaign');
		if (!confirm('Send this campaign now, bypassing the scheduled time?')) { return; }
		link.text('Sending…');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_send_campaign',
			nonce:       aiemAdmin.nonce,
			campaign_id: campaignId,
		}, function (res) {
			if (res.success) {
				alert(res.data.message);
				window.location.reload();
			} else {
				alert(res.data.message || 'Send failed.');
				link.text('Send Now');
			}
		});
	});

	$(document).on('click', '.aiem-resend-link', function (e) {
		e.preventDefault();
		var link       = $(this);
		var campaignId = link.data('campaign');
		if (!confirm('Re-send this campaign to all current subscribers on the list? Old send records will be cleared.')) { return; }
		link.text('Sending…');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_resend_campaign',
			nonce:       aiemAdmin.nonce,
			campaign_id: campaignId,
		}, function (res) {
			if (res.success) {
				alert(res.data.message);
				window.location.reload();
			} else {
				alert(res.data.message || 'Re-send failed.');
				link.text('Re-send');
			}
		});
	});

	// Settings: reset prompts to default
	$('#aiem-reset-prompt').on('click', function () {
		if (confirm('Reset System Prompt to default?')) {
			$('#aiem-system-prompt').val(aiemAdmin.defaultPrompt);
		}
	});
	$('#aiem-reset-template-prompt').on('click', function () {
		if (confirm('Reset Template System Prompt to default?')) {
			$('#aiem-template-system-prompt').val(aiemAdmin.defaultTemplatePrompt);
		}
	});

	// Workflows: process queue now
	$('#aiem-process-queue-btn').on('click', function () {
		var btn = $(this);
		btn.prop('disabled', true).text('Processing…');
		$('#aiem-process-queue-result').text('');
		$.post(aiemAdmin.ajaxUrl, {
			action: 'aiem_process_workflow_queue',
			nonce:  aiemAdmin.nonce,
		}, function (res) {
			btn.prop('disabled', false).text('Process Queue Now');
			if (res.success) {
				$('#aiem-process-queue-result').text(res.data.message);
			} else {
				$('#aiem-process-queue-result').text('Error: ' + (res.data ? res.data.message : 'unknown'));
			}
		}).fail(function () {
			btn.prop('disabled', false).text('Process Queue Now');
			$('#aiem-process-queue-result').text('Request failed.');
		});
	});

	// Email Editor: duplicate template
	$(document).on('click', '.aiem-tpl-duplicate', function () {
		var $btn = $(this).prop('disabled', true);
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_duplicate_email_template',
			nonce:       aiemAdmin.nonce,
			template_id: $btn.data('id'),
		}, function (res) {
			if (res.success && res.data.redirect) {
				window.location = res.data.redirect;
			} else {
				$btn.prop('disabled', false);
				alert((res.data && res.data.message) || 'Could not duplicate template.');
			}
		}).fail(function () { $btn.prop('disabled', false); });
	});

	// Email Editor: delete template
	$(document).on('click', '.aiem-tpl-delete', function () {
		if (!confirm('Delete this template?')) { return; }
		var id = $(this).data('id');
		$.post(aiemAdmin.ajaxUrl, {
			action:      'aiem_delete_email_template',
			nonce:       aiemAdmin.nonce,
			template_id: id,
		}, function (res) {
			if (res.success) {
				$('#aiem-tpl-row-' + id).fadeOut(300, function () { $(this).remove(); });
			}
		});
	});
	// ── Settings → Sending: live pace estimate ─────────────────────────────
	(function () {
		var $pace = $('#aiem-pace');
		if (!$pace.length) { return; }
		var measured = parseFloat($pace.data('avg')) || 0;
		var avg      = measured > 0 ? measured : 1.5;
		var budget   = parseInt($pace.data('budget'), 10) || 20;
		var list     = parseInt($pace.data('list'), 10) || 0;

		function dur(sec) {
			if (sec < 60) { return Math.round(sec) + ' s'; }
			if (sec < 3600) { return Math.round(sec / 60) + ' min'; }
			var h = sec / 3600;
			return (h < 10 ? h.toFixed(1).replace(/\.0$/, '') : Math.round(h)) + ' h';
		}
		function item(value, label) {
			var $d = $('<div class="aiem-pace-item"></div>');
			$('<strong></strong>').text(value).appendTo($d);
			$('<span></span>').text(label).appendTo($d);
			return $d;
		}
		function update() {
			var batch  = Math.max(1, parseInt($('#aiem-batch-size').val(), 10) || 1);
			var delay  = Math.max(1, parseInt($('#aiem-batch-delay').val(), 10) || 1);
			var hourly = Math.max(0, parseInt($('#aiem-hourly-limit').val(), 10) || 0);

			$('#aiem-batch-delay-human').text(delay >= 60 ? '= ' + dur(delay) : '');

			var perBatch = Math.max(1, Math.min(batch, Math.floor(budget / avg)));
			if (hourly) { perBatch = Math.min(perBatch, hourly); }
			var cycle   = delay + perBatch * avg;
			var perHour = (3600 / cycle) * perBatch;
			var capped  = hourly && perHour > hourly;
			if (capped) { perHour = hourly; }

			$pace.empty()
				.append(item('~' + perBatch, perBatch < batch ? 'per batch (time limit)' : 'per batch'))
				.append(item('~' + Math.round(perHour).toLocaleString(), capped ? 'per hour (your cap)' : 'per hour'));
			if (list) {
				$pace.append(item(dur((list / perHour) * 3600), 'for ' + list.toLocaleString() + ' subscribers'));
			}
			if (perBatch < batch) {
				$('<div class="aiem-pace-note"></div>')
					.text('Batches stop after ' + budget + ' s, so only about ' + perBatch + ' of ' + batch + ' go out per batch at this mail server speed.')
					.appendTo($pace);
			}
		}
		$('#aiem-batch-size, #aiem-batch-delay, #aiem-hourly-limit').on('input change', update);
		update();
	})();
});
