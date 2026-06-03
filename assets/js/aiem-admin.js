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

		$('#aiem-generate-btn').prop('disabled', true);
		$('#aiem-generate-spinner').show();
		$('#aiem-generate-status').text('Generating…');

		$.post(aiemAdmin.ajaxUrl, {
			action:           'aiem_generate_email',
			nonce:            aiemAdmin.nonce,
			prompt:           prompt,
			use_woo:          $('#aiem-use-woo').is(':checked') ? 1 : 0,
			campaign_id:      campaignId,
			woo_category_ids: JSON.stringify($('#aiem-woo-category-ids').val() || []),
			woo_tag_ids:      JSON.stringify($('#aiem-woo-tag-ids').val() || []),
			template_id:      $('#aiem-load-template').val() || 0,
		}, function (res) {
			$('#aiem-generate-btn').prop('disabled', false);
			$('#aiem-generate-spinner').hide();

			if (res.success) {
				$('#aiem-html-content').val(res.data.html);
				if (res.data.subject)      { $('#aiem-subject').val(res.data.subject); }
				if (res.data.preview_text) { $('#aiem-preheader').val(res.data.preview_text); }
				$('#aiem-generate-status').text('Subject, preview text, and content generated. Review and edit below.');
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
		var inVisual = $('#aiem-visual-editor').is(':visible');
		var html = inVisual && aiemBlocks.length
			? renderBlocksToEmail(aiemBlocks)
			: $('#aiem-html-content').val();
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
				setTimeout(function () { location.reload(); }, 1000);
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

	// ── Visual email editor ───────────────────────────────────────────────────

	var aiemBlocks = [];
	var aiemSelectedId = null;
	var aiemDragSrcId  = null;

	var BLOCK_DEFAULTS = {
		heading: {text: 'Your Heading', level: 'h2', align: 'left', color: '#111827'},
		text:    {content: '<p>Your text goes here.</p>', align: 'left'},
		button:  {text: 'Click Here', url: '#', align: 'center', bg_color: '#7c3aed', text_color: '#ffffff'},
		image:   {src: '', alt: '', width: '100%', align: 'center', link_url: ''},
		divider: {color: '#e5e7eb', margin: 24},
		spacer:  {height: 32},
	};

	function genId() {
		return 'b_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
	}

	function escHtmlStr(s) {
		return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
	}
	function escAttrStr(s) {
		return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;');
	}



	function renderCanvas() {
		var $canvas = $('#aiem-block-canvas');
		$canvas.empty();
		if (!aiemBlocks.length) {
			$canvas.html('<p class="aiem-canvas-empty">Click a block type above to add it.</p>');
			return;
		}
		aiemBlocks.forEach(function (block) {
			var sel = block.id === aiemSelectedId ? ' selected' : '';
			var $row = $('<div class="aiem-block-row' + sel + '" draggable="true" data-id="' + escAttrStr(block.id) + '"></div>');
			$row.append('<span class="aiem-block-handle" title="Drag to reorder">⠿</span>');
			$row.append($('<div class="aiem-block-preview"></div>').html(blockPreviewHtml(block)));
			$row.append('<button type="button" class="aiem-block-del" data-id="' + escAttrStr(block.id) + '" title="Delete block">&times;</button>');
			$canvas.append($row);
		});
		setupDrag();
	}

	function blockPreviewHtml(b) {
		var p = b.props || {};
		switch (b.type) {
			case 'heading':
				var tag = p.level || 'h2';
				return '<' + tag + ' style="margin:0;color:' + escAttrStr(p.color || '#111827') + ';text-align:' + escAttrStr(p.align || 'left') + ';font-family:sans-serif">' + escHtmlStr(p.text) + '</' + tag + '>';
			case 'text':
				return '<div style="font-size:13px;color:#374151;text-align:' + escAttrStr(p.align || 'left') + '">' + (p.content || '') + '</div>';
			case 'button':
				return '<div style="text-align:' + escAttrStr(p.align || 'center') + '"><span style="background:' + escAttrStr(p.bg_color || '#7c3aed') + ';color:' + escAttrStr(p.text_color || '#fff') + ';padding:8px 20px;border-radius:5px;font-size:13px;display:inline-block">' + escHtmlStr(p.text || 'Button') + '</span></div>';
			case 'image':
				return p.src
					? '<img src="' + escAttrStr(p.src) + '" style="max-width:100%;max-height:80px;display:block;' + (p.align === 'center' ? 'margin:auto' : '') + '" />'
					: '<div style="background:#f3f4f6;color:#9ca3af;text-align:center;padding:14px;font-size:13px">🖼 Image (no URL set)</div>';
			case 'divider':
				return '<hr style="border:none;border-top:1px solid ' + escAttrStr(p.color || '#e5e7eb') + ';margin:4px 0" />';
			case 'spacer':
				var h = Math.min(parseInt(p.height) || 32, 40);
				return '<div style="background:repeating-linear-gradient(45deg,#f9fafb,#f9fafb 4px,#f3f4f6 4px,#f3f4f6 8px);height:' + h + 'px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#9ca3af">Spacer ' + (p.height || 32) + 'px</div>';
			default:
				return '';
		}
	}

	function selOpt(cur, val) { return cur === val ? ' selected' : ''; }

	function renderPropsPanel() {
		var $panel = $('#aiem-block-props');
		if (!aiemSelectedId) {
			$panel.html('<p style="color:#9ca3af;font-size:13px">Click a block to edit its properties.</p>');
			return;
		}
		var block = aiemBlocks.find(function (b) { return b.id === aiemSelectedId; });
		if (!block) { $panel.empty(); return; }
		var p = block.props || {};

		function row(label, ctrl) {
			return '<div class="aiem-prop-row"><label class="aiem-prop-label">' + label + '</label><div class="aiem-prop-control">' + ctrl + '</div></div>';
		}
		function alignSel(v) {
			return '<select data-prop="align" class="aiem-prop-input"><option value="left"' + selOpt(v,'left') + '>Left</option><option value="center"' + selOpt(v,'center') + '>Center</option><option value="right"' + selOpt(v,'right') + '>Right</option></select>';
		}

		var html = '';
		switch (block.type) {
			case 'heading':
				html += row('Text', '<input type="text" data-prop="text" class="aiem-prop-input" value="' + escAttrStr(p.text) + '" />');
				html += row('Level', '<select data-prop="level" class="aiem-prop-input"><option value="h1"' + selOpt(p.level,'h1') + '>H1</option><option value="h2"' + selOpt(p.level,'h2') + '>H2</option><option value="h3"' + selOpt(p.level,'h3') + '>H3</option></select>');
				html += row('Align', alignSel(p.align));
				html += row('Color', '<input type="color" data-prop="color" class="aiem-prop-input" value="' + escAttrStr(p.color || '#111827') + '" />');
				break;
			case 'text':
				html += row('Content', '<textarea data-prop="content" class="aiem-prop-input" rows="5" style="width:100%;font-size:12px;font-family:monospace">' + escHtmlStr(p.content) + '</textarea>');
				html += row('Align', alignSel(p.align));
				break;
			case 'button':
				html += row('Text', '<input type="text" data-prop="text" class="aiem-prop-input" value="' + escAttrStr(p.text) + '" />');
				html += row('URL', '<input type="text" data-prop="url" class="aiem-prop-input" value="' + escAttrStr(p.url) + '" />');
				html += row('Align', alignSel(p.align));
				html += row('Background', '<input type="color" data-prop="bg_color" class="aiem-prop-input" value="' + escAttrStr(p.bg_color || '#7c3aed') + '" />');
				html += row('Text Color', '<input type="color" data-prop="text_color" class="aiem-prop-input" value="' + escAttrStr(p.text_color || '#ffffff') + '" />');
				break;
			case 'image':
				html += row('Image URL', '<input type="text" data-prop="src" class="aiem-prop-input" value="' + escAttrStr(p.src) + '" placeholder="https://..." />');
				html += row('Alt text', '<input type="text" data-prop="alt" class="aiem-prop-input" value="' + escAttrStr(p.alt) + '" />');
				html += row('Width', '<input type="text" data-prop="width" class="aiem-prop-input" value="' + escAttrStr(p.width || '100%') + '" />');
				html += row('Align', alignSel(p.align));
				html += row('Link URL', '<input type="text" data-prop="link_url" class="aiem-prop-input" value="' + escAttrStr(p.link_url) + '" placeholder="https://..." />');
				break;
			case 'divider':
				html += row('Color', '<input type="color" data-prop="color" class="aiem-prop-input" value="' + escAttrStr(p.color || '#e5e7eb') + '" />');
				html += row('Margin (px)', '<input type="number" data-prop="margin" class="aiem-prop-input" value="' + (parseInt(p.margin) || 24) + '" min="0" max="100" />');
				break;
			case 'spacer':
				html += row('Height (px)', '<input type="number" data-prop="height" class="aiem-prop-input" value="' + (parseInt(p.height) || 32) + '" min="4" max="200" />');
				break;
		}
		$panel.html(html);

		$panel.find('.aiem-prop-input').on('input change', function () {
			var prop = $(this).data('prop');
			var val  = $(this).val();
			var blk  = aiemBlocks.find(function (b) { return b.id === aiemSelectedId; });
			if (blk) {
				blk.props[prop] = val;
				$('#aiem-block-canvas [data-id="' + blk.id + '"] .aiem-block-preview').html(blockPreviewHtml(blk));
			}
		});
	}

	function setupDrag() {
		$('#aiem-block-canvas .aiem-block-row').each(function () {
			var el = this;
			el.addEventListener('dragstart', function (e) {
				aiemDragSrcId = $(el).data('id');
				e.dataTransfer.effectAllowed = 'move';
				$(el).addClass('aiem-drag-src');
			});
			el.addEventListener('dragend', function () {
				$(el).removeClass('aiem-drag-src');
				$('.aiem-block-row').removeClass('aiem-drag-over');
			});
			el.addEventListener('dragover', function (e) {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				$('.aiem-block-row').removeClass('aiem-drag-over');
				$(el).addClass('aiem-drag-over');
			});
			el.addEventListener('drop', function (e) {
				e.preventDefault();
				var targetId = $(el).data('id');
				if (aiemDragSrcId && aiemDragSrcId !== targetId) {
					var si = aiemBlocks.findIndex(function (b) { return b.id === aiemDragSrcId; });
					var ti = aiemBlocks.findIndex(function (b) { return b.id === targetId; });
					var removed = aiemBlocks.splice(si, 1)[0];
					aiemBlocks.splice(ti, 0, removed);
					renderCanvas();
				}
			});
		});
	}

	// HTML email rendering from blocks
	function renderBlocksToEmail(blocks) {
		if (!blocks || !blocks.length) return '';
		var parts = blocks.map(function (b) { return blockToEmailHtml(b); }).filter(Boolean);
		return '<div style="max-width:600px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;">\n' + parts.join('\n') + '\n</div>';
	}

	function blockToEmailHtml(b) {
		var p = b.props || {};
		function ea(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
		function eh(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
		switch (b.type) {
			case 'heading':
				var tag = p.level || 'h2';
				return '<' + tag + ' style="text-align:' + ea(p.align||'left') + ';color:' + ea(p.color||'#111827') + ';margin:0 0 16px;padding:0;font-family:Arial,Helvetica,sans-serif;line-height:1.3;">' + eh(p.text||'') + '</' + tag + '>';
			case 'text':
				return '<div style="text-align:' + ea(p.align||'left') + ';color:#374151;font-size:16px;line-height:1.6;margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;">' + (p.content||'') + '</div>';
			case 'button':
				var ml = p.align === 'right' ? 'margin-left:auto;' : (p.align === 'center' ? 'margin:0 auto;' : '');
				return '<div style="text-align:' + ea(p.align||'center') + ';margin:0 0 20px;">' +
					'<a href="' + ea(p.url||'#') + '" style="display:inline-block;background-color:' + ea(p.bg_color||'#7c3aed') + ';color:' + ea(p.text_color||'#ffffff') + ';padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:600;font-family:Arial,Helvetica,sans-serif;font-size:16px;">' + eh(p.text||'Click Here') + '</a></div>';
			case 'image':
				var iSrc = p.src || '';
				var iAlign = p.align === 'center' ? 'margin:0 auto;' : (p.align === 'right' ? 'margin-left:auto;' : '');
				var img = '<img src="' + ea(iSrc) + '" alt="' + ea(p.alt||'') + '" style="max-width:' + ea(p.width||'100%') + ';height:auto;display:block;' + iAlign + '" />';
				if (p.link_url) img = '<a href="' + ea(p.link_url) + '">' + img + '</a>';
				return '<div style="margin:0 0 16px;">' + img + '</div>';
			case 'divider':
				return '<hr style="border:none;border-top:1px solid ' + ea(p.color||'#e5e7eb') + ';margin:' + (parseInt(p.margin)||24) + 'px 0;" />';
			case 'spacer':
				return '<div style="height:' + (parseInt(p.height)||32) + 'px;line-height:' + (parseInt(p.height)||32) + 'px;">&nbsp;</div>';
			default:
				return '';
		}
	}

	// Tab switching (Email Editor page)
	function switchToVisual() {
		$('#aiem-tab-visual').addClass('active');
		$('#aiem-tab-html').removeClass('active');
		$('#aiem-visual-editor').show();
		$('#aiem-html-editor-wrap').hide();
	}
	function switchToHtml() {
		if (aiemBlocks.length) {
			$('#aiem-html-content').val(renderBlocksToEmail(aiemBlocks));
		}
		$('#aiem-tab-visual').removeClass('active');
		$('#aiem-tab-html').addClass('active');
		$('#aiem-visual-editor').hide();
		$('#aiem-html-editor-wrap').show();
	}

	// Init visual editor on Email Template Edit page
	if ($('#aiem-template-blocks-json').length) {
		var raw = $('#aiem-template-blocks-json').val() || '[]';
		try { aiemBlocks = JSON.parse(raw) || []; } catch(e) { aiemBlocks = []; }
		if (!Array.isArray(aiemBlocks)) aiemBlocks = [];

		if (aiemBlocks.length) {
			switchToVisual();
		} else if ($('#aiem-html-content').val().trim()) {
			switchToHtml();
		} else {
			switchToVisual();
		}

		$('#aiem-tab-visual').on('click', switchToVisual);
		$('#aiem-tab-html').on('click', switchToHtml);

		$('.aiem-palette-btn').on('click', function () {
			var type = $(this).data('block');
			var def  = BLOCK_DEFAULTS[type];
			if (!def) return;
			aiemBlocks.push({id: genId(), type: type, props: jQuery.extend({}, def)});
			aiemSelectedId = aiemBlocks[aiemBlocks.length - 1].id;
			renderCanvas();
			renderPropsPanel();
		});

		$(document).on('click', '#aiem-block-canvas .aiem-block-row', function (e) {
			if ($(e.target).hasClass('aiem-block-del')) return;
			aiemSelectedId = $(this).data('id');
			renderCanvas();
			renderPropsPanel();
		});
		$(document).on('click', '.aiem-block-del', function (e) {
			e.stopPropagation();
			var id = $(this).data('id');
			aiemBlocks = aiemBlocks.filter(function (b) { return b.id !== id; });
			if (aiemSelectedId === id) aiemSelectedId = null;
			renderCanvas();
			renderPropsPanel();
		});

		// Save template
		$('#aiem-template-save-btn').on('click', function () {
			var name = $('#aiem-template-name').val().trim();
			var $result = $('#aiem-template-save-result');
			if (!name) {
				$result.css('color', '#dc2626').text('Template name is required.');
				return;
			}
			var inVisual = $('#aiem-visual-editor').is(':visible');
			if (inVisual && aiemBlocks.length) {
				$('#aiem-html-content').val(renderBlocksToEmail(aiemBlocks));
			}
			var blocksJson = inVisual ? JSON.stringify(aiemBlocks) : '[]';
			var templateId = parseInt($('#aiem-template-id').val() || '0', 10);

			$(this).prop('disabled', true);
			$.post(aiemAdmin.ajaxUrl, {
				action:       'aiem_save_email_template',
				nonce:        aiemAdmin.nonce,
				template_id:  templateId,
				name:         name,
				blocks:       blocksJson,
				html_content: $('#aiem-html-content').val(),
			}, function (res) {
				$('#aiem-template-save-btn').prop('disabled', false);
				if (res.success) {
					$result.css('color', '#166534').text(res.data.message);
					if (!templateId && res.data.template_id) {
						$('#aiem-template-id').val(res.data.template_id);
						history.replaceState(null, '', '?page=aiem-email-template-edit&template_id=' + res.data.template_id);
						$('#aiem-template-save-btn').text('Update Template');
					}
				} else {
					$result.css('color', '#dc2626').text(res.data.message);
				}
			});
		});

		renderCanvas();
		renderPropsPanel();
	}

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
});
