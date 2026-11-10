/* AI Email Marketing — dashboard chart and hover tooltips */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';

	function el(name, attrs, parent) {
		var node = document.createElementNS(NS, name);
		for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) { node.setAttribute(k, attrs[k]); } }
		if (parent) { parent.appendChild(node); }
		return node;
	}
	function html(tag, cls, text, parent) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text != null) { node.textContent = text; }
		if (parent) { parent.appendChild(node); }
		return node;
	}
	function fmt(n) { return Number(n).toLocaleString(); }
	function fmtDate(iso, long) {
		var p = iso.split('-');
		var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
		return d.toLocaleDateString(undefined, long ? { weekday: 'short', month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric' });
	}
	// Round an axis maximum up to a clean number with 4-5 even steps.
	function niceScale(max) {
		if (max <= 0) { return { max: 4, step: 1 }; }
		var rough = max / 4;
		var pow = Math.pow(10, Math.floor(Math.log10(rough)));
		var step = pow;
		[1, 2, 2.5, 5, 10].some(function (m) { step = m * pow; return step >= rough; });
		if (step < 1) { step = 1; }
		return { max: Math.ceil(max / step) * step, step: step };
	}

	// ── Shared tooltip for bars and donut segments ───────────────────────────
	var tip = html('div', 'aiem-tip', null, document.body);
	tip.setAttribute('role', 'status');
	function showTip(target, x, y) {
		tip.textContent = '';
		html('strong', null, target.getAttribute('data-tip-value'), tip);
		html('span', null, target.getAttribute('data-tip'), tip);
		tip.style.display = 'block';
		var w = tip.offsetWidth, h = tip.offsetHeight;
		tip.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, x - w / 2)) + 'px';
		tip.style.top = Math.max(8, y - h - 12) + 'px';
	}
	function hideTip() { tip.style.display = 'none'; }
	Array.prototype.forEach.call(document.querySelectorAll('[data-tip]'), function (node) {
		node.addEventListener('pointermove', function (e) { showTip(node, e.clientX, e.clientY); });
		node.addEventListener('pointerleave', hideTip);
		node.addEventListener('focus', function () {
			var r = node.getBoundingClientRect();
			showTip(node, r.left + r.width / 2, r.top);
		});
		node.addEventListener('blur', hideTip);
	});

	// ── Line chart ───────────────────────────────────────────────────────────
	var host = document.getElementById('aiem-chart');
	var dataEl = document.getElementById('aiem-chart-data');
	var legend = document.getElementById('aiem-chart-legend');
	if (!host || !dataEl) { return; }

	var data;
	try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
	var dates = data.dates || [], series = data.series || [];
	if (!dates.length || !series.length) { return; }

	var hidden = {};          // series key -> true when toggled off
	var focusKey = null;      // series emphasised with an area wash
	var hoverIndex = -1;
	var H = 300, M = { top: 14, right: 16, bottom: 30, left: 48 };
	var svg, layer, readout, geom;

	function visible() { return series.filter(function (s) { return !hidden[s.key]; }); }
	function focused() {
		var vis = visible();
		for (var i = 0; i < vis.length; i++) { if (vis[i].key === focusKey) { return vis[i]; } }
		return vis[0] || null;
	}

	// Legend: one toggle per series. Text stays in ink; the swatch carries color.
	series.forEach(function (s) {
		var btn = html('button', 'aiem-legend-item', null, legend);
		btn.type = 'button';
		btn.setAttribute('aria-pressed', 'true');
		var sw = html('span', 'aiem-legend-key', null, btn);
		sw.style.background = s.color;
		html('span', null, s.label, btn);
		btn.addEventListener('click', function () {
			if (!hidden[s.key] && visible().length === 1) { return; } // keep at least one
			hidden[s.key] = !hidden[s.key];
			btn.setAttribute('aria-pressed', hidden[s.key] ? 'false' : 'true');
			btn.classList.toggle('is-off', !!hidden[s.key]);
			draw();
		});
		btn.addEventListener('pointerenter', function () { if (!hidden[s.key]) { focusKey = s.key; draw(); } });
	});

	function draw() {
		var W = Math.max(320, host.clientWidth);
		var vis = visible();
		var max = 0;
		vis.forEach(function (s) { s.values.forEach(function (v) { if (v > max) { max = v; } }); });
		var scale = niceScale(max);
		var iw = W - M.left - M.right, ih = H - M.top - M.bottom;
		var n = dates.length;
		var x = function (i) { return M.left + (n === 1 ? iw / 2 : (iw * i) / (n - 1)); };
		var y = function (v) { return M.top + ih - (ih * v) / scale.max; };
		geom = { W: W, x: x, y: y, n: n, iw: iw, ih: ih };

		host.textContent = '';
		svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'aria-hidden': 'true', focusable: 'false' }, host);

		// Grid + y axis (recessive)
		for (var t = 0; t <= scale.max + 1e-9; t += scale.step) {
			var gy = y(t);
			el('line', { x1: M.left, x2: W - M.right, y1: gy, y2: gy, class: 'aiem-grid' }, svg);
			var lab = el('text', { x: M.left - 8, y: gy + 4, 'text-anchor': 'end', class: 'aiem-axis' }, svg);
			lab.textContent = fmt(t);
		}
		// X labels: thin them so they never collide
		var every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 72))));
		for (var i = 0; i < n; i++) {
			// Always label the last day; drop a regular label that would crowd it.
			if (i !== n - 1 && (i % every !== 0 || n - 1 - i < every * 0.7)) { continue; }
			var xl = el('text', { x: x(i), y: H - 8, 'text-anchor': i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle'), class: 'aiem-axis' }, svg);
			xl.textContent = fmtDate(dates[i]);
		}

		var foc = focused();
		// Area wash under the focused series only
		if (foc) {
			var area = 'M' + x(0) + ',' + y(0);
			foc.values.forEach(function (v, i) { area += ' L' + x(i) + ',' + y(v); });
			area += ' L' + x(n - 1) + ',' + y(0) + ' Z';
			el('path', { d: area, fill: foc.color, 'fill-opacity': 0.1 }, svg);
		}
		// Lines: focused series drawn last, on top
		vis.slice().sort(function (a, b) { return (a === foc) - (b === foc); }).forEach(function (s) {
			var d = '';
			s.values.forEach(function (v, i) { d += (i ? ' L' : 'M') + x(i) + ',' + y(v); });
			el('path', {
				d: d, fill: 'none', stroke: s.color, 'stroke-width': 2,
				'stroke-linejoin': 'round', 'stroke-linecap': 'round',
				'stroke-opacity': s === foc ? 1 : 0.55
			}, svg);
			if (n === 1) { el('circle', { cx: x(0), cy: y(s.values[0]), r: 4, fill: s.color, stroke: '#fff', 'stroke-width': 2 }, svg); }
		});

		layer = el('g', {}, svg);
		readout = html('div', 'aiem-chart-readout', null, host);
		if (hoverIndex >= 0) { showAt(hoverIndex); }
	}

	function showAt(i) {
		if (!geom) { return; }
		i = Math.max(0, Math.min(geom.n - 1, i));
		hoverIndex = i;
		while (layer.firstChild) { layer.removeChild(layer.firstChild); }
		var cx = geom.x(i);
		el('line', { x1: cx, x2: cx, y1: M.top, y2: H - M.bottom, class: 'aiem-crosshair' }, layer);
		var vis = visible();
		vis.forEach(function (s) {
			el('circle', { cx: cx, cy: geom.y(s.values[i]), r: 4, fill: s.color, stroke: '#fff', 'stroke-width': 2 }, layer);
		});

		// One readout listing every visible series: value leads, label follows.
		readout.textContent = '';
		html('div', 'aiem-readout-date', fmtDate(dates[i], true), readout);
		vis.forEach(function (s) {
			var row = html('div', 'aiem-readout-row', null, readout);
			var key = html('span', 'aiem-readout-key', null, row);
			key.style.background = s.color;
			html('strong', null, fmt(s.values[i]), row);
			html('span', null, s.label, row);
		});
		readout.style.display = 'block';
		var rw = readout.offsetWidth;
		var left = cx + 14;
		if (left + rw > geom.W - 4) { left = cx - rw - 14; }
		readout.style.left = Math.max(4, left) + 'px';
		readout.style.top = M.top + 'px';
	}
	function hideReadout() {
		hoverIndex = -1;
		if (layer) { while (layer.firstChild) { layer.removeChild(layer.firstChild); } }
		if (readout) { readout.style.display = 'none'; }
	}
	function indexFromEvent(e) {
		var r = host.getBoundingClientRect();
		var px = e.clientX - r.left;
		if (geom.n === 1) { return 0; }
		return Math.round(((px - M.left) / geom.iw) * (geom.n - 1));
	}

	host.addEventListener('pointermove', function (e) { showAt(indexFromEvent(e)); });
	host.addEventListener('pointerleave', hideReadout);
	// Keyboard: same details as hover
	host.tabIndex = 0;
	host.addEventListener('focus', function () { if (hoverIndex < 0) { showAt(dates.length - 1); } });
	host.addEventListener('blur', hideReadout);
	host.addEventListener('keydown', function (e) {
		if (e.key === 'ArrowLeft') { showAt((hoverIndex < 0 ? dates.length : hoverIndex) - 1); e.preventDefault(); }
		if (e.key === 'ArrowRight') { showAt(hoverIndex + 1); e.preventDefault(); }
		if (e.key === 'Escape') { hideReadout(); }
	});

	var resizeTimer = null;
	window.addEventListener('resize', function () {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(draw, 120);
	});

	draw();
})();
