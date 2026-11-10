/* AI Email Marketing — three-pane email template editor */
(function ($) {
	'use strict';

	var dataEl = document.getElementById('aiem-ee-data');
	if (!dataEl) { return; }

	var boot = {};
	try { boot = JSON.parse(dataEl.textContent || '{}') || {}; } catch (e) { boot = {}; }

	// ── Constants ────────────────────────────────────────────────────────────

	var KNOWN_TAGS = [
		'first_name', 'last_name', 'full_name', 'email', 'site_name', 'site_url',
		'unsubscribe_url', 'preheader', 'content',
		'EMAIL', 'post_title', 'post_url', 'post_excerpt', 'post_author', 'DATE', 'SUBJECT', 'COUNT'
	];
	var INSERT_TAGS = ['first_name', 'last_name', 'full_name', 'email', 'site_name', 'site_url', 'unsubscribe_url'];

	var FONTS = {
		'Arial, Helvetica, sans-serif': 'Arial',
		'Verdana, Geneva, sans-serif': 'Verdana',
		'Tahoma, Geneva, sans-serif': 'Tahoma',
		"'Trebuchet MS', Arial, sans-serif": 'Trebuchet',
		'Ubuntu, Arial, sans-serif': 'Ubuntu',
		"Georgia, 'Times New Roman', serif": 'Georgia',
		"'Courier New', Courier, monospace": 'Courier'
	};

	var STYLE_LIGHT = {
		bodyBg: '#f3f4f6', contentBg: '#ffffff', width: 600,
		font: 'Arial, Helvetica, sans-serif',
		textColor: '#374151', headingColor: '#111827', linkColor: '#7c3aed', padX: 24
	};
	var STYLE_DARK = {
		bodyBg: '#000000', contentBg: '#000000', width: 600,
		font: 'Ubuntu, Arial, sans-serif',
		textColor: '#d1d5db', headingColor: '#ffffff', linkColor: '#ffffff', padX: 24
	};
	// Templates saved by the old editor had no page background.
	var STYLE_LEGACY = $.extend({}, STYLE_LIGHT, { bodyBg: '#ffffff', padX: 0 });

	function col() { return { src: '', alt: '', title: 'Column title', text: 'Your text goes here.', btn: '', url: '' }; }

	// Default text matches the placeholders the AI template prompt looks for.
	var DEFS = {
		heading: { label: 'Heading', icon: 'heading',            group: 'Content',    props: { text: 'Your Heading', level: 'h2', align: 'left', color: '', py: 10 } },
		text:    { label: 'Text',    icon: 'editor-paragraph',   group: 'Content',    props: { content: 'Your text goes here.', align: 'left', color: '', size: 16, py: 10 } },
		image:   { label: 'Image',   icon: 'format-image',       group: 'Content',    props: { src: '', alt: '', width: 100, align: 'center', link_url: '', py: 10 } },
		button:  { label: 'Button',  icon: 'button',             group: 'Content',    props: { text: 'Click Here', url: '', align: 'center', bg_color: '#7c3aed', text_color: '#ffffff', radius: 6, py: 12 } },
		columns: { label: 'Columns', icon: 'columns',            group: 'Layout',     props: { left: col(), right: col(), py: 10 } },
		divider: { label: 'Divider', icon: 'minus',              group: 'Layout',     props: { color: '#e5e7eb', thickness: 1, margin: 16 } },
		spacer:  { label: 'Spacer',  icon: 'image-flip-vertical', group: 'Layout',    props: { height: 32 } },
		product: { label: 'Product', icon: 'cart',               group: 'Ready-made', props: { src: '', alt: '', title: 'Product title', price: '', text: 'Product text', btn: 'Click Here', url: '', align: 'center', bg_color: '#7c3aed', text_color: '#ffffff', py: 12 } },
		social:  { label: 'Social',  icon: 'share',              group: 'Ready-made', props: { label1: 'Instagram', url1: '', label2: 'Facebook', url2: '', label3: '', url3: '', label4: '', url4: '', align: 'center', color: '', py: 10 } },
		footer:  { label: 'Footer',  icon: 'align-full-width',   group: 'Ready-made', props: { text: 'Your footer', unsub_label: 'Unsubscribe', align: 'center', color: '#9ca3af', size: 12, py: 16 } }
	};

	function colFields(side, name) {
		return [
			[side + '.src',   name + ' image URL', 'image'],
			[side + '.alt',   name + ' alt text', 'text'],
			[side + '.title', name + ' title', 'text'],
			[side + '.text',  name + ' text', 'textarea'],
			[side + '.btn',   name + ' button label', 'text'],
			[side + '.url',   name + ' button link', 'url']
		];
	}

	// [path, label, control, ...options]
	var FIELDS = {
		heading: [['text', 'Text', 'text'], ['level', 'Level', 'select', { h1: 'Large (H1)', h2: 'Medium (H2)', h3: 'Small (H3)' }], ['align', 'Align', 'align'], ['color', 'Color', 'color', 'headingColor'], ['py', 'Spacing', 'range', 0, 48]],
		text:    [['size', 'Text size', 'range', 12, 24], ['align', 'Align', 'align'], ['color', 'Color', 'color', 'textColor'], ['py', 'Spacing', 'range', 0, 48]],
		image:   [['src', 'Image URL', 'image'], ['alt', 'Alt text', 'text'], ['width', 'Width %', 'range', 10, 100], ['align', 'Align', 'align'], ['link_url', 'Link', 'url'], ['py', 'Spacing', 'range', 0, 48]],
		button:  [['text', 'Label', 'text'], ['url', 'Link', 'url'], ['align', 'Align', 'align'], ['bg_color', 'Background', 'color'], ['text_color', 'Text color', 'color'], ['radius', 'Corner radius', 'range', 0, 30], ['py', 'Spacing', 'range', 0, 48]],
		columns: colFields('left', 'Left').concat(colFields('right', 'Right'), [['py', 'Spacing', 'range', 0, 48]]),
		divider: [['color', 'Color', 'color'], ['thickness', 'Thickness', 'range', 1, 6], ['margin', 'Spacing', 'range', 0, 60]],
		spacer:  [['height', 'Height', 'range', 4, 120]],
		product: [['src', 'Image URL', 'image'], ['alt', 'Alt text', 'text'], ['title', 'Title', 'text'], ['price', 'Price', 'text'], ['text', 'Description', 'textarea'], ['btn', 'Button label', 'text'], ['url', 'Button link', 'url'], ['align', 'Align', 'align'], ['bg_color', 'Button background', 'color'], ['text_color', 'Button text', 'color'], ['py', 'Spacing', 'range', 0, 48]],
		social:  [['label1', 'Link 1 label', 'text'], ['url1', 'Link 1 URL', 'url'], ['label2', 'Link 2 label', 'text'], ['url2', 'Link 2 URL', 'url'], ['label3', 'Link 3 label', 'text'], ['url3', 'Link 3 URL', 'url'], ['label4', 'Link 4 label', 'text'], ['url4', 'Link 4 URL', 'url'], ['align', 'Align', 'align'], ['color', 'Color', 'color', 'linkColor'], ['py', 'Spacing', 'range', 0, 48]],
		footer:  [['text', 'Text', 'textarea'], ['unsub_label', 'Unsubscribe label', 'text'], ['align', 'Align', 'align'], ['color', 'Color', 'color'], ['size', 'Text size', 'range', 10, 16], ['py', 'Spacing', 'range', 0, 48]]
	};

	// Which prop "Rewrite with AI" works on: [path, isRichHtml]
	var AI_FIELD = { heading: ['text', false], text: ['content', true], product: ['text', false], footer: ['text', false] };

	var STARTERS = {
		blank: [],
		announcement: [
			['heading', { text: 'Your Heading', level: 'h1', align: 'center' }],
			['text', { align: 'center' }],
			['button', {}],
			['footer', {}]
		],
		product: [
			['image', { alt: 'Logo', width: 40 }],
			['product', {}],
			['divider', {}],
			['footer', {}]
		],
		newsletter: [
			['heading', { level: 'h1' }],
			['text', {}],
			['columns', {}],
			['divider', {}],
			['footer', {}]
		]
	};

	// ── State ────────────────────────────────────────────────────────────────

	var state = { blocks: [], style: $.extend({}, STYLE_LIGHT) };
	var mode = 'visual';      // 'visual' = blocks, 'html' = hand-written HTML
	var htmlSource = '';      // HTML-mode source
	var showCode = false;     // visual mode: showing the generated HTML
	var selectedId = null;
	var tab = 'block';
	var templateId = parseInt(boot.id, 10) || 0;
	var history = [], future = [], savedSnap = '';
	var drag = null;

	function clone(o) { return JSON.parse(JSON.stringify(o)); }
	function genId() { return 'b_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 5); }
	function makeBlock(type, overrides) {
		return { id: genId(), type: type, props: $.extend(true, {}, clone(DEFS[type].props), overrides || {}) };
	}
	function findBlock(id) {
		for (var i = 0; i < state.blocks.length; i++) { if (state.blocks[i].id === id) { return state.blocks[i]; } }
		return null;
	}
	function indexOfBlock(id) {
		for (var i = 0; i < state.blocks.length; i++) { if (state.blocks[i].id === id) { return i; } }
		return -1;
	}
	function getPath(obj, path) {
		var parts = path.split('.'), cur = obj;
		for (var i = 0; i < parts.length; i++) { if (cur == null) { return ''; } cur = cur[parts[i]]; }
		return cur == null ? '' : cur;
	}
	function setPath(obj, path, val) {
		var parts = path.split('.'), cur = obj;
		for (var i = 0; i < parts.length - 1; i++) {
			if (typeof cur[parts[i]] !== 'object' || cur[parts[i]] === null) { cur[parts[i]] = {}; }
			cur = cur[parts[i]];
		}
		cur[parts[parts.length - 1]] = val;
	}

	(function load() {
		var raw = boot.blocks, blocks = [], style = null;
		if (Array.isArray(raw)) {
			blocks = raw; style = STYLE_LEGACY;
		} else if (raw && typeof raw === 'object' && Array.isArray(raw.blocks)) {
			blocks = raw.blocks; style = raw.style || STYLE_LIGHT;
		}
		blocks = blocks.filter(function (b) { return b && DEFS[b.type]; }).map(function (b) {
			return { id: b.id || genId(), type: b.type, props: $.extend(true, {}, clone(DEFS[b.type].props), b.props || {}) };
		});
		htmlSource = String(boot.html || '');

		if (blocks.length) {
			state.blocks = blocks;
			state.style = $.extend({}, STYLE_LIGHT, style);
		} else if (htmlSource.trim() || boot.starter === 'html') {
			mode = 'html';
		} else {
			var starter = STARTERS[boot.starter] || [];
			state.blocks = starter.map(function (s) { return makeBlock(s[0], s[1]); });
		}
	})();

	// ── HTML helpers ─────────────────────────────────────────────────────────

	function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
	function escA(s) { return esc(s).replace(/"/g, '&quot;'); }
	function num(v, d) { var n = parseInt(v, 10); return isNaN(n) ? d : n; }
	function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }

	// Email clients ignore stylesheets for links, so color them inline.
	function styleLinks(html, color) {
		if (String(html).indexOf('<a') === -1) { return html; }
		var div = document.createElement('div');
		div.innerHTML = html;
		Array.prototype.forEach.call(div.querySelectorAll('a'), function (a) {
			a.style.color = color;
			a.style.textDecoration = 'underline';
		});
		return div.innerHTML;
	}

	/**
	 * Inner HTML of one block. With edit=true the same markup carries
	 * contenteditable hooks, so the canvas is what the email will look like.
	 */
	function blockInner(b, st, edit) {
		var p = b.props || {};
		var font = st.font;

		function ed(path, rich) {
			return edit ? ' data-edit="' + path + '" contenteditable="true"' + (rich ? ' data-rich="1"' : '') : '';
		}
		function img(src, alt, widthPct, align, link) {
			if (edit && !src) { return '<div class="ph">Image: add a URL in the panel</div>'; }
			var m = align === 'center' ? 'margin:0 auto;' : (align === 'right' ? 'margin:0 0 0 auto;' : '');
			var w = widthPct && widthPct < 100 ? 'width:' + widthPct + '%;max-width:100%;' : 'max-width:100%;';
			var h = '<img src="' + escA(src) + '" alt="' + escA(alt) + '" style="' + w + 'height:auto;display:block;border:0;' + m + '" />';
			return link ? '<a href="' + escA(link) + '">' + h + '</a>' : h;
		}
		function btn(text, url, bg, fg, radius, path) {
			return '<a href="' + escA(url || '#') + '" style="display:inline-block;background-color:' + escA(bg || '#7c3aed') + ';color:' + escA(fg || '#ffffff') +
				';padding:13px 30px;border-radius:' + num(radius, 6) + 'px;text-decoration:none;font-weight:bold;font-family:' + font + ';font-size:16px;line-height:1.2;"' + ed(path) + '>' + esc(text) + '</a>';
		}
		function body(text, path, align, color, size) {
			return '<div style="font-family:' + font + ';font-size:' + (size || 16) + 'px;line-height:1.6;color:' + escA(color || st.textColor) + ';text-align:' + escA(align || 'left') + ';"' + ed(path) + '>' + nl2br(text) + '</div>';
		}

		switch (b.type) {
			case 'heading':
				var tag = /^h[1-3]$/.test(p.level) ? p.level : 'h2';
				var hs = { h1: 30, h2: 24, h3: 19 }[tag];
				return '<' + tag + ' style="margin:0;padding:0;font-family:' + font + ';font-size:' + hs + 'px;line-height:1.3;color:' + escA(p.color || st.headingColor) + ';text-align:' + escA(p.align || 'left') + ';"' + ed('text') + '>' + esc(p.text) + '</' + tag + '>';

			case 'text':
				return '<div style="font-family:' + font + ';font-size:' + num(p.size, 16) + 'px;line-height:1.6;color:' + escA(p.color || st.textColor) + ';text-align:' + escA(p.align || 'left') + ';"' + ed('content', true) + '>' + styleLinks(p.content || '', st.linkColor) + '</div>';

			case 'image':
				return img(p.src, p.alt, num(p.width, 100), p.align, p.link_url);

			case 'button':
				return '<div style="text-align:' + escA(p.align || 'center') + ';">' + btn(p.text, p.url, p.bg_color, p.text_color, p.radius, 'text') + '</div>';

			case 'divider':
				return '<hr style="border:none;border-top:' + num(p.thickness, 1) + 'px solid ' + escA(p.color || '#e5e7eb') + ';margin:0;" />';

			case 'spacer':
				var h = num(p.height, 32);
				return edit
					? '<div class="sp" style="height:' + h + 'px;">Spacer ' + h + 'px</div>'
					: '<div style="height:' + h + 'px;line-height:' + h + 'px;font-size:1px;">&nbsp;</div>';

			case 'columns':
				var cell = function (side, pad) {
					var c = p[side] || {};
					var out = '';
					if (c.src || edit) { out += '<div style="margin:0 0 10px;">' + img(c.src, c.alt, 100, 'center', '') + '</div>'; }
					out += '<div style="font-family:' + font + ';font-size:18px;line-height:1.3;font-weight:bold;color:' + st.headingColor + ';margin:0 0 6px;"' + ed(side + '.title') + '>' + esc(c.title) + '</div>';
					out += '<div style="font-family:' + font + ';font-size:15px;line-height:1.6;color:' + st.textColor + ';"' + ed(side + '.text') + '>' + nl2br(c.text) + '</div>';
					if (c.btn) { out += '<div style="margin:10px 0 0;"><a href="' + escA(c.url || '#') + '" style="font-family:' + font + ';font-size:15px;font-weight:bold;color:' + st.linkColor + ';text-decoration:underline;"' + ed(side + '.btn') + '>' + esc(c.btn) + '</a></div>'; }
					return '<td class="aiem-col" width="50%" valign="top" style="width:50%;vertical-align:top;padding:' + pad + ';">' + out + '</td>';
				};
				return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' + cell('left', '0 10px 0 0') + cell('right', '0 0 0 10px') + '</tr></table>';

			case 'product':
				var al = p.align || 'center';
				var out = '';
				// Always output the image, even with no URL: an empty <img src="">
				// is what the AI fills in when this template is used as a scaffold.
				out += '<div style="margin:0 0 14px;">' + img(p.src, p.alt, 100, al, p.url) + '</div>';
				out += '<div style="font-family:' + font + ';font-size:22px;line-height:1.3;font-weight:bold;color:' + st.headingColor + ';text-align:' + al + ';margin:0 0 6px;"' + ed('title') + '>' + esc(p.title) + '</div>';
				if (p.price || edit) { out += '<div style="font-family:' + font + ';font-size:17px;line-height:1.4;color:' + st.textColor + ';text-align:' + al + ';margin:0 0 8px;"' + ed('price') + '>' + esc(p.price) + '</div>'; }
				out += '<div style="margin:0 0 16px;">' + body(p.text, 'text', al) + '</div>';
				if (p.btn) { out += '<div style="text-align:' + al + ';">' + btn(p.btn, p.url, p.bg_color, p.text_color, 6, 'btn') + '</div>'; }
				return out;

			case 'social':
				var links = [];
				for (var i = 1; i <= 4; i++) {
					if (p['label' + i]) {
						links.push('<a href="' + escA(p['url' + i] || '#') + '" style="color:' + escA(p.color || st.linkColor) + ';text-decoration:underline;">' + esc(p['label' + i]) + '</a>');
					}
				}
				return '<div style="font-family:' + font + ';font-size:14px;line-height:1.6;color:' + escA(p.color || st.linkColor) + ';text-align:' + escA(p.align || 'center') + ';">' + links.join(' &nbsp;&middot;&nbsp; ') + '</div>';

			case 'footer':
				var fc = escA(p.color || '#9ca3af');
				return '<div style="font-family:' + font + ';font-size:' + num(p.size, 12) + 'px;line-height:1.6;color:' + fc + ';text-align:' + escA(p.align || 'center') + ';">' +
					'<div' + ed('text') + '>' + nl2br(p.text) + '</div>' +
					'<div style="margin-top:6px;"><a href="{{unsubscribe_url}}" style="color:' + fc + ';text-decoration:underline;">' + esc(p.unsub_label || 'Unsubscribe') + '</a></div></div>';
		}
		return '';
	}

	function blockPadding(b, st) {
		var p = b.props || {};
		var py = b.type === 'divider' ? num(p.margin, 16) : (b.type === 'spacer' ? 0 : num(p.py, 10));
		return py + 'px ' + num(st.padX, 24) + 'px';
	}

	/** The complete email document sent to subscribers. */
	function renderEmail() {
		var st = state.style, w = num(st.width, 600);
		var rows = state.blocks.map(function (b) {
			return '<tr><td style="padding:' + blockPadding(b, st) + ';">' + blockInner(b, st, false) + '</td></tr>';
		}).join('\n');
		return '<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="UTF-8">\n<meta name="viewport" content="width=device-width, initial-scale=1.0">\n' +
			'<style>@media only screen and (max-width:480px){.aiem-col{display:block!important;width:100%!important;padding:0 0 16px!important;}}</style>\n</head>\n' +
			'<body style="margin:0;padding:0;background-color:' + st.bodyBg + ';">\n' +
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' + st.bodyBg + ';"><tr><td align="center" style="padding:24px 12px;">\n' +
			'<table role="presentation" width="' + w + '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' + w + 'px;background-color:' + st.contentBg + ';">\n' +
			rows + '\n</table>\n</td></tr></table>\n</body>\n</html>';
	}

	function currentHtml() { return mode === 'visual' ? renderEmail() : htmlSource; }

	// ── History / dirty tracking ─────────────────────────────────────────────

	function snap() { return JSON.stringify({ mode: mode, state: state, html: htmlSource, name: $('#aiem-ee-name').val() }); }
	var lastSnap = '';
	var commitTimer = null;

	function commit() {
		clearTimeout(commitTimer);
		var s = JSON.stringify(state);
		if (s === lastSnap) { refreshStatus(); return; }
		history.push(lastSnap);
		if (history.length > 80) { history.shift(); }
		future = [];
		lastSnap = s;
		refreshStatus();
		scheduleChecks();
	}
	function commitSoon() {
		clearTimeout(commitTimer);
		commitTimer = setTimeout(commit, 500);
		refreshStatus(true);
	}
	function restore(s) {
		state = JSON.parse(s);
		lastSnap = s;
		if (selectedId && !findBlock(selectedId)) { selectedId = null; }
		renderCanvas();
		renderPanel();
		refreshStatus();
		scheduleChecks();
	}
	function undo() {
		commit();
		if (!history.length) { return; }
		future.push(lastSnap);
		restore(history.pop());
	}
	function redo() {
		if (!future.length) { return; }
		history.push(lastSnap);
		restore(future.pop());
	}
	function isDirty() { return snap() !== savedSnap; }
	function refreshStatus(forceDirty) {
		$('#aiem-ee-undo').prop('disabled', mode !== 'visual' || !history.length);
		$('#aiem-ee-redo').prop('disabled', mode !== 'visual' || !future.length);
		var $s = $('#aiem-ee-status');
		if ($s.data('busy')) { return; }
		if (forceDirty || isDirty()) { $s.attr('class', 'aiem-ee-status is-dirty').text('Unsaved changes'); }
		else { $s.attr('class', 'aiem-ee-status').text(templateId ? 'Saved' : ''); }
	}

	// ── Canvas (shadow DOM so WP admin CSS cannot leak into the email) ───────

	var host = document.getElementById('aiem-ee-canvas');
	var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

	var CANVAS_CSS =
		':host{all:initial;display:block}' +
		'.page{padding:24px 12px;min-height:420px;box-sizing:border-box}' +
		'.sheet{margin:0 auto;min-height:120px}' +
		'.blk{position:relative;outline:1px dashed transparent;outline-offset:-1px;cursor:default}' +
		'.blk:hover{outline-color:#a78bfa}' +
		'.blk.sel{outline:2px solid #7c3aed}' +
		'.bar{display:none;position:absolute;top:-24px;right:0;z-index:5;background:#7c3aed;color:#fff;font:12px/1 -apple-system,BlinkMacSystemFont,sans-serif;border-radius:4px 4px 0 0;white-space:nowrap}' +
		'.blk:hover>.bar,.blk.sel>.bar{display:flex}' +
		'.blk:first-child>.bar{top:0;border-radius:0 0 0 4px}' +
		'.bar span,.bar button{display:inline-block;padding:6px 7px;color:#fff;background:none;border:0;font:inherit;cursor:pointer}' +
		'.bar button:hover{background:#5b21b6}' +
		'.bar .grip{cursor:grab}' +
		'[contenteditable]{outline:none;cursor:text;min-width:8px}' +
		'[contenteditable]:focus{box-shadow:0 0 0 1px rgba(124,58,237,.45)}' +
		'.ph{background:rgba(127,127,127,.18);color:#8b8b8b;text-align:center;padding:26px 10px;font:13px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;border:1px dashed rgba(127,127,127,.5)}' +
		'.sp{display:flex;align-items:center;justify-content:center;font:11px/1 -apple-system,BlinkMacSystemFont,sans-serif;color:#8b8b8b;background:repeating-linear-gradient(45deg,rgba(127,127,127,.08),rgba(127,127,127,.08) 5px,rgba(127,127,127,.18) 5px,rgba(127,127,127,.18) 10px)}' +
		'.empty{padding:60px 20px;text-align:center;color:#8b8b8b;font:14px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;border:2px dashed rgba(127,127,127,.4)}' +
		'.blk.drop-before{box-shadow:0 -3px 0 #ec4899}.blk.drop-after{box-shadow:0 3px 0 #ec4899}' +
		'.sheet.drop-end{box-shadow:0 3px 0 #ec4899}' +
		'img{max-width:100%}';

	function blockShell(b) {
		var st = state.style;
		return '<div class="blk' + (b.id === selectedId ? ' sel' : '') + '" data-id="' + escA(b.id) + '" style="padding:' + blockPadding(b, st) + ';">' +
			'<div class="bar" contenteditable="false">' +
			'<span class="grip" draggable="true" title="Drag to move">&#10303; ' + esc(DEFS[b.type].label) + '</span>' +
			'<button type="button" data-act="up" title="Move up">&uarr;</button>' +
			'<button type="button" data-act="down" title="Move down">&darr;</button>' +
			'<button type="button" data-act="dup" title="Duplicate">&#10697;</button>' +
			'<button type="button" data-act="del" title="Delete">&#10005;</button>' +
			'</div>' + blockInner(b, st, true) + '</div>';
	}

	function renderCanvas() {
		var st = state.style;
		var inner = state.blocks.length
			? state.blocks.map(blockShell).join('')
			: '<div class="empty">Drag a block here, or click one in the list on the left.</div>';
		root.innerHTML = '<style>' + CANVAS_CSS + '</style>' +
			'<div class="page" style="background:' + escA(st.bodyBg) + ';"><div class="sheet" style="max-width:' + num(st.width, 600) + 'px;background:' + escA(st.contentBg) + ';">' + inner + '</div></div>';
	}

	function blockEl(id) { return root.querySelector('.blk[data-id="' + id + '"]'); }

	function renderBlock(id) {
		var b = findBlock(id), el = blockEl(id);
		if (!b || !el) { renderCanvas(); return; }
		var tmp = document.createElement('div');
		tmp.innerHTML = blockShell(b);
		el.parentNode.replaceChild(tmp.firstChild, el);
	}

	function select(id, rerenderPanel) {
		if (selectedId === id) { return; }
		selectedId = id;
		Array.prototype.forEach.call(root.querySelectorAll('.blk'), function (el) {
			el.classList.toggle('sel', el.getAttribute('data-id') === id);
		});
		if (id && tab !== 'block') { tab = 'block'; }
		if (rerenderPanel !== false) { renderPanel(); }
	}

	function insertBlock(type, index) {
		if (!DEFS[type]) { return; }
		var b = makeBlock(type);
		if (index == null || index < 0 || index > state.blocks.length) {
			var si = indexOfBlock(selectedId);
			index = si >= 0 ? si + 1 : state.blocks.length;
		}
		state.blocks.splice(index, 0, b);
		selectedId = b.id;
		tab = 'block';
		renderCanvas();
		renderPanel();
		commit();
		var el = blockEl(b.id);
		if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
	}

	function blockAction(id, act) {
		var i = indexOfBlock(id);
		if (i < 0) { return; }
		if (act === 'up' && i > 0) { state.blocks.splice(i - 1, 0, state.blocks.splice(i, 1)[0]); }
		else if (act === 'down' && i < state.blocks.length - 1) { state.blocks.splice(i + 1, 0, state.blocks.splice(i, 1)[0]); }
		else if (act === 'dup') {
			var copy = clone(state.blocks[i]); copy.id = genId();
			state.blocks.splice(i + 1, 0, copy); selectedId = copy.id;
		}
		else if (act === 'del') {
			state.blocks.splice(i, 1);
			if (selectedId === id) { selectedId = null; }
		}
		else { return; }
		renderCanvas();
		renderPanel();
		commit();
	}

	root.addEventListener('click', function (e) {
		var t = e.target;
		if (!t.closest) { return; }
		var blk = t.closest('.blk');
		var actBtn = t.closest('[data-act]');
		if (t.closest('a')) { e.preventDefault(); }
		if (actBtn && blk) { blockAction(blk.getAttribute('data-id'), actBtn.getAttribute('data-act')); return; }
		if (blk) { select(blk.getAttribute('data-id')); }
	});

	root.addEventListener('input', function (e) {
		var el = e.target.closest ? e.target.closest('[data-edit]') : null;
		var blk = el ? el.closest('.blk') : null;
		if (!el || !blk) { return; }
		var b = findBlock(blk.getAttribute('data-id'));
		if (!b) { return; }
		var val = el.getAttribute('data-rich') ? el.innerHTML : el.innerText.replace(/\n$/, '');
		setPath(b.props, el.getAttribute('data-edit'), val);
		// Keep a matching text input in the panel in sync without re-rendering.
		var $inp = $('#aiem-ee-panel [data-path="' + el.getAttribute('data-edit') + '"]');
		if ($inp.length && !$inp.is(':focus')) { $inp.val(val); }
		commitSoon();
	});

	root.addEventListener('keydown', function (e) {
		var el = e.target.closest ? e.target.closest('[data-edit]') : null;
		if (!el) { return; }
		// Single-line fields (headings, buttons, titles): Enter must not add lines.
		// Multi-line plain fields are the DIVs whose path ends in "text".
		if (e.key === 'Enter' && !el.getAttribute('data-rich')) {
			var multi = el.tagName === 'DIV' && /(^|\.)text$/.test(el.getAttribute('data-edit'));
			if (!multi) { e.preventDefault(); }
		}
	});

	root.addEventListener('paste', function (e) {
		if (!e.target.closest || !e.target.closest('[data-edit]')) { return; }
		e.preventDefault();
		var text = (e.clipboardData || window.clipboardData).getData('text/plain');
		document.execCommand('insertText', false, text);
	});

	// ── Drag and drop ────────────────────────────────────────────────────────

	function clearDropMarks() {
		Array.prototype.forEach.call(root.querySelectorAll('.drop-before,.drop-after,.drop-end'), function (el) {
			el.classList.remove('drop-before', 'drop-after', 'drop-end');
		});
	}
	function dropTarget(e) {
		var blk = e.target.closest ? e.target.closest('.blk') : null;
		if (!blk) { return { index: state.blocks.length, el: null }; }
		var r = blk.getBoundingClientRect();
		var after = e.clientY > r.top + r.height / 2;
		return { index: indexOfBlock(blk.getAttribute('data-id')) + (after ? 1 : 0), el: blk, after: after };
	}

	root.addEventListener('dragstart', function (e) {
		var grip = e.target.closest ? e.target.closest('.grip') : null;
		var blk = grip ? grip.closest('.blk') : null;
		if (!blk) { e.preventDefault(); return; }
		drag = { kind: 'move', id: blk.getAttribute('data-id') };
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData('text/plain', 'aiem-move');
		if (e.dataTransfer.setDragImage) { e.dataTransfer.setDragImage(blk, 20, 20); }
	});
	root.addEventListener('dragover', function (e) {
		if (!drag) { return; }
		e.preventDefault();
		e.dataTransfer.dropEffect = drag.kind === 'move' ? 'move' : 'copy';
		clearDropMarks();
		var t = dropTarget(e);
		if (t.el) { t.el.classList.add(t.after ? 'drop-after' : 'drop-before'); }
		else { var sheet = root.querySelector('.sheet'); if (sheet) { sheet.classList.add('drop-end'); } }
	});
	root.addEventListener('drop', function (e) {
		if (!drag) { return; }
		e.preventDefault();
		var t = dropTarget(e), d = drag;
		drag = null;
		clearDropMarks();
		if (d.kind === 'new') { insertBlock(d.type, t.index); return; }
		var from = indexOfBlock(d.id);
		if (from < 0) { return; }
		var to = t.index > from ? t.index - 1 : t.index;
		if (to === from) { return; }
		state.blocks.splice(to, 0, state.blocks.splice(from, 1)[0]);
		selectedId = d.id;
		renderCanvas();
		renderPanel();
		commit();
	});
	document.addEventListener('dragend', function () { drag = null; clearDropMarks(); });
	root.addEventListener('dragend', function () { drag = null; clearDropMarks(); });

	// ── Palette ──────────────────────────────────────────────────────────────

	function renderPalette() {
		var $p = $('#aiem-ee-palette').empty();
		if (mode !== 'visual') {
			$p.append('<p class="aiem-ee-hint">This template is hand-written HTML. Blocks are not available.</p>');
			$p.append('<button type="button" class="button" id="aiem-ee-to-blocks">Start over with blocks</button>');
			return;
		}
		var groups = {};
		Object.keys(DEFS).forEach(function (type) {
			(groups[DEFS[type].group] = groups[DEFS[type].group] || []).push(type);
		});
		Object.keys(groups).forEach(function (g) {
			$p.append($('<div class="aiem-ee-group"></div>').text(g));
			groups[g].forEach(function (type) {
				$p.append(
					$('<button type="button" class="aiem-ee-pal" draggable="true"></button>')
						.attr('data-type', type)
						.append('<span class="dashicons dashicons-' + DEFS[type].icon + '" aria-hidden="true"></span>')
						.append($('<span></span>').text(DEFS[type].label))
				);
			});
		});
		$p.append('<p class="aiem-ee-hint">Click to add below the selected block, or drag onto the email.</p>');
	}
	$(document).on('click', '.aiem-ee-pal', function () { insertBlock($(this).data('type')); });
	document.addEventListener('dragstart', function (e) {
		var pal = e.target.closest ? e.target.closest('.aiem-ee-pal') : null;
		if (!pal) { return; }
		drag = { kind: 'new', type: pal.getAttribute('data-type') };
		e.dataTransfer.effectAllowed = 'copy';
		e.dataTransfer.setData('text/plain', 'aiem-new');
	});

	// ── Right panel ──────────────────────────────────────────────────────────

	function field(path, label, kind, a, b, value) {
		var id = 'aiem-f-' + path.replace(/\W/g, '-');
		var h = '<div class="aiem-ee-field"><label for="' + id + '">' + esc(label) + '</label>';
		switch (kind) {
			case 'textarea':
				h += '<textarea id="' + id + '" data-path="' + path + '" rows="3">' + esc(value) + '</textarea>'; break;
			case 'select':
				h += '<select id="' + id + '" data-path="' + path + '">';
				Object.keys(a).forEach(function (k) { h += '<option value="' + escA(k) + '"' + (String(value) === k ? ' selected' : '') + '>' + esc(a[k]) + '</option>'; });
				h += '</select>'; break;
			case 'align':
				h += '<div class="aiem-ee-seg" data-path="' + path + '">';
				['left', 'center', 'right'].forEach(function (v) {
					h += '<button type="button" data-val="' + v + '" class="' + (value === v ? 'is-on' : '') + '" title="' + v + '"><span class="dashicons dashicons-editor-align' + v + '" aria-hidden="true"></span></button>';
				});
				h += '</div>'; break;
			case 'color':
				h += '<div class="aiem-ee-color"><input type="color" id="' + id + '" data-path="' + path + '" value="' + escA(value || '#000000') + '" /><code>' + esc(value || '') + '</code></div>'; break;
			case 'range':
				h += '<div class="aiem-ee-range"><input type="range" id="' + id + '" data-path="' + path + '" data-num="1" min="' + a + '" max="' + b + '" step="1" value="' + num(value, a) + '" /><output>' + num(value, a) + '</output></div>'; break;
			case 'image':
				h += '<div class="aiem-ee-img"><input type="url" id="' + id + '" data-path="' + path + '" value="' + escA(value) + '" placeholder="https://" />' +
					'<button type="button" class="button aiem-ee-media" data-for="' + path + '">Library</button></div>'; break;
			default:
				h += '<input type="' + (kind === 'url' ? 'url' : 'text') + '" id="' + id + '" data-path="' + path + '" value="' + escA(value) + '"' + (kind === 'url' ? ' placeholder="https://"' : '') + ' />';
		}
		return h + '</div>';
	}

	function renderPanel() {
		if (mode !== 'visual') { tab = 'checks'; }
		var counts = checkCounts();
		$('.aiem-ee-tab').each(function () {
			var t = $(this).data('tab');
			$(this).toggleClass('is-on', t === tab).prop('disabled', mode !== 'visual' && t !== 'checks');
		});
		$('#aiem-ee-check-count').text(counts ? counts : '').toggle(!!counts);

		var $panel = $('#aiem-ee-panel');

		if (tab === 'checks') { $panel.html(checksHtml()); return; }

		if (tab === 'style') {
			var st = state.style, h = '<div class="aiem-ee-presets"><span>Preset</span><button type="button" class="button" data-preset="light">Light</button><button type="button" class="button" data-preset="dark">Dark</button></div>';
			h += field('bodyBg', 'Page background', 'color', null, null, st.bodyBg);
			h += field('contentBg', 'Email background', 'color', null, null, st.contentBg);
			h += field('width', 'Email width', 'range', 480, 700, st.width);
			h += field('padX', 'Side padding', 'range', 0, 48, st.padX);
			h += field('font', 'Font', 'select', FONTS, null, st.font);
			h += field('headingColor', 'Heading color', 'color', null, null, st.headingColor);
			h += field('textColor', 'Text color', 'color', null, null, st.textColor);
			h += field('linkColor', 'Link color', 'color', null, null, st.linkColor);
			$panel.html('<div data-scope="style">' + h + '</div>');
			return;
		}

		var b = findBlock(selectedId);
		if (!b) {
			$panel.html('<p class="aiem-ee-hint">Select a block in the email to edit it. Text can be typed directly in the email.</p>');
			return;
		}
		var html = '<div class="aiem-ee-paneltitle">' + esc(DEFS[b.type].label) + '</div>';

		if (b.type === 'text') {
			html += '<div class="aiem-ee-field"><label>Format selected text</label><div class="aiem-ee-fmt">' +
				'<button type="button" class="button" data-cmd="bold"><strong>B</strong></button>' +
				'<button type="button" class="button" data-cmd="italic"><em>I</em></button>' +
				'<button type="button" class="button" data-cmd="link">Link</button>' +
				'<button type="button" class="button" data-cmd="unlink">Unlink</button></div></div>';
		}
		html += '<div data-scope="block">';
		FIELDS[b.type].forEach(function (f) {
			var val = getPath(b.props, f[0]);
			if (f[2] === 'color' && !val && f[3]) { val = state.style[f[3]]; }
			html += field(f[0], f[1], f[2], f[3], f[4], val);
		});
		html += '</div>';

		html += '<div class="aiem-ee-field"><label>Insert merge tag at cursor</label><div class="aiem-ee-tags">';
		INSERT_TAGS.forEach(function (t) { html += '<button type="button" class="aiem-ee-tag" data-tag="{{' + t + '}}">' + t + '</button>'; });
		html += '</div></div>';

		if (AI_FIELD[b.type]) {
			html += '<div class="aiem-ee-ai"><label for="aiem-ee-ai-inst">Rewrite with AI</label>' +
				'<input type="text" id="aiem-ee-ai-inst" placeholder="Shorter and punchier" />' +
				'<button type="button" class="button" id="aiem-ee-ai-go"><span class="dashicons dashicons-admin-customizer" aria-hidden="true"></span> Rewrite</button>' +
				'<span class="aiem-ee-ai-msg" id="aiem-ee-ai-msg"></span></div>';
		}
		$panel.html(html);
	}

	function applyField($el, val) {
		var path = $el.data('path');
		if ($el.data('num')) { val = num(val, 0); $el.siblings('output').text(val); }
		if ($el.attr('type') === 'color') { $el.siblings('code').text(val); }
		if ($el.closest('[data-scope="style"]').length) {
			state.style[path] = val;
			renderCanvas();
		} else {
			var b = findBlock(selectedId);
			if (!b) { return; }
			setPath(b.props, path, val);
			renderBlock(b.id);
		}
		commitSoon();
	}
	$(document).on('input change', '#aiem-ee-panel [data-path]:input', function () { applyField($(this), $(this).val()); });
	$(document).on('click', '.aiem-ee-seg button', function () {
		var $seg = $(this).parent();
		$seg.find('button').removeClass('is-on');
		$(this).addClass('is-on');
		applyField($seg, $(this).data('val'));
	});
	$(document).on('click', '.aiem-ee-presets [data-preset]', function () {
		state.style = $.extend({}, $(this).data('preset') === 'dark' ? STYLE_DARK : STYLE_LIGHT);
		renderCanvas(); renderPanel(); commit();
	});
	$(document).on('click', '.aiem-ee-tab', function () { tab = $(this).data('tab'); renderPanel(); });

	// Formatting + merge tags act on the live selection inside the email, so
	// these buttons must not take focus (mousedown preventDefault).
	$(document).on('mousedown', '.aiem-ee-fmt button, .aiem-ee-tag', function (e) { e.preventDefault(); });
	$(document).on('click', '.aiem-ee-fmt button', function () {
		var cmd = $(this).data('cmd');
		if (cmd === 'link') {
			var url = window.prompt('Link URL', 'https://');
			if (url) { document.execCommand('createLink', false, url); }
		} else {
			document.execCommand(cmd, false, null);
		}
	});
	$(document).on('click', '.aiem-ee-tag', function () {
		var tag = $(this).data('tag');
		var active = root.activeElement || document.activeElement;
		if (active && active.closest && active.closest('[data-edit]')) {
			document.execCommand('insertText', false, tag);
			return;
		}
		// Nothing focused in the email: append to the block's main text.
		var b = findBlock(selectedId);
		var target = b && ({ heading: 'text', text: 'content', button: 'text', footer: 'text', product: 'text' })[b.type];
		if (!target) { window.alert('Click into a text area in the email first, then pick a tag.'); return; }
		setPath(b.props, target, String(getPath(b.props, target)) + ' ' + tag);
		renderBlock(b.id); renderPanel(); commit();
	});

	// Media library picker
	$(document).on('click', '.aiem-ee-media', function () {
		var path = $(this).data('for');
		if (!window.wp || !wp.media) { window.alert('Media library is not available on this page.'); return; }
		var frame = wp.media({ title: 'Choose image', multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var b = findBlock(selectedId);
			if (!b) { return; }
			setPath(b.props, path, att.url);
			var altPath = path.replace(/src$/, 'alt');
			if (att.alt && !getPath(b.props, altPath)) { setPath(b.props, altPath, att.alt); }
			renderBlock(b.id); renderPanel(); commit();
		});
		frame.open();
	});

	// AI rewrite
	$(document).on('click', '#aiem-ee-ai-go', function () {
		var b = findBlock(selectedId), spec = b && AI_FIELD[b.type];
		if (!spec) { return; }
		var $btn = $(this).prop('disabled', true), $msg = $('#aiem-ee-ai-msg').attr('class', 'aiem-ee-ai-msg').text('Rewriting…');
		$.post(aiemAdmin.ajaxUrl, {
			action: 'aiem_rewrite_text', nonce: aiemAdmin.nonce,
			text: String(getPath(b.props, spec[0])), instruction: $('#aiem-ee-ai-inst').val(), is_html: spec[1] ? 1 : 0
		}).done(function (res) {
			if (res && res.success && res.data.text) {
				setPath(b.props, spec[0], res.data.text);
				renderBlock(b.id); commit();
				var keep = $('#aiem-ee-ai-inst').val();
				renderPanel();
				$('#aiem-ee-ai-inst').val(keep);
				$('#aiem-ee-ai-msg').text('Done. Undo to revert.');
			} else {
				$msg.addClass('is-err').text((res && res.data && res.data.message) || 'Rewrite failed.');
			}
		}).fail(function () { $msg.addClass('is-err').text('Request failed.'); })
		  .always(function () { $btn.prop('disabled', false); });
	});

	// ── Pre-send checks ──────────────────────────────────────────────────────

	var checks = [];
	var checkTimer = null;

	function computeChecks() {
		var out = [];
		var html = currentHtml();
		var parts = mode === 'visual'
			? state.blocks.map(function (b) { return { id: b.id, label: DEFS[b.type].label, html: blockInner(b, state.style, false) }; })
			: [{ id: null, label: '', html: html }];

		if (!html.trim() || (mode === 'visual' && !state.blocks.length)) {
			return [{ level: 'info', text: 'The email is empty', meta: 'Add a block to get started.' }];
		}

		if (html.indexOf('{{unsubscribe_url}}') !== -1) {
			out.push({ level: 'ok', text: 'Unsubscribe link present' });
		} else {
			out.push({ level: 'info', text: 'No unsubscribe link in the design', meta: 'A plain footer link is added automatically when sending.' + (mode === 'visual' ? ' Add a Footer block to style it.' : ''), add: mode === 'visual' ? 'footer' : null });
		}

		var linkCount = 0, badLinks = [], noAlt = [], noSrc = [], insecure = [];
		parts.forEach(function (part) {
			var doc = new DOMParser().parseFromString(part.html, 'text/html');
			Array.prototype.forEach.call(doc.querySelectorAll('a'), function (a) {
				linkCount++;
				var href = (a.getAttribute('href') || '').trim();
				if (!href || href === '#' || !/^(https?:\/\/|mailto:|tel:|\{\{)/i.test(href)) { badLinks.push(part); }
			});
			Array.prototype.forEach.call(doc.querySelectorAll('img'), function (im) {
				var src = (im.getAttribute('src') || '').trim();
				if (!src) { noSrc.push(part); }
				else if (/^http:\/\//i.test(src)) { insecure.push(part); }
				if (!(im.getAttribute('alt') || '').trim()) { noAlt.push(part); }
			});
		});

		function issues(list, level, one, many, meta) {
			if (!list.length) { return; }
			var seen = {};
			list.forEach(function (part) {
				var key = part.id || '_';
				if (seen[key]) { seen[key].n++; return; }
				seen[key] = { n: 1, part: part };
			});
			Object.keys(seen).forEach(function (k) {
				var s = seen[k];
				out.push({ level: level, text: (s.n > 1 ? many.replace('%d', s.n) : one) + (s.part.label ? ' in ' + s.part.label + ' block' : ''), meta: meta, blockId: s.part.id });
			});
		}

		if (!badLinks.length) { out.push({ level: 'ok', text: 'All links have a destination', meta: linkCount + (linkCount === 1 ? ' link' : ' links') }); }
		issues(badLinks, 'warn', 'Link has no destination', '%d links have no destination', 'Empty or "#" links go nowhere when clicked.');
		issues(noAlt, 'warn', 'Image missing alt text', '%d images missing alt text', 'Alt text shows when images are blocked.');
		issues(insecure, 'warn', 'Image uses http://', '%d images use http://', 'Many inboxes block non-https images.');
		issues(noSrc, 'info', 'Image has no URL', '%d images have no URL', 'Fine for AI scaffolds: the AI fills empty images.');

		var unknown = {};
		html.replace(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g, function (m, t) { if (KNOWN_TAGS.indexOf(t) === -1) { unknown[m] = 1; } return m; });
		Object.keys(unknown).forEach(function (m) {
			out.push({ level: 'warn', text: 'Unknown merge tag ' + m, meta: 'It will be sent as literal text.' });
		});

		var kb = Math.round(new Blob([html]).size / 1024);
		out.push({
			level: kb > 102 ? 'error' : (kb > 90 ? 'warn' : 'ok'),
			text: 'Size ' + kb + ' KB',
			meta: kb > 102 ? 'Gmail clips emails over 102 KB.' : 'Gmail clips at 102 KB'
		});
		return out;
	}

	function checkCounts() {
		return checks.filter(function (c) { return c.level === 'warn' || c.level === 'error'; }).length;
	}
	function checksHtml() {
		var icon = { ok: 'yes-alt', warn: 'warning', error: 'dismiss', info: 'info' };
		var h = '<div class="aiem-ee-paneltitle">Pre-send checks</div><ul class="aiem-ee-checks">';
		checks.forEach(function (c) {
			h += '<li class="is-' + c.level + '"><span class="dashicons dashicons-' + icon[c.level] + '" aria-hidden="true"></span><div><strong>' + esc(c.text) + '</strong>' +
				(c.meta ? '<small>' + esc(c.meta) + '</small>' : '') + '</div>';
			if (c.blockId) { h += '<button type="button" class="button-link aiem-ee-fix" data-id="' + escA(c.blockId) + '">Fix</button>'; }
			else if (c.add) { h += '<button type="button" class="button-link aiem-ee-add" data-type="' + escA(c.add) + '">Add</button>'; }
			h += '</li>';
		});
		return h + '</ul>';
	}
	function runChecks() {
		checks = computeChecks();
		var n = checkCounts();
		$('#aiem-ee-check-count').text(n ? n : '').toggle(!!n);
		if (tab === 'checks') { $('#aiem-ee-panel').html(checksHtml()); }
	}
	function scheduleChecks() { clearTimeout(checkTimer); checkTimer = setTimeout(runChecks, 300); }

	$(document).on('click', '.aiem-ee-fix', function () {
		var id = $(this).data('id');
		selectedId = null;
		tab = 'block';
		select(id);
		var el = blockEl(id);
		if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
	});
	$(document).on('click', '.aiem-ee-add', function () {
		selectedId = null;
		insertBlock($(this).data('type'), state.blocks.length);
	});

	// ── Code view / HTML mode ────────────────────────────────────────────────

	var previewTimer = null;
	function updatePreview() {
		var frame = document.getElementById('aiem-ee-preview');
		if (frame) { frame.srcdoc = currentHtml(); }
	}
	function renderMode() {
		var code = mode === 'html' || showCode;
		$('#aiem-ee-canvas').toggle(!code);
		$('#aiem-ee-codeview').toggle(code);
		$('#aiem-ee').toggleClass('is-html', mode === 'html');
		$('#aiem-ee-code').text(mode === 'html' ? 'HTML template' : (showCode ? 'Back to design' : 'View HTML')).prop('disabled', mode === 'html');
		if (code) {
			$('#aiem-ee-source').val(currentHtml()).prop('readonly', mode === 'visual');
			$('#aiem-ee-codenote').html(mode === 'visual'
				? 'Generated from your blocks (read-only). <button type="button" class="button-link" id="aiem-ee-to-html">Convert to a hand-edited HTML template</button>'
				: 'Hand-written HTML. Merge tags: <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{email}}</code> <code>{{site_name}}</code> <code>{{unsubscribe_url}}</code>');
			updatePreview();
		}
		renderPalette();
		renderPanel();
		refreshStatus();
	}
	$('#aiem-ee-code').on('click', function () { commit(); showCode = !showCode; renderMode(); });
	$(document).on('input', '#aiem-ee-source', function () {
		if (mode !== 'html') { return; }
		htmlSource = $(this).val();
		refreshStatus();
		scheduleChecks();
		clearTimeout(previewTimer);
		previewTimer = setTimeout(updatePreview, 400);
	});
	$(document).on('click', '#aiem-ee-to-html', function () {
		if (!window.confirm('Convert to hand-edited HTML? You will edit the code directly from now on, and the blocks cannot be restored after saving.')) { return; }
		commit();
		htmlSource = renderEmail();
		mode = 'html'; showCode = false; selectedId = null; tab = 'checks';
		renderMode(); runChecks();
	});
	$(document).on('click', '#aiem-ee-to-blocks', function () {
		if (htmlSource.trim() && !window.confirm('Start over with blocks? The current HTML will be replaced when you save.')) { return; }
		mode = 'visual'; showCode = false; tab = 'block';
		state.blocks = []; lastSnap = JSON.stringify(state); history = []; future = [];
		renderCanvas(); renderMode(); runChecks();
	});

	// ── Save / test send ─────────────────────────────────────────────────────

	function setStatus(text, cls, busy) {
		$('#aiem-ee-status').data('busy', !!busy).attr('class', 'aiem-ee-status ' + (cls || '')).text(text);
	}

	function save() {
		commit();
		var name = $.trim($('#aiem-ee-name').val());
		if (!name) { setStatus('Enter a template name', 'is-err'); $('#aiem-ee-name').trigger('focus'); return; }
		var snapAtSave = snap();
		var $btn = $('#aiem-ee-save').prop('disabled', true);
		setStatus('Saving…', '', true);
		$.post(aiemAdmin.ajaxUrl, {
			action: 'aiem_save_email_template', nonce: aiemAdmin.nonce,
			template_id: templateId, name: name,
			blocks: mode === 'visual' ? JSON.stringify({ version: 2, style: state.style, blocks: state.blocks }) : '[]',
			html_content: currentHtml()
		}).done(function (res) {
			$('#aiem-ee-status').data('busy', false);
			if (res && res.success) {
				savedSnap = snapAtSave;
				if (!templateId && res.data.template_id) {
					templateId = parseInt(res.data.template_id, 10);
					window.history.replaceState(null, '', '?page=aiem-email-template-edit&template_id=' + templateId);
				}
				runChecks();
				var n = checkCounts();
				if (isDirty()) { refreshStatus(); }
				else { setStatus(n ? 'Saved · ' + n + (n === 1 ? ' warning' : ' warnings') : 'Saved', n ? 'is-warn' : 'is-ok'); }
			} else {
				setStatus((res && res.data && res.data.message) || 'Save failed', 'is-err');
			}
		}).fail(function () {
			$('#aiem-ee-status').data('busy', false);
			setStatus('Save failed. Check your connection.', 'is-err');
		}).always(function () { $btn.prop('disabled', false); });
	}
	$('#aiem-ee-save').on('click', save);

	$('#aiem-ee-test').on('click', function () {
		commit();
		var html = currentHtml();
		if (!html.trim() || (mode === 'visual' && !state.blocks.length)) { setStatus('Nothing to send yet', 'is-err'); return; }
		var to = window.prompt('Send a test of this design to:', aiemAdmin.adminEmail || '');
		if (!to) { return; }
		var $btn = $(this).prop('disabled', true);
		setStatus('Sending test…', '', true);
		$.post(aiemAdmin.ajaxUrl, {
			action: 'aiem_test_send_template', nonce: aiemAdmin.nonce,
			to_email: to, name: $('#aiem-ee-name').val(), html_content: html
		}).done(function (res) {
			$('#aiem-ee-status').data('busy', false);
			setStatus((res && res.data && res.data.message) || 'Test failed', res && res.success ? 'is-ok' : 'is-err');
		}).fail(function () {
			$('#aiem-ee-status').data('busy', false);
			setStatus('Test request failed', 'is-err');
		}).always(function () { $btn.prop('disabled', false); });
	});

	$('#aiem-ee-undo').on('click', undo);
	$('#aiem-ee-redo').on('click', redo);
	$('#aiem-ee-name').on('input', function () { refreshStatus(); });

	document.addEventListener('keydown', function (e) {
		var mod = e.metaKey || e.ctrlKey;
		if (!mod) { return; }
		var key = String(e.key).toLowerCase();
		if (key === 's') { e.preventDefault(); save(); return; }
		if (mode !== 'visual' || key !== 'z') { return; }
		// Leave native undo alone while typing in a field or in the email.
		var a = document.activeElement, inShadow = root.activeElement;
		if ((a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) || inShadow) { return; }
		e.preventDefault();
		if (e.shiftKey) { redo(); } else { undo(); }
	});

	window.addEventListener('beforeunload', function (e) {
		if (isDirty()) { e.preventDefault(); e.returnValue = ''; }
	});

	// ── Boot ─────────────────────────────────────────────────────────────────

	lastSnap = JSON.stringify(state);
	renderCanvas();
	checks = computeChecks();
	renderMode();
	// A brand-new template built from a starter counts as unsaved work.
	savedSnap = (templateId || (!state.blocks.length && !htmlSource.trim())) ? snap() : '';
	refreshStatus();

})(jQuery);
