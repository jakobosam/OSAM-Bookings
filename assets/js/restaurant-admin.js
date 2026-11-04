/* OSAM Bookings — restaurant admin: service timeline, live floor plan and floor-plan editor (no dependencies) */
(function () {
	'use strict';

	var C = window.OSB_REST || {};
	var T = C.i18n || {};
	var SNAP = 15; // minutes
	var PPM = 2; // pixels per minute on the timeline

	/* ---------- helpers ---------- */

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function toMin(hm) { var p = String(hm || '0:0').split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1] || 0, 10); }
	function toHM(m) { m = Math.max(0, Math.min(24 * 60 - 1, Math.round(m))); return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
	var twelve = /[gh]/.test(C.timeFormat || 'H:i') && !/[GH]/.test(C.timeFormat || '');
	function fmt(hm) {
		var m = toMin(hm), h = Math.floor(m / 60), mi = m % 60;
		if (!twelve) { return pad(h) + ':' + pad(mi); }
		var ap = h >= 12 ? 'pm' : 'am';
		h = h % 12 || 12;
		return h + (mi ? ':' + pad(mi) : '') + ap;
	}
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function shiftDate(s, days) { var d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + days); return ymd(d); }
	function sprintf(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(s || '').replace(/%(\d\$)?[sd]/g, function (m, pos) { return pos ? a[parseInt(pos, 10) - 1] : a[i++]; }); }
	function h(tag, attrs, html) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') { el.className = attrs[k]; } else if (k === 'style') { el.style.cssText = attrs[k]; } else { el.setAttribute(k, attrs[k]); }
		});
		if (html != null) { el.innerHTML = html; }
		return el;
	}

	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' } };
		if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		var url = C.rest + path;
		if (opts.query) {
			var q = Object.keys(opts.query).filter(function (k) { return opts.query[k] !== '' && opts.query[k] != null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]); }).join('&');
			if (q) { url += (url.indexOf('?') > -1 ? '&' : '?') + q; }
		}
		return fetch(url, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) { var e = new Error((d && d.message) || T.error); e.data = d; throw e; }
				return d;
			});
		});
	}

	var toastTimer = null;
	function toast(msg, list, bad) {
		var el = document.querySelector('.osb-rest-toast');
		if (!el) { el = h('div', { 'class': 'osb-rest-toast', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(el); }
		el.className = 'osb-rest-toast is-on' + (list && list.length ? ' is-warn' : '') + (bad ? ' is-bad' : '');
		el.innerHTML = '<strong>' + esc(msg) + '</strong>' + (list && list.length ? '<ul>' + list.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>' : '');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { el.classList.remove('is-on'); }, list && list.length ? 7000 : 2600);
	}

	/* ======================================================================
	 * Service screen
	 * ==================================================================== */

	function Service(root) {
		this.root = root;
		var stored = '';
		try { stored = window.localStorage.getItem('osbRestView') || ''; } catch (e) { /* ignore */ }
		this.s = {
			date: root.getAttribute('data-date'),
			focus: parseInt(root.getAttribute('data-focus'), 10) || 0,
			view: stored === 'floor' ? 'floor' : 'timeline',
			showCancelled: false,
			search: '',
			area: 0,
			at: null,
			data: null,
			open: null
		};
		this.bind();
		this.load();
		var self = this;
		// Keep the board fresh during service.
		this.timer = setInterval(function () { if (!self.drag && !self.modal && document.visibilityState === 'visible') { self.load(true); } }, 60000);
	}

	Service.prototype.load = function (quiet) {
		var self = this;
		if (!quiet) { this.root.classList.add('is-loading'); }
		return api('/admin/tables/day', { query: { date: this.s.date } }).then(function (d) {
			self.s.data = d;
			if (!self.s.area && d.areas.length) { self.s.area = d.areas[0].id; }
			self.root.classList.remove('is-loading');
			self.render();
			if (self.s.focus) {
				var f = self.s.focus;
				self.s.focus = 0;
				if (self.booking(f)) { self.openDrawer(f); }
			} else if (self.s.open) {
				if (self.booking(self.s.open)) { self.openDrawer(self.s.open); } else { self.closeDrawer(); }
			}
		}).catch(function (e) { self.root.innerHTML = '<div class="osb-alert osb-alert--warn">' + esc(e.message) + '</div>'; });
	};

	Service.prototype.booking = function (id) {
		return (this.s.data.bookings || []).filter(function (b) { return b.id === id; })[0] || null;
	};

	Service.prototype.visible = function () {
		var s = this.s;
		var q = s.search.trim().toLowerCase();
		return (s.data.bookings || []).filter(function (b) {
			if (!s.showCancelled && b.status === 'cancelled') { return false; }
			if (q && (b.name + ' ' + b.phone + ' ' + b.email + ' #' + b.id).toLowerCase().indexOf(q) === -1) { return false; }
			return true;
		});
	};

	Service.prototype.range = function () {
		var d = this.s.data;
		var from = 24 * 60, to = 0;
		var maxTurn = 0;
		(C.turns || []).forEach(function (t) { maxTurn = Math.max(maxTurn, parseInt(t[1], 10) || 0); });
		d.periods.forEach(function (p) { from = Math.min(from, toMin(p.start)); to = Math.max(to, toMin(p.last) + (maxTurn || 120)); });
		d.bookings.forEach(function (b) { from = Math.min(from, toMin(b.start)); to = Math.max(to, toMin(b.start) + b.minutes); });
		if (from >= to) { from = 11 * 60; to = 23 * 60; }
		from = Math.max(0, Math.floor((from - 30) / 60) * 60);
		to = Math.min(24 * 60, Math.ceil((to + 15) / 60) * 60);
		return [from, to];
	};

	Service.prototype.nowMin = function () {
		var n = this.s.data.now || '';
		return n.slice(0, 10) === this.s.date ? toMin(n.slice(11, 16)) : null;
	};

	/* ---------- render ---------- */

	Service.prototype.render = function () {
		var s = this.s, d = s.data;
		var list = this.visible().filter(function (b) { return b.status !== 'cancelled'; });
		var covers = list.reduce(function (a, b) { return a + b.covers; }, 0);
		var isToday = (d.now || '').slice(0, 10) === s.date;
		var html = '<div class="osb-rest-bar">' +
			'<div class="osb-rest-date">' +
			'<button type="button" class="osb-iconbtn" data-act="day" data-value="-1" aria-label="Previous day"><span class="dashicons dashicons-arrow-left-alt2"></span></button>' +
			'<label class="osb-rest-date__pick"><span>' + esc(d.label) + '</span><input type="date" value="' + esc(s.date) + '" data-act="date"></label>' +
			'<button type="button" class="osb-iconbtn" data-act="day" data-value="1" aria-label="Next day"><span class="dashicons dashicons-arrow-right-alt2"></span></button>' +
			(isToday ? '' : '<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="today">' + esc(T.today) + '</button>') +
			'</div>' +
			'<div class="osb-segmented osb-rest-views" role="tablist">' +
			['timeline', 'floor'].map(function (v) { return '<a href="#" role="tab" aria-selected="' + (s.view === v) + '" class="' + (s.view === v ? 'is-active' : '') + '" data-act="view" data-value="' + v + '">' + esc(T[v]) + '</a>'; }).join('') +
			'</div>' +
			'<p class="osb-rest-sum">' + esc(sprintf(T.summary, list.length, covers)) + '</p>' +
			'<input type="search" class="osb-rest-search" placeholder="' + esc(T.search) + '" value="' + esc(s.search) + '" data-act="search">' +
			'<label class="osb-rest-cx"><input type="checkbox" data-act="cancelled"' + (s.showCancelled ? ' checked' : '') + '> ' + esc(C.statuses.cancelled || 'Cancelled') + '</label>' +
			'<div class="osb-rest-actions">' +
			'<button type="button" class="osb-btn osb-btn--ghost" data-act="walkin"><span class="dashicons dashicons-groups"></span>' + esc(T.walkIn) + '</button>' +
			'<button type="button" class="osb-btn osb-btn--primary" data-act="new"><span class="dashicons dashicons-plus-alt2"></span>' + esc(T.newBooking) + '</button>' +
			'</div></div>';

		if (d.closed) { html += '<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-lock"></span><div>' + esc(T.closed) + '</div></div>'; }
		if (!d.periods.length && !d.closed) { html += '<div class="osb-alert osb-alert--info"><span class="dashicons dashicons-info"></span><div>' + esc(T.noPeriods) + '</div></div>'; }
		if (!d.tables.length) {
			html += '<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div>' + esc(T.noTables) + '</div></div>';
		}
		html += '<div class="osb-rest-main">' + (s.view === 'floor' ? '<div class="osb-rest-floorwrap"></div>' : '<div class="osb-rest-tl"></div>') + '</div>';
		this.root.innerHTML = html;
		if (s.view === 'floor') { this.renderFloor(); } else { this.renderTimeline(); }
		if (!this.drawer) {
			this.drawer = h('aside', { 'class': 'osb-rest-drawer', 'aria-hidden': 'true' });
			document.body.appendChild(this.drawer);
			this.bindDrawer();
		}
	};

	/* ---------- timeline ---------- */

	Service.prototype.rows = function () {
		var d = this.s.data;
		var rows = [{ key: 'u', id: 0, name: T.unassigned, area: '', unassigned: true }];
		var areaName = {};
		d.areas.forEach(function (a) { areaName[a.id] = a.name; });
		var last = null;
		d.tables.forEach(function (t) {
			if (t.area_id !== last && d.areas.length > 1) {
				last = t.area_id;
				rows.push({ key: 'a' + t.area_id, heading: true, name: areaName[t.area_id] || '' });
			}
			rows.push({ key: 't' + t.id, id: t.id, name: t.name, seats: t.min + '–' + t.max, table: t });
		});
		return rows;
	};

	// Spread overlapping bookings in one row into lanes.
	function lanes(items) {
		var ends = [];
		items.sort(function (a, b) { return a.s - b.s || b.e - a.e; });
		items.forEach(function (it) {
			var lane = 0;
			while (lane < ends.length && ends[lane] > it.s) { lane++; }
			it.lane = lane;
			ends[lane] = it.e;
		});
		return Math.max(1, ends.length);
	}

	Service.prototype.renderTimeline = function () {
		var self = this, s = this.s, d = s.data;
		var wrap = this.root.querySelector('.osb-rest-tl');
		var r = this.range();
		this.r = r;
		var width = (r[1] - r[0]) * PPM;
		var rows = this.rows();
		var bk = this.visible();
		var rowItems = {};
		rows.forEach(function (row) { rowItems[row.key] = []; });
		bk.forEach(function (b) {
			var item = { b: b, s: toMin(b.start), e: toMin(b.start) + b.minutes };
			if (!b.tables.length) {
				rowItems.u.push(Object.assign({}, item));
			} else {
				b.tables.forEach(function (tid) { if (rowItems['t' + tid]) { rowItems['t' + tid].push(Object.assign({}, item)); } else { rowItems.u.push(Object.assign({}, item)); } });
			}
		});

		var head = '<div class="osb-tl__corner"></div><div class="osb-tl__scale" style="width:' + width + 'px">';
		d.periods.forEach(function (p) {
			var a = (toMin(p.start) - r[0]) * PPM, b = (toMin(p.last) - r[0]) * PPM;
			head += '<span class="osb-tl__period" style="left:' + a + 'px;width:' + Math.max(40, b - a) + 'px">' + esc(p.name) + '</span>';
		});
		for (var m = r[0]; m <= r[1]; m += 30) {
			head += '<span class="osb-tl__tick' + (m % 60 ? ' is-half' : '') + '" style="left:' + ((m - r[0]) * PPM) + 'px">' + (m % 60 ? '' : esc(fmt(toHM(m)))) + '</span>';
		}
		head += '</div>';

		var body = '';
		rows.forEach(function (row) {
			if (row.heading) {
				body += '<div class="osb-tl__label osb-tl__label--area">' + esc(row.name) + '</div><div class="osb-tl__row osb-tl__row--area" style="width:' + width + 'px"></div>';
				return;
			}
			var items = rowItems[row.key];
			var n = lanes(items);
			var hgt = Math.max(1, n) * 34 + 8;
			var clash = n > 1 && !row.unassigned;
			body += '<div class="osb-tl__label' + (row.unassigned ? ' osb-tl__label--wait' : '') + (clash ? ' has-clash' : '') + '" style="height:' + hgt + 'px"><strong>' + esc(row.name) + '</strong>' + (row.seats ? '<small>' + esc(row.seats) + '</small>' : '') + '</div>';
			body += '<div class="osb-tl__row' + (row.unassigned ? ' osb-tl__row--wait' : '') + '" data-row="' + row.key + '" data-table="' + (row.id || 0) + '" style="height:' + hgt + 'px;width:' + width + 'px">';
			d.periods.forEach(function (p) {
				var a = (toMin(p.start) - r[0]) * PPM, b2 = (toMin(p.last) - r[0]) * PPM;
				body += '<span class="osb-tl__band" style="left:' + a + 'px;width:' + Math.max(0, b2 - a) + 'px"></span>';
			});
			items.forEach(function (it) {
				var b = it.b;
				var left = (it.s - r[0]) * PPM, w = Math.max(24, (it.e - it.s) * PPM - 2);
				var multi = b.tables.length > 1;
				var big = row.table && b.covers > row.table.max * (multi ? 99 : 1);
				body += '<div class="osb-tl__bk is-' + esc(b.status) + (multi ? ' is-multi' : '') + (clash ? ' is-clash' : '') + (big ? ' is-big' : '') + (s.open === b.id ? ' is-open' : '') + '" data-id="' + b.id + '" style="left:' + left + 'px;width:' + w + 'px;top:' + (4 + it.lane * 34) + 'px" tabindex="0" title="' + esc(b.name + ' · ' + b.covers + ' · ' + fmt(b.start) + '–' + fmt(b.end)) + '">' +
					'<span class="osb-tl__cv">' + b.covers + '</span><span class="osb-tl__nm">' + esc(b.name) + '</span>' +
					(b.card_held ? '<span class="dashicons dashicons-lock" title="' + esc(T.cardOnFile) + '"></span>' : '') +
					(b.notes ? '<span class="dashicons dashicons-format-chat" title="' + esc(b.notes) + '"></span>' : '') +
					'<span class="osb-tl__grip" aria-hidden="true"></span></div>';
			});
			body += '</div>';
		});

		var now = this.nowMin();
		var nowLine = now !== null && now >= r[0] && now <= r[1] ? '<span class="osb-tl__now" style="left:calc(var(--osb-tl-label) + ' + ((now - r[0]) * PPM) + 'px)"></span>' : '';
		wrap.innerHTML = '<p class="osb-help osb-rest-hint">' + esc(T.dropHint) + '</p><div class="osb-tl"><div class="osb-tl__grid" style="grid-template-columns:var(--osb-tl-label) ' + width + 'px">' + head + body + nowLine + '</div></div>';
		// Scroll to now (or the first sitting) the first time.
		var scroller = wrap.querySelector('.osb-tl');
		var target = now !== null ? now - 60 : (d.periods.length ? toMin(d.periods[0].start) - 30 : r[0]);
		if (this.keepScroll != null) { scroller.scrollLeft = this.keepScroll; } else { scroller.scrollLeft = Math.max(0, (target - r[0]) * PPM); }
		scroller.addEventListener('scroll', function () { self.keepScroll = scroller.scrollLeft; });
	};

	/* ---------- timeline drag ---------- */

	Service.prototype.startDrag = function (e, el) {
		var self = this;
		var b = this.booking(parseInt(el.getAttribute('data-id'), 10));
		if (!b || e.button > 0) { return; }
		var resize = !!e.target.closest('.osb-tl__grip');
		var row = el.parentNode;
		var start = { x: e.clientX, y: e.clientY, left: parseFloat(el.style.left), width: parseFloat(el.style.width), row: row };
		var moved = false;
		var ghost = null;
		this.drag = true;
		var target = row;

		function move(ev) {
			var dx = ev.clientX - start.x, dy = ev.clientY - start.y;
			if (!moved && Math.abs(dx) < 4 && Math.abs(dy) < 4) { return; }
			if (!moved) {
				moved = true;
				ghost = el.cloneNode(true);
				ghost.classList.add('is-ghost');
				el.classList.add('is-dragging');
				row.appendChild(ghost);
			}
			var step = SNAP * PPM;
			if (resize) {
				ghost.style.width = Math.max(step, Math.round((start.width + dx) / step) * step) + 'px';
				return;
			}
			ghost.style.left = Math.round((start.left + dx) / step) * step + 'px';
			var over = document.elementFromPoint(ev.clientX, ev.clientY);
			var r2 = over ? over.closest('.osb-tl__row[data-row]') : null;
			if (r2 && r2 !== target) {
				if (target) { target.classList.remove('is-target'); }
				target = r2;
				target.classList.add('is-target');
				target.appendChild(ghost);
			}
		}
		function up() {
			document.removeEventListener('pointermove', move);
			document.removeEventListener('pointerup', up);
			self.drag = false;
			if (target) { target.classList.remove('is-target'); }
			if (!moved) { el.classList.remove('is-dragging'); return self.openDrawer(b.id); }
			var body = {};
			if (resize) {
				body.duration = Math.max(SNAP, Math.round(parseFloat(ghost.style.width) / PPM / SNAP) * SNAP);
			} else {
				var newStart = toHM(self.r[0] + Math.round(parseFloat(ghost.style.left) / PPM));
				if (newStart !== b.start) { body.time = newStart; }
				if (target !== row) {
					var tid = parseInt(target.getAttribute('data-table'), 10) || 0;
					body.tables = tid ? [tid] : [];
				}
			}
			if (!Object.keys(body).length) { ghost.remove(); el.classList.remove('is-dragging'); return; }
			self.save(b.id, body);
		}
		document.addEventListener('pointermove', move);
		document.addEventListener('pointerup', up);
		e.preventDefault();
	};

	Service.prototype.save = function (id, body, done) {
		var self = this;
		return api('/admin/tables/bookings/' + id, { method: 'POST', body: body }).then(function (res) {
			if (res.warnings && res.warnings.length) { toast(T.warn, res.warnings); } else { toast(T.saved); }
			if (done) { done(res); }
			return self.load(true);
		}).catch(function (e) { toast(e.message, null, true); self.load(true); });
	};

	/* ---------- floor plan (live) ---------- */

	Service.prototype.renderFloor = function () {
		var self = this, s = this.s, d = s.data;
		var wrap = this.root.querySelector('.osb-rest-floorwrap');
		var r = this.range();
		if (s.at === null || s.at < r[0] || s.at > r[1]) {
			var now = this.nowMin();
			s.at = now !== null && now >= r[0] && now <= r[1] ? Math.floor(now / 5) * 5 : (d.periods.length ? toMin(d.periods[d.periods.length - 1].start) + 60 : r[0]);
		}
		var area = d.areas.filter(function (a) { return a.id === s.area; })[0] || d.areas[0];
		var tabs = d.areas.length > 1 ? '<nav class="osb-segmented osb-rest-areatabs">' + d.areas.map(function (a) { return '<a href="#" data-act="area" data-value="' + a.id + '" class="' + (area && a.id === area.id ? 'is-active' : '') + '">' + esc(a.name) + '</a>'; }).join('') + '</nav>' : '';
		var bk = this.visible().filter(function (b) { return b.status !== 'cancelled' && b.status !== 'no_show'; });
		var at = s.at;
		var occupant = {}, next = {};
		bk.forEach(function (b) {
			var st = toMin(b.start), en = st + b.minutes;
			b.tables.forEach(function (tid) {
				if (st <= at && en > at && b.status !== 'completed') { occupant[tid] = b; }
				if (st > at && (!next[tid] || toMin(next[tid].start) > st)) { next[tid] = b; }
			});
		});
		var plan = '';
		if (area) {
			d.tables.filter(function (t) { return t.area_id === area.id; }).forEach(function (t) {
				var o = occupant[t.id], n = next[t.id];
				var state = o ? (o.status === 'seated' ? 'seated' : (o.status === 'pending' ? 'pending' : 'booked')) : 'free';
				var soon = !o && n && toMin(n.start) - at <= 30;
				plan += '<div class="osb-ft osb-ft--' + esc(t.shape) + ' is-' + state + (soon ? ' is-soon' : '') + '" data-table="' + t.id + '" data-id="' + (o ? o.id : '') + '" style="left:' + (t.x / area.width * 100) + '%;top:' + (t.y / area.height * 100) + '%;width:' + (t.w / area.width * 100) + '%;height:' + (t.h / area.height * 100) + '%;transform:rotate(' + t.rotation + 'deg)">' +
					'<span class="osb-ft__in" style="transform:rotate(' + (-t.rotation) + 'deg)"><b>' + esc(t.name) + '</b>' +
					(o ? '<span>' + esc(o.name) + ' · ' + o.covers + '</span><small>' + esc(sprintf(T.until, fmt(toHM(toMin(o.start) + o.minutes)))) + '</small>' : '<small>' + esc(n ? sprintf(T.nextAt, fmt(n.start)) : T.free) + '</small>') +
					'</span></div>';
			});
		}
		var listHtml = bk.slice().sort(function (a, b) { return toMin(a.start) - toMin(b.start); }).map(function (b) {
			return '<li draggable="true" class="osb-fl__item is-' + esc(b.status) + (b.tables.length ? '' : ' is-wait') + '" data-id="' + b.id + '"><span class="osb-fl__time">' + esc(fmt(b.start)) + '</span><span class="osb-fl__who"><strong>' + esc(b.name) + '</strong><small>' + b.covers + ' · ' + esc(b.tables.length ? self.tableNames(b.tables) : T.unassigned) + '</small></span></li>';
		}).join('');
		var now2 = this.nowMin();
		wrap.innerHTML = '<p class="osb-help osb-rest-hint">' + esc(T.floorHint) + '</p>' + tabs +
			'<div class="osb-fl"><div class="osb-fl__planwrap">' +
			'<div class="osb-fl__slider"><label>' + esc(T.at) + ' <strong>' + esc(fmt(toHM(at))) + '</strong></label><input type="range" min="' + r[0] + '" max="' + r[1] + '" step="5" value="' + at + '" data-act="at">' +
			(now2 !== null ? '<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="atnow">' + esc(T.now) + '</button>' : '') + '</div>' +
			(area ? '<div class="osb-fl__plan" style="aspect-ratio:' + area.width + '/' + area.height + '">' + plan + '</div>' : '') +
			'<div class="osb-fl__legend"><span class="is-free">' + esc(T.free) + '</span><span class="is-booked">' + esc(C.statuses.confirmed || '') + '</span><span class="is-seated">' + esc(C.statuses.seated || '') + '</span><span class="is-pending">' + esc(C.statuses.pending || '') + '</span></div>' +
			'</div><ul class="osb-fl__list">' + (listHtml || '<li class="osb-muted">—</li>') + '</ul></div>';
	};

	Service.prototype.tableNames = function (ids) {
		var map = {};
		this.s.data.tables.forEach(function (t) { map[t.id] = t.name; });
		return ids.map(function (id) { return map[id] || '#' + id; }).join(' + ');
	};

	/* ---------- drawer ---------- */

	Service.prototype.openDrawer = function (id) {
		var b = this.booking(id);
		if (!b) { return; }
		this.s.open = id;
		var self = this;
		var st = C.statuses;
		var actions = [];
		if (b.status === 'pending') { actions.push(['confirmed', T.confirm, 'primary']); }
		if (['confirmed', 'pending'].indexOf(b.status) > -1) { actions.push(['seated', T.seat, 'primary']); }
		if (b.status === 'seated') { actions.push(['completed', T.finish, 'primary']); }
		if (['confirmed', 'pending', 'pending_payment'].indexOf(b.status) > -1) { actions.push(['no_show', T.noShow, 'ghost']); }
		if (['cancelled', 'completed', 'no_show'].indexOf(b.status) === -1) { actions.push(['cancelled', T.cancelBk, 'danger']); }
		var tables = this.s.data.tables;
		var areas = {};
		this.s.data.areas.forEach(function (a) { areas[a.id] = a.name; });
		var lastArea = null;
		var tableChecks = tables.map(function (t) {
			var head = '';
			if (t.area_id !== lastArea && self.s.data.areas.length > 1) { lastArea = t.area_id; head = '<span class="osb-chips-check__group">' + esc(areas[t.area_id] || '') + '</span>'; }
			return head + '<label><input type="checkbox" name="tables" value="' + t.id + '"' + (b.tables.indexOf(t.id) > -1 ? ' checked' : '') + '><span>' + esc(t.name) + ' <small>(' + t.max + ')</small></span></label>';
		}).join('');
		this.drawer.innerHTML = '<div class="osb-admin osb-rest-drawer__in">' +
			'<div class="osb-rest-drawer__head"><div><h2>' + esc(b.name) + '</h2><p><span class="osb-pill osb-pill--' + esc(b.status) + '">' + esc(st[b.status] || b.status) + '</span> <span class="osb-muted">#' + b.id + '</span></p></div><button type="button" class="osb-iconbtn" data-act="close" aria-label="' + esc(T.close) + '"><span class="dashicons dashicons-no-alt"></span></button></div>' +
			'<dl class="osb-dl">' +
			'<dt>' + esc(T.time) + '</dt><dd>' + esc(fmt(b.start) + ' – ' + fmt(b.end)) + '</dd>' +
			'<dt>' + esc(T.covers) + '</dt><dd>' + b.covers + '</dd>' +
			'<dt>' + esc(T.tables) + '</dt><dd>' + esc(b.tables.length ? this.tableNames(b.tables) : T.unassigned) + '</dd>' +
			(b.phone ? '<dt>' + esc(T.phone) + '</dt><dd><a href="tel:' + esc(b.phone) + '">' + esc(b.phone) + '</a></dd>' : '') +
			(b.email ? '<dt>' + esc(T.email) + '</dt><dd><a href="mailto:' + esc(b.email) + '">' + esc(b.email) + '</a></dd>' : '') +
			(b.amount ? '<dt>' + esc(T.paid) + '</dt><dd>' + esc(b.amount) + (b.payment === 'paid' ? '' : ' (' + esc(b.payment) + ')') + '</dd>' : '') +
			(b.card_held ? '<dt>' + esc(T.cardOnFile) + '</dt><dd><span class="dashicons dashicons-lock"></span> ' + esc(b.fee) + '</dd>' : '') +
			'</dl>' +
			(b.notes ? '<div class="osb-rest-note"><strong>' + esc(T.notes) + '</strong><p>' + esc(b.notes) + '</p></div>' : '') +
			(actions.length ? '<div class="osb-rest-drawer__acts">' + actions.map(function (a) { return '<button type="button" class="osb-btn osb-btn--' + (a[2] === 'danger' ? 'ghost osb-btn--danger' : a[2]) + ' osb-btn--sm" data-act="status" data-value="' + a[0] + '">' + esc(a[1]) + '</button>'; }).join('') + '</div>' : '') +
			(b.card_held && C.cardHold && b.payment !== 'paid' ? '<p><button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="charge"><span class="dashicons dashicons-money-alt"></span>' + esc(T.charge) + '</button></p>' : '') +
			'<form class="osb-rest-drawer__form" data-id="' + b.id + '">' +
			'<div class="osb-fieldgrid">' +
			'<div class="osb-field osb-field--third"><label>' + esc(T.time) + '</label><input type="time" name="time" step="300" value="' + esc(b.start) + '"></div>' +
			'<div class="osb-field osb-field--third"><label>' + esc(T.covers) + '</label><input type="number" name="covers" min="1" value="' + b.covers + '"></div>' +
			'<div class="osb-field osb-field--third"><label>' + esc(T.duration) + '</label><input type="number" name="duration" min="15" step="15" value="' + b.minutes + '"></div>' +
			'</div>' +
			'<div class="osb-field"><label>' + esc(T.tables) + '</label><div class="osb-chips-check osb-rest-tablepick">' + tableChecks + '</div></div>' +
			'<div class="osb-field"><label>' + esc(T.internal) + '</label><textarea name="note" rows="2">' + esc(b.internal) + '</textarea></div>' +
			(b.email ? '<label class="osb-switch"><input type="checkbox" name="notify" value="1"><span></span>' + esc(T.notifyAsk) + '</label>' : '') +
			'<div class="osb-formfoot"><a class="osb-link" href="' + esc(b.url) + '">' + esc(T.open) + ' →</a><button class="osb-btn osb-btn--primary">' + esc(T.save) + '</button></div>' +
			'</form></div>';
		this.drawer.classList.add('is-open');
		this.drawer.setAttribute('aria-hidden', 'false');
		Array.prototype.forEach.call(this.root.querySelectorAll('.osb-tl__bk.is-open'), function (x) { x.classList.remove('is-open'); });
		Array.prototype.forEach.call(this.root.querySelectorAll('.osb-tl__bk[data-id="' + id + '"]'), function (x) { x.classList.add('is-open'); });
	};

	Service.prototype.closeDrawer = function () {
		this.s.open = null;
		if (this.drawer) { this.drawer.classList.remove('is-open'); this.drawer.setAttribute('aria-hidden', 'true'); }
		Array.prototype.forEach.call(this.root.querySelectorAll('.osb-tl__bk.is-open'), function (x) { x.classList.remove('is-open'); });
	};

	Service.prototype.bindDrawer = function () {
		var self = this;
		this.drawer.addEventListener('click', function (e) {
			var el = e.target.closest('[data-act]');
			if (!el) { return; }
			var act = el.getAttribute('data-act');
			var id = self.s.open;
			var b = self.booking(id);
			if (act === 'close') { return self.closeDrawer(); }
			if (act === 'status' && b) {
				var to = el.getAttribute('data-value');
				if (to === 'cancelled' && !window.confirm(T.cancelAsk)) { return; }
				var notify = b.email && ['cancelled', 'confirmed'].indexOf(to) > -1 ? window.confirm(T.notifyAsk) : false;
				el.disabled = true;
				self.save(id, { status: to, notify: notify });
			}
			if (act === 'charge' && b) {
				if (!window.confirm(sprintf(T.chargeAsk, b.fee))) { return; }
				el.disabled = true;
				api('/admin/tables/charge/' + id, { method: 'POST', body: {} }).then(function () { toast(T.charged); self.load(true); }).catch(function (er) { el.disabled = false; toast(er.message, null, true); });
			}
		});
		this.drawer.addEventListener('submit', function (e) {
			e.preventDefault();
			var f = e.target;
			var b = self.booking(parseInt(f.getAttribute('data-id'), 10));
			if (!b) { return; }
			var tables = Array.prototype.map.call(f.querySelectorAll('[name="tables"]:checked'), function (x) { return parseInt(x.value, 10); });
			var body = { note: f.note.value };
			if (f.time.value && f.time.value !== b.start) { body.time = f.time.value; }
			if (parseInt(f.covers.value, 10) !== b.covers) { body.covers = parseInt(f.covers.value, 10); }
			if (parseInt(f.duration.value, 10) !== b.minutes) { body.duration = parseInt(f.duration.value, 10); }
			if (tables.slice().sort().join(',') !== b.tables.slice().sort().join(',')) { body.tables = tables; }
			if (f.notify && f.notify.checked) { body.notify = true; }
			self.save(b.id, body);
		});
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && self.s.open && !self.modal) { self.closeDrawer(); } });
	};

	/* ---------- new booking / walk-in ---------- */

	Service.prototype.openModal = function (kind) {
		var self = this, d = this.s.data;
		var now = this.nowMin();
		var defTime = now !== null ? toHM(Math.ceil(now / SNAP) * SNAP) : (d.periods.length ? d.periods[d.periods.length - 1].start : '19:00');
		var walk = kind === 'walkin';
		var statuses = ['confirmed', 'pending', 'seated'];
		var m = h('div', { 'class': 'osb-rest-modal', role: 'dialog', 'aria-modal': 'true' });
		m.innerHTML = '<div class="osb-admin osb-rest-modal__box"><form class="osb-form">' +
			'<div class="osb-rest-drawer__head"><h2>' + esc(walk ? T.walkIn : T.newBooking) + '</h2><button type="button" class="osb-iconbtn" data-act="x" aria-label="' + esc(T.close) + '"><span class="dashicons dashicons-no-alt"></span></button></div>' +
			'<div class="osb-fieldgrid">' +
			(walk ? '' : '<div class="osb-field osb-field--third"><label>' + esc(T.date) + '</label><input type="date" name="date" required value="' + esc(this.s.date) + '"></div>') +
			'<div class="osb-field osb-field--third"><label>' + esc(T.time) + '</label><input type="time" name="time" step="300" required value="' + esc(defTime) + '"></div>' +
			'<div class="osb-field osb-field--third"><label>' + esc(T.covers) + '</label><input type="number" name="covers" min="1" required value="2"></div>' +
			(walk ? '<div class="osb-field osb-field--third"><label>' + esc(T.duration) + '</label><input type="number" name="duration" min="15" step="15" placeholder="auto"></div>' : '') +
			'<div class="osb-field osb-field--half"><label>' + esc(T.name) + (walk ? '' : ' <span class="osb-req">*</span>') + '</label><input type="text" name="name"' + (walk ? '' : ' required') + '></div>' +
			'<div class="osb-field osb-field--half"><label>' + esc(T.phone) + '</label><input type="tel" name="phone"></div>' +
			(walk ? '' : '<div class="osb-field osb-field--half"><label>' + esc(T.email) + '</label><input type="email" name="email"></div>') +
			(walk ? '' : '<div class="osb-field osb-field--half"><label>' + esc(T.status) + '</label><select name="status">' + statuses.map(function (k) { return '<option value="' + k + '">' + esc(C.statuses[k] || k) + '</option>'; }).join('') + '</select></div>') +
			'</div>' +
			'<div class="osb-field"><label>' + esc(T.tables) + '</label><div class="osb-rest-suggest"></div></div>' +
			(walk ? '' : '<div class="osb-field"><label>' + esc(T.notes) + '</label><textarea name="notes" rows="2"></textarea></div>') +
			'<div class="osb-field"><label>' + esc(T.internal) + '</label><textarea name="internal" rows="2"></textarea></div>' +
			(walk ? '' : '<label class="osb-switch"><input type="checkbox" name="notify" value="1" checked><span></span>' + esc(T.sendEmail) + '</label>') +
			'<div class="osb-formfoot"><button type="button" class="osb-btn osb-btn--ghost" data-act="x">' + esc(T.cancel) + '</button><button class="osb-btn osb-btn--primary">' + esc(T.save) + '</button></div>' +
			'</form></div>';
		document.body.appendChild(m);
		this.modal = m;
		var form = m.querySelector('form');
		var box = m.querySelector('.osb-rest-suggest');
		var picked = null; // null = best table; [] = none; [ids] = chosen
		var tmr = null;
		function suggest() {
			clearTimeout(tmr);
			tmr = setTimeout(function () {
				var q = { date: walk ? self.s.date : form.date.value, time: form.time.value, covers: form.covers.value, duration: walk && form.duration.value ? form.duration.value : '' };
				api('/admin/tables/suggest', { query: q }).then(function (res) {
					var free = res.free || [];
					var fitIds = res.fit ? res.fit.tables : [];
					var html = '<div class="osb-chips-check osb-rest-tablepick">' +
						'<label class="is-all"><input type="radio" name="tmode" value="auto"' + (picked === null ? ' checked' : '') + '><span>' + esc(T.autoTable) + (res.fit ? ' — <b>' + esc(res.fit.label) + '</b>' : '') + '</span></label>' +
						'<label class="is-all"><input type="radio" name="tmode" value="none"' + (picked && !picked.length ? ' checked' : '') + '><span>' + esc(T.noTable) + '</span></label>' +
						d.tables.map(function (t) {
							var isFree = free.indexOf(t.id) > -1;
							var on = picked && picked.length ? picked.indexOf(t.id) > -1 : false;
							return '<label class="' + (isFree ? '' : 'is-busy') + (fitIds.indexOf(t.id) > -1 && picked === null ? ' is-suggested' : '') + '"><input type="checkbox" name="tpick" value="' + t.id + '"' + (on ? ' checked' : '') + '><span>' + esc(t.name) + ' <small>(' + t.max + ')</small></span></label>';
						}).join('') + '</div>' + (res.fit ? '' : '<p class="osb-help">' + esc(T.noFit) + '</p>');
					box.innerHTML = html;
				}).catch(function () { box.innerHTML = ''; });
			}, 200);
		}
		suggest();
		form.addEventListener('input', function (e) { if (['time', 'covers', 'date', 'duration'].indexOf(e.target.name) > -1) { suggest(); } });
		form.addEventListener('change', function (e) {
			if (e.target.name === 'tmode') { picked = e.target.value === 'none' ? [] : null; Array.prototype.forEach.call(form.querySelectorAll('[name="tpick"]'), function (x) { x.checked = false; }); }
			if (e.target.name === 'tpick') {
				picked = Array.prototype.map.call(form.querySelectorAll('[name="tpick"]:checked'), function (x) { return parseInt(x.value, 10); });
				if (!picked.length) { picked = null; }
				Array.prototype.forEach.call(form.querySelectorAll('[name="tmode"]'), function (x) { x.checked = picked === null && x.value === 'auto'; });
			}
		});
		function close() { m.remove(); self.modal = null; }
		m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-act="x"]')) { close(); } });
		m.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = form.querySelector('.osb-btn--primary');
			btn.disabled = true;
			var body = { time: form.time.value, covers: parseInt(form.covers.value, 10) || 2, name: form.name.value, phone: form.phone.value, internal: form.internal.value };
			if (picked !== null) { body.tables = picked.length ? picked : [0]; }
			var path = '/admin/tables/reservations';
			if (walk) {
				path = '/admin/tables/walkin';
				body.date = self.s.date;
				body.note = form.internal.value;
				if (form.duration.value) { body.duration = parseInt(form.duration.value, 10); }
				if (picked === null) { delete body.tables; }
			} else {
				body.date = form.date.value;
				body.email = form.email.value;
				body.notes = form.notes.value;
				body.status = form.status.value;
				body.notify = form.notify.checked;
			}
			api(path, { method: 'POST', body: body }).then(function (res) {
				close();
				if (res.warnings && res.warnings.length) { toast(T.warn, res.warnings); } else { toast(T.saved); }
				if (!walk && body.date !== self.s.date) { self.s.date = body.date; }
				self.s.focus = res.id;
				self.load(true);
			}).catch(function (er) { btn.disabled = false; toast(er.message, null, true); });
		});
		setTimeout(function () { var first = form.querySelector('[name="covers"]'); if (first) { first.focus(); first.select(); } }, 30);
	};

	/* ---------- events ---------- */

	Service.prototype.go = function (date) {
		this.s.date = date;
		this.keepScroll = null;
		this.s.at = null;
		this.closeDrawer();
		if (window.history && window.history.replaceState) {
			var u = new URL(window.location.href);
			u.searchParams.set('date', date);
			u.searchParams.delete('booking');
			window.history.replaceState({}, '', u.toString());
		}
		this.load();
	};

	Service.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('click', function (e) {
			var el = e.target.closest('[data-act]');
			if (el) {
				var act = el.getAttribute('data-act'), val = el.getAttribute('data-value');
				if (act === 'day') { e.preventDefault(); return self.go(shiftDate(self.s.date, parseInt(val, 10))); }
				if (act === 'today') { e.preventDefault(); return self.go((self.s.data.now || '').slice(0, 10)); }
				if (act === 'view') { e.preventDefault(); self.s.view = val; try { window.localStorage.setItem('osbRestView', val); } catch (er) { /* ignore */ } return self.render(); }
				if (act === 'area') { e.preventDefault(); self.s.area = parseInt(val, 10); return self.renderFloor(); }
				if (act === 'new' || act === 'walkin') { e.preventDefault(); return self.openModal(act); }
				if (act === 'atnow') { e.preventDefault(); self.s.at = null; return self.renderFloor(); }
			}
			var ft = e.target.closest('.osb-ft');
			if (ft && ft.getAttribute('data-id')) { return self.openDrawer(parseInt(ft.getAttribute('data-id'), 10)); }
			var li = e.target.closest('.osb-fl__item');
			if (li) { return self.openDrawer(parseInt(li.getAttribute('data-id'), 10)); }
		});
		this.root.addEventListener('pointerdown', function (e) {
			var bk = e.target.closest('.osb-tl__bk');
			if (bk && !bk.classList.contains('is-ghost')) { self.startDrag(e, bk); }
		});
		this.root.addEventListener('keydown', function (e) {
			var bk = e.target.closest('.osb-tl__bk');
			if (bk && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); self.openDrawer(parseInt(bk.getAttribute('data-id'), 10)); }
		});
		this.root.addEventListener('input', function (e) {
			var act = e.target.getAttribute('data-act');
			if (act === 'search') {
				self.s.search = e.target.value;
				var pos = e.target.selectionStart;
				self.render();
				var inp = self.root.querySelector('[data-act="search"]');
				inp.focus();
				try { inp.setSelectionRange(pos, pos); } catch (er) { /* ignore */ }
			}
			if (act === 'at') {
				self.s.at = parseInt(e.target.value, 10);
				self.renderFloor();
				var sl = self.root.querySelector('[data-act="at"]');
				if (sl) { sl.focus(); }
			}
		});
		this.root.addEventListener('change', function (e) {
			var act = e.target.getAttribute('data-act');
			if (act === 'date' && e.target.value) { self.go(e.target.value); }
			if (act === 'cancelled') { self.s.showCancelled = e.target.checked; self.render(); }
		});
		// Floor plan: drag a booking from the list onto a table.
		this.root.addEventListener('dragstart', function (e) {
			var li = e.target.closest('.osb-fl__item');
			if (li) { e.dataTransfer.setData('text/plain', li.getAttribute('data-id')); e.dataTransfer.effectAllowed = 'move'; }
		});
		this.root.addEventListener('dragover', function (e) {
			var ft = e.target.closest('.osb-ft');
			if (ft) { e.preventDefault(); ft.classList.add('is-drop'); }
		});
		this.root.addEventListener('dragleave', function (e) {
			var ft = e.target.closest('.osb-ft');
			if (ft) { ft.classList.remove('is-drop'); }
		});
		this.root.addEventListener('drop', function (e) {
			var ft = e.target.closest('.osb-ft');
			if (!ft) { return; }
			e.preventDefault();
			ft.classList.remove('is-drop');
			var id = parseInt(e.dataTransfer.getData('text/plain'), 10);
			var tid = parseInt(ft.getAttribute('data-table'), 10);
			var b = self.booking(id);
			if (!b || !tid) { return; }
			// Holding Shift adds the table to the booking (push tables together).
			var tables = e.shiftKey ? b.tables.concat([tid]).filter(function (v, i, a) { return a.indexOf(v) === i; }) : [tid];
			self.save(id, { tables: tables });
		});
	};

	/* ======================================================================
	 * Floor-plan editor
	 * ==================================================================== */

	function Editor(root) {
		this.root = root;
		this.area = JSON.parse(root.getAttribute('data-area') || '{}');
		this.tables = JSON.parse(root.getAttribute('data-tables') || '[]');
		this.sel = null;
		this.dirty = false;
		this.uid = -1;
		var self = this;
		this.render();
		window.addEventListener('beforeunload', function (e) { if (self.dirty) { e.preventDefault(); e.returnValue = T.leave; return T.leave; } });
		window.addEventListener('resize', function () { self.layout(); });
	}

	Editor.prototype.scale = function () {
		var plan = this.root.querySelector('.osb-fe__plan');
		return plan ? plan.clientWidth / this.area.width : 1;
	};

	Editor.prototype.render = function () {
		var self = this;
		this.root.innerHTML = '<div class="osb-fe">' +
			'<div class="osb-fe__bar">' +
			'<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="add"><span class="dashicons dashicons-plus-alt2"></span>' + esc(T.addTable) + '</button>' +
			'<label class="osb-fe__size">' + esc(T.canvasSize) + ' <input type="number" min="300" max="4000" step="50" value="' + this.area.width + '" data-act="w"> × <input type="number" min="200" max="4000" step="50" value="' + this.area.height + '" data-act="h"></label>' +
			'<span class="osb-fe__dirty" hidden>' + esc(T.unsaved) + '</span>' +
			'<button type="button" class="osb-btn osb-btn--primary osb-btn--sm" data-act="save">' + esc(T.saveLayout) + '</button>' +
			'</div>' +
			'<div class="osb-fe__body"><div class="osb-fe__canvas"><div class="osb-fe__plan" tabindex="0" style="aspect-ratio:' + this.area.width + '/' + this.area.height + '"></div></div>' +
			'<div class="osb-fe__panel"></div></div></div>';
		this.plan = this.root.querySelector('.osb-fe__plan');
		this.panel = this.root.querySelector('.osb-fe__panel');
		this.bind();
		this.layout();
		this.renderPanel();
	};

	Editor.prototype.layout = function () {
		var self = this;
		var A = this.area;
		this.plan.style.aspectRatio = A.width + '/' + A.height;
		this.plan.style.backgroundSize = (50 / A.width * 100) + '% ' + (50 / A.height * 100) + '%';
		this.plan.innerHTML = this.tables.map(function (t) {
			return '<div class="osb-ft osb-ft--' + esc(t.shape) + (t.status === 'inactive' ? ' is-off' : '') + (self.sel === t.id ? ' is-selected' : '') + '" data-id="' + t.id + '" style="left:' + (t.x / A.width * 100) + '%;top:' + (t.y / A.height * 100) + '%;width:' + (t.w / A.width * 100) + '%;height:' + (t.h / A.height * 100) + '%;transform:rotate(' + t.rotation + 'deg)">' +
				'<span class="osb-ft__in" style="transform:rotate(' + (-t.rotation) + 'deg)"><b>' + esc(t.name) + '</b><small>' + t.min + '–' + t.max + (t.online ? '' : ' · ✕') + '</small></span>' +
				'<span class="osb-ft__resize" aria-hidden="true"></span></div>';
		}).join('');
	};

	Editor.prototype.table = function (id) {
		return this.tables.filter(function (t) { return t.id === id; })[0] || null;
	};

	Editor.prototype.mark = function () {
		this.dirty = true;
		var d = this.root.querySelector('.osb-fe__dirty');
		if (d) { d.hidden = false; }
	};

	Editor.prototype.renderPanel = function () {
		var t = this.table(this.sel);
		if (!t) {
			this.panel.innerHTML = '<p class="osb-muted">' + esc(T.selectTable) + '</p>';
			return;
		}
		var shapes = T.shapes || {};
		this.panel.innerHTML = '<div class="osb-fe__props">' +
			'<div class="osb-field"><label>' + esc(T.tableName) + '</label><input type="text" maxlength="64" data-prop="name" value="' + esc(t.name) + '"></div>' +
			'<div class="osb-fieldgrid"><div class="osb-field osb-field--half"><label>' + esc(T.minCovers) + '</label><input type="number" min="1" data-prop="min" value="' + t.min + '"></div>' +
			'<div class="osb-field osb-field--half"><label>' + esc(T.maxCovers) + '</label><input type="number" min="1" data-prop="max" value="' + t.max + '"></div></div>' +
			'<div class="osb-field"><label>' + esc(T.shape) + '</label><select data-prop="shape">' + Object.keys(shapes).map(function (k) { return '<option value="' + k + '"' + (t.shape === k ? ' selected' : '') + '>' + esc(shapes[k]) + '</option>'; }).join('') + '</select></div>' +
			'<div class="osb-field"><label>' + esc(T.priority) + '</label><input type="number" data-prop="priority" value="' + t.priority + '"><p class="osb-help">' + esc(T.priorityHelp) + '</p></div>' +
			'<label class="osb-switch"><input type="checkbox" data-prop="online"' + (t.online ? ' checked' : '') + '><span></span>' + esc(T.online) + '</label>' +
			'<label class="osb-switch"><input type="checkbox" data-prop="active"' + (t.status !== 'inactive' ? ' checked' : '') + '><span></span>' + esc(T.active) + '</label>' +
			'<div class="osb-fe__btns">' +
			'<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="rot" data-value="-15">⟲ 15°</button>' +
			'<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="rot" data-value="90">⟳ 90°</button>' +
			'<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-act="dup">' + esc(T.duplicate) + '</button>' +
			'<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-btn--danger" data-act="del">' + esc(T.remove) + '</button>' +
			'</div></div>';
	};

	Editor.prototype.nextName = function () {
		var n = 1, names = this.tables.map(function (t) { return t.name; });
		while (names.indexOf('T' + n) > -1) { n++; }
		return 'T' + n;
	};

	Editor.prototype.add = function (from) {
		var t = from ? Object.assign({}, from) : { name: '', min: 1, max: 4, online: true, priority: 0, shape: 'square', x: 40, y: 40, w: 80, h: 80, rotation: 0, status: 'active' };
		t.id = this.uid--;
		t.name = this.nextName();
		if (from) { t.x = Math.min(this.area.width - t.w, from.x + 30); t.y = Math.min(this.area.height - t.h, from.y + 30); }
		else {
			// Find a free spot on a 100-unit grid.
			outer: for (var y = 30; y < this.area.height - 80; y += 110) {
				for (var x = 30; x < this.area.width - 80; x += 110) {
					var clash = this.tables.some(function (o) { return x < o.x + o.w && x + 80 > o.x && y < o.y + o.h && y + 80 > o.y; });
					if (!clash) { t.x = x; t.y = y; break outer; }
				}
			}
		}
		this.tables.push(t);
		this.sel = t.id;
		this.mark();
		this.layout();
		this.renderPanel();
	};

	Editor.prototype.save = function () {
		var self = this;
		var btn = this.root.querySelector('[data-act="save"]');
		btn.disabled = true;
		api('/admin/tables/layout', { method: 'POST', body: { area_id: this.area.id, width: this.area.width, height: this.area.height, tables: this.tables.map(function (t) { var c = Object.assign({}, t); if (c.id < 0) { c.id = 0; } return c; }) } }).then(function (res) {
			btn.disabled = false;
			var selName = (self.table(self.sel) || {}).name;
			self.tables = res.tables;
			self.sel = selName ? ((self.tables.filter(function (t) { return t.name === selName; })[0] || {}).id || null) : null;
			self.dirty = false;
			self.root.querySelector('.osb-fe__dirty').hidden = true;
			self.layout();
			self.renderPanel();
			toast(T.layoutSaved, res.notice ? [res.notice] : null);
		}).catch(function (e) { btn.disabled = false; toast(e.message, null, true); });
	};

	Editor.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('click', function (e) {
			var el = e.target.closest('[data-act]');
			if (!el) { return; }
			var act = el.getAttribute('data-act');
			var t = self.table(self.sel);
			if (act === 'add') { self.add(); }
			if (act === 'save') { self.save(); }
			if (act === 'dup' && t) { self.add(t); }
			if (act === 'del' && t) { self.tables = self.tables.filter(function (x) { return x !== t; }); self.sel = null; self.mark(); self.layout(); self.renderPanel(); }
			if (act === 'rot' && t) { t.rotation = ((t.rotation + parseInt(el.getAttribute('data-value'), 10)) % 360 + 360) % 360; self.mark(); self.layout(); }
		});
		this.root.addEventListener('input', function (e) {
			var act = e.target.getAttribute('data-act');
			if (act === 'w' || act === 'h') {
				var v = parseInt(e.target.value, 10);
				if (v >= 200) { self.area[act === 'w' ? 'width' : 'height'] = v; self.mark(); self.layout(); }
				return;
			}
			var prop = e.target.getAttribute('data-prop');
			var t = self.table(self.sel);
			if (!prop || !t) { return; }
			if (prop === 'online') { t.online = e.target.checked; } else if (prop === 'active') { t.status = e.target.checked ? 'active' : 'inactive'; } else if (prop === 'name' || prop === 'shape') { t[prop] = e.target.value; } else { t[prop] = parseInt(e.target.value, 10) || 0; }
			if (prop === 'shape') {
				if (t.shape === 'rect' && t.w === t.h) { t.w = t.w * 2; }
				if ((t.shape === 'round' || t.shape === 'square') && t.w !== t.h) { t.h = t.w = Math.max(t.w, t.h) > 120 ? 100 : Math.max(t.w, t.h); }
			}
			if (prop === 'min' && t.max < t.min) { t.max = t.min; }
			self.mark();
			self.layout();
		});
		this.root.addEventListener('change', function (e) {
			if (e.target.getAttribute('data-prop') === 'shape' || e.target.getAttribute('data-prop') === 'min') { self.renderPanel(); }
		});
		this.root.addEventListener('pointerdown', function (e) {
			var el = e.target.closest('.osb-fe__plan .osb-ft');
			if (!el) {
				if (e.target.closest('.osb-fe__plan')) { self.sel = null; self.layout(); self.renderPanel(); }
				return;
			}
			var t = self.table(parseInt(el.getAttribute('data-id'), 10));
			if (!t) { return; }
			if (self.sel !== t.id) { self.sel = t.id; self.renderPanel(); }
			var resize = !!e.target.closest('.osb-ft__resize');
			var sc = self.scale();
			var start = { x: e.clientX, y: e.clientY, tx: t.x, ty: t.y, w: t.w, h: t.h };
			var moved = false;
			Array.prototype.forEach.call(self.plan.querySelectorAll('.osb-ft'), function (x) { x.classList.toggle('is-selected', x === el); });
			function move(ev) {
				var dx = (ev.clientX - start.x) / sc, dy = (ev.clientY - start.y) / sc;
				if (!moved && Math.abs(dx) < 2 && Math.abs(dy) < 2) { return; }
				moved = true;
				if (resize) {
					t.w = Math.max(30, Math.round((start.w + dx) / 10) * 10);
					t.h = t.shape === 'round' || t.shape === 'square' ? t.w : Math.max(30, Math.round((start.h + dy) / 10) * 10);
				} else {
					t.x = Math.max(0, Math.min(self.area.width - t.w, Math.round((start.tx + dx) / 10) * 10));
					t.y = Math.max(0, Math.min(self.area.height - t.h, Math.round((start.ty + dy) / 10) * 10));
				}
				el.style.left = (t.x / self.area.width * 100) + '%';
				el.style.top = (t.y / self.area.height * 100) + '%';
				el.style.width = (t.w / self.area.width * 100) + '%';
				el.style.height = (t.h / self.area.height * 100) + '%';
			}
			function up() {
				document.removeEventListener('pointermove', move);
				document.removeEventListener('pointerup', up);
				if (moved) { self.mark(); }
			}
			document.addEventListener('pointermove', move);
			document.addEventListener('pointerup', up);
			e.preventDefault();
			self.plan.focus({ preventScroll: true });
		});
		this.root.addEventListener('keydown', function (e) {
			if (!e.target.classList.contains('osb-fe__plan')) { return; }
			var t = self.table(self.sel);
			if (!t) { return; }
			var step = e.shiftKey ? 10 : 1;
			var map = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
			if (map[e.key]) {
				e.preventDefault();
				t.x = Math.max(0, Math.min(self.area.width - t.w, t.x + map[e.key][0]));
				t.y = Math.max(0, Math.min(self.area.height - t.h, t.y + map[e.key][1]));
				self.mark();
				self.layout();
			}
			if (e.key === 'Delete' || e.key === 'Backspace') {
				e.preventDefault();
				self.tables = self.tables.filter(function (x) { return x !== t; });
				self.sel = null;
				self.mark();
				self.layout();
				self.renderPanel();
			}
		});
	};

	/* ---------- boot ---------- */

	function boot() {
		var app = document.getElementById('osb-rest-app');
		if (app && !app.osb) { app.osb = new Service(app); }
		var ed = document.getElementById('osb-floor-editor');
		if (ed && !ed.osb) { ed.osb = new Editor(ed); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
