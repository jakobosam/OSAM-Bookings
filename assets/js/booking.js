/* OSAM Bookings — front-end booking form (no dependencies) */
(function () {
	'use strict';

	var G = window.OSB_BOOKING || {};
	var L = G.labels || {};
	var T = G.i18n || {};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function money(n) {
		var c = G.currency || { symbol: '£', position: 'before' };
		var num = Number(n || 0).toLocaleString(G.locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
		return c.position === 'after' ? num + ' ' + c.symbol.trim() : c.symbol + num;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

	function prettyDate(s) {
		var d = new Date(s + 'T12:00:00');
		try {
			return d.toLocaleDateString(G.locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
		} catch (e) {
			return s;
		}
	}

	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
		if (opts.body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		var url = G.rest + path;
		if (opts.query) {
			var q = Object.keys(opts.query).filter(function (k) { return opts.query[k] !== '' && opts.query[k] != null; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]); }).join('&');
			if (q) { url += (url.indexOf('?') > -1 ? '&' : '?') + q; }
		}
		return fetch(url, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) {
					var err = new Error((data && data.message) || T.error);
					err.data = data;
					throw err;
				}
				return data;
			});
		});
	}

	function Booking(root) {
		this.root = root;
		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { /* ignore */ }
		this.cfg = cfg;
		this.s = {
			mode: ['events', 'courses', 'tables', 'resources', 'bundles'].indexOf(cfg.mode) > -1 ? cfg.mode : 'services',
		bundles: null,
		bundle: null,
		addons: {},
		repeatOn: false,
		repeat: { freq: '', count: 4 },
		repeatPreview: null,
		pass: null,
			rlist: null,
			rmulti: false,
			rsel: [],
			hire: 'time',
			minutes: 0,
			rdays: 1,
			places: 1,
			excl: false,
			ropt: null,
			tcfg: null,
			covers: 2,
			area: 0,
			isRequest: false,
			step: 'service',
			services: null,
			events: null,
			courses: null,
			course: null,
			category: '',
			service: null,
			staffList: null,
			staff: null, // object or null (any)
			month: null,
			dates: [],
			bounds: null,
			date: null,
			slots: null,
			time: null,
			event: null,
			seats: 1,
			guests: [],
			guestsSame: false,
			values: {},
			method: null,
			terms: false,
			error: '',
			busy: false,
			result: null,
			outcome: ''
		};
		this.bind();
		if (cfg.result && cfg.result.token) {
			this.loadResult(cfg.result.token, cfg.result.outcome);
		} else {
			this.start();
		}
	}

	Booking.prototype.start = function () {
		var self = this;
		var s = this.s;
		if (this.cfg.waitlist && !s.wl) {
			// Arrived from a "a place has opened up" email: load the offer first.
			this.loading();
			api('/waitlist/' + encodeURIComponent(this.cfg.waitlist)).then(function (w) {
				s.wl = w;
				self.start();
			}).catch(function () { s.wl = { live: false, status: 'invalid' }; self.start(); });
			return;
		}
		var jobs = [];
		var mode = this.cfg.mode || 'all';
		var on = G.features || { events: true, courses: true };
		if (s.wl && s.wl.live) { mode = s.wl.type === 'course' ? 'courses' : 'events'; }
		if (mode === 'all' || mode === 'services') {
			jobs.push(api('/services', { query: { ids: (this.cfg.services || []).join(','), category: this.cfg.category } }).then(function (d) { s.services = d; }));
		}
		if ((mode === 'all' && on.events) || mode === 'events') {
			jobs.push(api('/events', { query: { ids: (this.cfg.events || []).join(','), waitlist: this.cfg.waitlist || '' } }).then(function (d) { s.events = d; }));
		}
		if ((mode === 'all' && on.courses) || mode === 'courses') {
			jobs.push(api('/courses', { query: { ids: (this.cfg.courses || []).join(','), waitlist: this.cfg.waitlist || '' } }).then(function (d) { s.courses = d; }));
		}
		if (((mode === 'all' && on.resources) || mode === 'resources') && !(s.wl && s.wl.live)) {
			jobs.push(api('/resources', { query: { ids: (this.cfg.resources || []).join(','), category: this.cfg.category } }).then(function (d) {
				s.rlist = d.resources; s.rmulti = !!d.multi;
			}).catch(function (e) { if (mode === 'resources') { throw e; } }));
		}
		if (((mode === 'all' && G.bundles) || mode === 'bundles') && !(s.wl && s.wl.live)) {
			jobs.push(api('/bundles', { query: { ids: (this.cfg.bundles || []).join(',') } }).then(function (d) { s.bundles = d; }).catch(function (e) { if (mode === 'bundles') { throw e; } }));
		}
		if (((mode === 'all' && on.tables) || mode === 'tables') && !(s.wl && s.wl.live)) {
			jobs.push(api('/tables/config').then(function (d) {
				s.tcfg = d;
				s.covers = Math.min(d.max, Math.max(d.min, self.cfg.covers || 2));
			}).catch(function (e) { if (mode === 'tables') { throw e; } }));
		}
		this.loading();
		Promise.all(jobs).then(function () {
			// In "all" mode, fall back to whichever list has content.
			if (self.cfg.mode === 'all' && (!s.services || !s.services.length)) {
				if (s.events && s.events.length) { s.mode = 'events'; } else if (s.courses && s.courses.length) { s.mode = 'courses'; } else if (s.rlist && s.rlist.length) { s.mode = 'resources'; } else if (s.tcfg) { s.mode = 'tables'; } else if (s.bundles && s.bundles.length) { s.mode = 'bundles'; }
			}
			if (s.wl) {
				if (s.wl.live) {
					s.mode = s.wl.type === 'course' ? 'courses' : 'events';
					s.values.name = s.wl.name; s.values.email = s.wl.email; s.values.phone = s.wl.phone;
					self.reset();
					if (s.wl.type === 'course') { self.pickCourse(s.wl.id); } else { self.pickEvent(s.wl.id); }
					if (s.step === 'details') {
						s.seats = Math.min(self.maxSeats(), s.wl.seats || 1);
						s.notice = (T.wlHeld || '').replace('%s', s.wl.expires);
						return self.render();
					}
				}
				s.wlExpired = !s.wl.live;
			}
			self.reset();
		}).catch(function (e) { self.fatal(e.message); });
	};

	Booking.prototype.reset = function () {
		var s = this.s;
		s.service = s.event = s.course = s.staff = s.staffList = null;
		s.date = s.time = s.slots = null;
		s.seats = 1;
		s.guests = [];
		s.guestsSame = false;
		s.method = null;
		s.error = '';
		s.result = null;
		s.addons = {};
		s.repeatOn = false;
		s.repeatPreview = null;
		s.pass = null;
		s.passOpen = false;
		s.passMsg = '';
		s.bundle = null;
		if (s.mode === 'bundles') {
			s.step = 'bundle';
			s.service = s.event = s.course = null;
			s.coupon = null;
			if (s.bundles && s.bundles.length === 1 && this.cfg.bundles && this.cfg.bundles.length === 1) { return this.pickBundle(s.bundles[0].id); }
			return this.render();
		}
		if (s.mode === 'resources') {
			s.step = 'resource';
			s.service = s.event = s.course = null;
			s.rsel = []; s.ropt = null; s.excl = false; s.places = 1; s.hire = 'time';
			s.coupon = null; s.method = null;
			if (s.rlist && s.rlist.length === 1) { s.rsel = [s.rlist[0].id]; return this.enterRes(); }
			return this.render();
		}
		if (s.mode === 'tables') {
			s.step = 'table';
			s.service = s.event = s.course = null;
			s.month = null;
			s.autoJumped = 0;
			s.coupon = null;
			s.method = null;
			return this.enterTables();
		}
		if (s.mode === 'events') {
			s.step = 'event';
			if (this.cfg.events && this.cfg.events.length === 1 && s.events && s.events.length === 1) {
				return this.pickEvent(s.events[0].id);
			}
		} else if (s.mode === 'courses') {
			s.step = 'course';
			// One course with a single open intake: go straight to the details.
			if (this.singleCourse()) {
				return this.pickCourse(s.courses[0].id);
			}
		} else {
			s.step = 'service';
			if (this.cfg.services && this.cfg.services.length === 1 && s.services && s.services.length === 1) {
				return this.pickService(s.services[0].id);
			}
		}
		this.render();
	};

	Booking.prototype.loading = function () {
		this.root.innerHTML = '<div class="osb-loading">' + esc(T.loading) + '</div>';
	};

	Booking.prototype.fatal = function (msg) {
		this.root.innerHTML = '<div class="osb-alert osb-alert--error">' + esc(msg || T.error) + '</div>';
	};

	/* ---------- flow ---------- */

	Booking.prototype.steps = function () {
		var s = this.s;
		if (s.resched) { return ['datetime', 'reschedule']; }
		if (s.mode === 'bundles') { return this.cfg.bundles && this.cfg.bundles.length === 1 ? ['details'] : ['bundle', 'details']; }
		if (s.mode === 'tables') { return ['table', 'details']; }
		if (s.mode === 'resources') { return s.rlist && s.rlist.length === 1 ? ['res_when', 'details'] : ['resource', 'res_when', 'details']; }
		if (s.mode === 'events') { return ['event', 'details']; }
		if (s.mode === 'courses') { return this.singleCourse() ? ['details'] : ['course', 'details']; }
		var steps = [];
		if (!(this.cfg.services && this.cfg.services.length === 1)) { steps.push('service'); }
		if (this.needsStaffStep()) { steps.push('staff'); }
		steps.push('datetime', 'details');
		return steps;
	};

	Booking.prototype.needsStaffStep = function () {
		var s = this.s;
		if (this.cfg.staff) { return false; }
		if (!G.showStaff) { return false; }
		if (s.service && s.service.staff.length < 2) { return false; }
		return true;
	};

	Booking.prototype.go = function (step) {
		this.s.step = step;
		this.s.error = '';
		this.render(true);
	};

	Booking.prototype.back = function () {
		var steps = this.steps();
		var i = steps.indexOf(this.s.step);
		if (i > 0) { this.go(steps[i - 1]); }
	};

	Booking.prototype.pickService = function (id) {
		var self = this;
		var s = this.s;
		s.service = (s.services || []).filter(function (x) { return x.id === id; })[0] || null;
		if (!s.service) { return; }
		s.staff = null;
		s.date = s.time = s.slots = null;
		s.month = null;
		s.autoJumped = 0;
		s.seats = 1;
		s.method = s.service.methods.length === 1 ? s.service.methods[0].id : null;
		this.loading();
		api('/staff', { query: { service_id: id } }).then(function (list) {
			s.staffList = list;
			if (self.cfg.staff) {
				s.staff = list.filter(function (x) { return x.id === self.cfg.staff; })[0] || null;
			} else if (list.length === 1) {
				s.staff = list[0];
			}
			if (self.needsStaffStep()) {
				self.go('staff');
			} else {
				self.enterDatetime();
			}
		}).catch(function (e) { self.fatal(e.message); });
	};

	Booking.prototype.pickStaff = function (id) {
		var s = this.s;
		s.staff = id ? (s.staffList.filter(function (x) { return x.id === id; })[0] || null) : null;
		s.date = s.time = s.slots = null;
		this.enterDatetime();
	};

	Booking.prototype.staffId = function () {
		return this.s.staff ? this.s.staff.id : (this.cfg.staff || 0);
	};

	Booking.prototype.enterDatetime = function () {
		var s = this.s;
		if (!s.month) {
			var now = new Date();
			s.month = now.getFullYear() + '-' + pad(now.getMonth() + 1);
		}
		this.go('datetime');
		this.loadMonth();
	};

	Booking.prototype.loadMonth = function () {
		var self = this;
		var s = this.s;
		s.dates = null;
		this.render();
		var mpath = s.resched ? '/bookings/' + encodeURIComponent(s.token) + '/month' : '/availability/month';
		var mquery = { service_id: s.service ? s.service.id : 0, staff_id: this.staffId(), month: s.month };
		if (s.mode === 'tables' && !s.resched) {
			mpath = '/tables/month';
			mquery = { covers: s.covers, area_id: s.area || 0, month: s.month };
		}
		if (s.mode === 'resources' && !s.resched) {
			mpath = '/resources/month';
			mquery = this.resQuery();
			mquery.month = s.month;
		}
		var sig = function () { return s.month + '|' + s.covers + '|' + s.area + '|' + JSON.stringify(self.resQuery()); };
		var asked = sig();
		api(mpath, { query: mquery }).then(function (d) {
			if ((s.mode === 'tables' || s.mode === 'resources') && asked !== sig()) { return; }
			s.dates = d.dates;
			s.bounds = { min: d.min, max: d.max };
			// Jump forward automatically if this month is empty but later ones are bookable.
			if (!d.dates.length && (s.autoJumped || 0) < 3 && s.month < d.max.slice(0, 7)) {
				s.autoJumped = (s.autoJumped || 0) + 1;
				return self.shiftMonth(1);
			}
			if (s.date && d.dates.indexOf(s.date) === -1) { s.date = null; s.slots = null; s.time = null; }
			if (!s.date && d.dates.length) { return self.pickDate(d.dates[0]); }
			self.render();
		}).catch(function (e) { s.error = e.message; self.render(); });
	};

	Booking.prototype.shiftMonth = function (delta) {
		var parts = this.s.month.split('-');
		var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1 + delta, 1);
		this.s.month = d.getFullYear() + '-' + pad(d.getMonth() + 1);
		this.loadMonth();
	};

	Booking.prototype.pickDate = function (date) {
		var self = this;
		var s = this.s;
		s.date = date;
		s.time = null;
		s.slots = null;
		this.render();
		if (s.mode === 'resources' && !s.resched) {
			var rq = this.resQuery();
			rq.date = date;
			var rkey = JSON.stringify(rq);
			api('/resources/day', { query: rq }).then(function (d) {
				var now = self.resQuery(); now.date = s.date;
				if (JSON.stringify(now) !== rkey) { return; }
				s.slots = d;
				self.render();
			}).catch(function (e) { s.error = e.message; self.render(); });
			return;
		}
		if (s.mode === 'tables' && !s.resched) {
			var covers = s.covers;
			api('/tables/day', { query: { covers: covers, area_id: s.area || 0, date: date } }).then(function (d) {
				if (s.date !== date || s.covers !== covers) { return; }
				s.slots = d.slots;
				s.isRequest = !!d.request;
				self.render();
			}).catch(function (e) { s.error = e.message; self.render(); });
			return;
		}
		var dpath = s.resched ? '/bookings/' + encodeURIComponent(s.token) + '/day' : '/availability/day';
		api(dpath, { query: { service_id: s.service.id, staff_id: this.staffId(), date: date, seats: s.seats } }).then(function (slots) {
			if (s.date !== date) { return; }
			s.slots = slots;
			self.render();
		}).catch(function (e) { s.error = e.message; self.render(); });
	};

	Booking.prototype.startReschedule = function () {
		var s = this.s;
		var d = s.result || {};
		s.resched = { from: d };
		s.service = { id: d.service_id, name: d.item_name, capacity: 1, staff: [d.staff_id] };
		s.staff = d.staff_name ? { id: d.staff_id, name: d.staff_name } : null;
		s.seats = d.seats || 1;
		s.date = s.time = s.slots = null;
		s.month = null;
		s.autoJumped = 0;
		s.error = '';
		this.enterDatetime();
	};

	Booking.prototype.confirmReschedule = function () {
		var self = this;
		var s = this.s;
		s.busy = true;
		s.error = '';
		this.render();
		api('/bookings/' + encodeURIComponent(s.token) + '/reschedule', { method: 'POST', body: { date: s.date, time: s.time } }).then(function (d) {
			s.busy = false;
			s.resched = null;
			s.result = d;
			s.outcome = 'rescheduled';
			self.go('done');
		}).catch(function (e) {
			s.busy = false;
			s.error = e.message;
			s.time = null;
			s.step = 'datetime';
			self.pickDate(s.date);
		});
	};

	Booking.prototype.pickTime = function (time) {
		if (this.s.resched) {
			this.s.time = time;
			return this.go('reschedule');
		}
		this.s.time = time;
		if (this.s.mode === 'tables') {
			var m = this.methods();
			this.s.method = m.length === 1 ? m[0].id : null;
			this.s.seats = 1;
		}
		this.go('details');
	};

	Booking.prototype.pickEvent = function (id) {
		var s = this.s;
		s.event = (s.events || []).filter(function (x) { return x.id === id; })[0] || null;
		if (!s.event || s.event.seats_left < 1) { return; }
		s.seats = 1;
		s.method = s.event.methods.length === 1 ? s.event.methods[0].id : null;
		this.go('details');
	};

	Booking.prototype.singleCourse = function () {
		var s = this.s;
		return !!(this.cfg.courses && this.cfg.courses.length === 1 && s.courses && s.courses.length === 1 && s.courses[0].seats_left > 0);
	};

	Booking.prototype.pickCourse = function (id) {
		var s = this.s;
		s.course = (s.courses || []).filter(function (x) { return x.id === id; })[0] || null;
		if (!s.course || s.course.seats_left < 1) { return; }
		s.seats = 1;
		s.method = s.course.methods.length === 1 ? s.course.methods[0].id : null;
		this.go('details');
	};

	/* ---------- resources ---------- */

	Booking.prototype.rChosen = function () {
		var s = this.s;
		return (s.rlist || []).filter(function (r) { return s.rsel.indexOf(r.id) > -1; });
	};

	// Kinds of hire every resource in the list offers (by time only when they share a time grid).
	function commonHires(list) {
		var hires = ['time', 'half', 'day'];
		list.forEach(function (r) { hires = hires.filter(function (h) { return r.hires.indexOf(h) > -1; }); });
		if (hires.indexOf('time') > -1 && list.some(function (r) { return r.mode + r.grid !== list[0].mode + list[0].grid; })) {
			hires = hires.filter(function (h) { return h !== 'time'; });
		}
		return hires;
	}

	Booking.prototype.resRules = function () {
		var list = this.rChosen();
		var durs = null;
		list.forEach(function (r) { durs = durs === null ? r.durations.slice() : durs.filter(function (m) { return r.durations.indexOf(m) > -1; }); });
		return {
			list: list,
			hires: commonHires(list),
			durations: durs || [],
			shared: list.length > 0 && list.every(function (r) { return r.shared; }),
			exclusive: list.length > 0 && list.every(function (r) { return r.exclusive; }),
			max: list.reduce(function (a, r) { return Math.min(a, r.max); }, 9999),
			maxDays: list.reduce(function (a, r) { return Math.min(a, r.max_days); }, 30)
		};
	};

	Booking.prototype.resQuery = function () {
		var s = this.s;
		return { ids: s.rsel.join(','), hire: s.hire, minutes: s.hire === 'time' ? s.minutes : '', days: s.hire === 'day' ? s.rdays : '', places: s.excl ? 1 : s.places, exclusive: s.excl ? 1 : '' };
	};

	Booking.prototype.enterRes = function () {
		var s = this.s;
		var R = this.resRules();
		if (!R.list.length) { return; }
		if (R.hires.indexOf(s.hire) === -1) { s.hire = R.hires[0] || 'time'; }
		if (R.durations.indexOf(s.minutes) === -1) { s.minutes = R.durations[0] || 60; }
		if (!R.shared) { s.excl = true; } else if (!R.exclusive) { s.excl = false; }
		s.places = Math.max(1, Math.min(s.places, R.max));
		s.rdays = Math.max(1, Math.min(s.rdays, R.maxDays));
		s.date = s.time = s.slots = null; s.ropt = null; s.dates = null;
		if (!s.month) { var now = new Date(); s.month = now.getFullYear() + '-' + pad(now.getMonth() + 1); }
		s.autoJumped = 0;
		s.step = 'res_when';
		s.error = '';
		this.render(true);
		this.loadMonth();
	};

	Booking.prototype.toggleRes = function (id) {
		var s = this.s;
		var i = s.rsel.indexOf(id);
		if (!s.rmulti) { s.rsel = [id]; return this.enterRes(); }
		if (i > -1) { s.rsel.splice(i, 1); } else { s.rsel.push(id); }
		this.render();
	};

	Booking.prototype.resChanged = function () {
		var s = this.s;
		s.date = s.time = s.slots = null; s.ropt = null; s.dates = null; s.autoJumped = 0;
		this.render();
		this.loadMonth();
	};

	Booking.prototype.view_resource = function () {
		var self = this, s = this.s;
		var list = s.rlist || [];
		var html = '<h3 class="osb-title">' + esc(L.step_resource) + '</h3>';
		if (!list.length) { return html + '<p class="osb-empty">' + esc(L.no_resources) + '</p>'; }
		var cats = [];
		list.forEach(function (x) { if (x.category && cats.indexOf(x.category) === -1) { cats.push(x.category); } });
		if (cats.length > 1 && !this.cfg.category) {
			html += '<div class="osb-chips"><button type="button" class="osb-chip' + (!s.category ? ' is-active' : '') + '" data-osb="category" data-value="">' + esc(T.all) + '</button>' +
				cats.map(function (c) { return '<button type="button" class="osb-chip' + (s.category === c ? ' is-active' : '') + '" data-osb="category" data-value="' + esc(c) + '">' + esc(c) + '</button>'; }).join('') + '</div>';
		}
		if (s.rmulti) { html += '<p class="osb-subtitle">' + esc(T.resPickHint) + '</p>'; }
		var chosen = this.rChosen();
		html += '<div class="osb-cards osb-cards--res">';
		list.filter(function (x) { return !s.category || x.category === s.category || s.rsel.indexOf(x.id) > -1; }).forEach(function (x) {
			var on = s.rsel.indexOf(x.id) > -1;
			var ok = on || !chosen.length || commonHires(chosen.concat([x])).length > 0;
			var meta = [];
			if (x.shared) { meta.push((T.resUpTo || '%d').replace('%d', x.capacity)); } else { meta.push(T.resSoleUse); }
			if (x.location) { meta.push(x.location); }
			html += '<button type="button" class="osb-card' + (on ? ' is-selected' : '') + (ok ? '' : ' is-disabled') + '" data-osb="res" data-value="' + x.id + '"' + (ok ? '' : ' disabled') + ' aria-pressed="' + on + '" style="--osb-item:' + esc(x.color) + '">' +
				(s.rmulti ? '<span class="osb-card__check" aria-hidden="true">' + (on ? '✓' : '') + '</span>' : '') +
				(x.image ? '<img class="osb-card__img" src="' + esc(x.image) + '" alt="" loading="lazy">' : '<span class="osb-card__dot"></span>') +
				'<span class="osb-card__body"><span class="osb-card__name">' + esc(x.name) + '</span>' +
				'<span class="osb-card__meta">' + esc(meta.join(' · ')) + '</span>' +
				(x.description ? '<span class="osb-card__desc">' + x.description + '</span>' : '') +
				'</span><span class="osb-card__price">' + esc(x.price_label) + '</span></button>';
		});
		html += '</div>';
		if (s.rmulti) {
			html += '<div class="osb-actions"><button type="button" class="osb-btn osb-btn--primary" data-osb="res-next"' + (s.rsel.length ? '' : ' disabled') + '>' + esc(L.next) + (s.rsel.length > 1 ? ' (' + s.rsel.length + ')' : '') + '</button></div>';
		}
		return html;
	};

	function durLabel(m) {
		if (m < 60) { return m + ' ' + (T.minutes || 'min'); }
		var h = Math.floor(m / 60), r = m % 60;
		return r ? h + 'h ' + r + 'm' : h + ' ' + (h === 1 ? T.hour : T.hours);
	}

	Booking.prototype.view_res_when = function () {
		var s = this.s;
		var R = this.resRules();
		var id = this.root.id;
		var html = '<h3 class="osb-title">' + esc(L.step_res_when) + '</h3><p class="osb-subtitle">' + esc(R.list.map(function (r) { return r.name; }).join(' + ')) + '</p>';
		var labels = { time: L.res_time, half: L.res_half, day: L.res_day };
		if (R.hires.length > 1) {
			html += '<div class="osb-party"><span class="osb-party__label">' + esc(T.resHow) + '</span><div class="osb-chips">' + R.hires.map(function (h) {
				return '<button type="button" class="osb-chip' + (s.hire === h ? ' is-active' : '') + '" data-osb="hire" data-value="' + h + '">' + esc(labels[h]) + '</button>';
			}).join('') + '</div></div>';
		}
		if (R.shared && R.exclusive) {
			html += '<div class="osb-party"><span class="osb-party__label">' + esc(T.resUse) + '</span><div class="osb-chips">' +
				'<button type="button" class="osb-chip' + (!s.excl ? ' is-active' : '') + '" data-osb="excl" data-value="0">' + esc(L.res_share) + '</button>' +
				'<button type="button" class="osb-chip' + (s.excl ? ' is-active' : '') + '" data-osb="excl" data-value="1">' + esc(L.res_book_out) + '</button></div></div>';
		}
		if (!s.excl && R.shared && R.max > 1) {
			html += '<div class="osb-party"><label class="osb-party__label" for="' + id + '-pl">' + esc(L.res_places) + '</label><input class="osb-party__num" id="' + id + '-pl" type="number" min="1" max="' + R.max + '" name="osb_places" value="' + s.places + '"></div>';
		}
		if (s.hire === 'time' && R.durations.length > 1) {
			html += '<div class="osb-party"><label class="osb-party__label" for="' + id + '-len">' + esc(L.res_length) + '</label><select id="' + id + '-len" name="osb_minutes">' +
				R.durations.map(function (m) {
					// Fixed lengths have their own prices: show them so people can compare.
					var price = 0, known = true;
					R.list.forEach(function (r) {
						var o = (r.slot_options || []).filter(function (x) { return x.minutes === m; })[0];
						if (!o) { known = false; return; }
						price += s.excl ? o.exclusive : o.place * Math.max(1, s.places);
					});
					var tag = known && R.list[0].slot_options && R.list[0].slot_options.length ? (price > 0 ? ' — ' + money(price) : ' — ' + L.free) : '';
					return '<option value="' + m + '"' + (s.minutes === m ? ' selected' : '') + '>' + esc(durLabel(m) + tag) + '</option>';
				}).join('') + '</select></div>';
		}
		if (s.hire === 'day' && R.maxDays > 1) {
			var o = '';
			for (var d = 1; d <= R.maxDays; d++) { o += '<option value="' + d + '"' + (s.rdays === d ? ' selected' : '') + '>' + d + '</option>'; }
			html += '<div class="osb-party"><label class="osb-party__label" for="' + id + '-days">' + esc(L.res_days) + '</label><select id="' + id + '-days" name="osb_rdays">' + o + '</select></div>';
		}
		html += '<div class="osb-datetime"><div class="osb-calendar">' + this.renderCalendar() + '</div><div class="osb-slots" aria-live="polite">';
		if (!s.date) {
			html += s.dates === null ? '<div class="osb-loading">' + esc(T.loading) + '</div>' : '<p class="osb-muted">' + esc(s.dates.length ? T.pickDate : L.res_none_free) + '</p>';
		} else {
			html += '<p class="osb-slots__date">' + esc(prettyDate(s.date)) + '</p>';
			if (s.slots === null) {
				html += '<div class="osb-loading">' + esc(T.loading) + '</div>';
			} else if (!s.slots.length) {
				html += '<p class="osb-muted">' + esc(L.res_none_free) + '</p>';
			} else {
				var showLeft = !s.excl && R.shared;
				var samePrice = s.slots.every(function (x) { return x.amount === s.slots[0].amount; });
				html += '<div class="osb-slots__grid ' + (s.hire === 'time' ? 'osb-slots__grid--res' : 'osb-slots__grid--wide') + '">' + s.slots.map(function (x, i) {
					var main = s.hire === 'half' ? x.label : (s.hire === 'time' ? x.label : x.range);
					var sub = s.hire === 'half' ? x.range : (s.hire === 'time' ? (L.res_until || 'until %s').replace('%s', x.end_label) : '');
					var left = showLeft && s.hire === 'time' ? (L.seats_left || '%d').replace('%d', x.left) : '';
					return '<button type="button" class="osb-slot osb-slot--res" data-osb="ropt" data-value="' + i + '">' + esc(main) +
						(sub ? '<small>' + esc(sub) + '</small>' : '') + (left ? '<small class="osb-seats-left">' + esc(left) + '</small>' : '') +
						(x.amount > 0 && !samePrice ? '<small class="osb-slot__price">' + esc(money(x.amount)) + '</small>' : '') + '</button>';
				}).join('') + '</div>';
			}
		}
		html += '</div></div><div class="osb-actions">' + this.backBtn() + '</div>';
		return html;
	};

	Booking.prototype.pickResOpt = function (i) {
		var s = this.s;
		var x = (s.slots || [])[i];
		if (!x) { return; }
		s.ropt = x;
		s.time = x.time;
		var m = this.methods();
		s.method = m.length === 1 ? m[0].id : null;
		s.seats = 1;
		this.go('details');
	};

	/* ---------- tables ---------- */

	Booking.prototype.enterTables = function () {
		var s = this.s;
		s.date = s.time = s.slots = null;
		s.dates = null;
		if (!s.month) {
			var now = new Date();
			s.month = now.getFullYear() + '-' + pad(now.getMonth() + 1);
		}
		s.step = 'table';
		s.error = '';
		if (this.tooBig()) { return this.render(); }
		this.render();
		this.loadMonth();
	};

	// "Please call us" territory: more guests than can be booked or requested online.
	Booking.prototype.tooBig = function () {
		var c = this.s.tcfg;
		return !!c && this.s.covers > Math.max(c.max, c.request_max || 0);
	};

	Booking.prototype.setCovers = function (n) {
		var s = this.s;
		var c = s.tcfg || { min: 1, max: 8 };
		var top = c.request_max || c.max;
		if (c.large_mode === 'call') { top = c.max + 1; }
		s.covers = Math.max(c.min, Math.min(top, n));
		s.autoJumped = 0;
		this.enterTables();
	};

	Booking.prototype.tablePayment = function () {
		var s = this.s;
		var p = s.tcfg && s.tcfg.payment ? s.tcfg.payment : { mode: 'none' };
		if (p.mode === 'none' || s.isRequest || s.covers < (p.min_party || 1)) { return { mode: 'none', amount: 0, fee: 0 }; }
		if (p.mode === 'deposit') { return { mode: 'deposit', amount: Math.round((p.per === 'booking' ? p.deposit : p.deposit * s.covers) * 100) / 100, fee: 0 }; }
		return { mode: 'card_hold', amount: 0, fee: Math.round(p.fee * s.covers * 100) / 100 };
	};

	Booking.prototype.view_table = function () {
		var s = this.s;
		var c = s.tcfg || { min: 1, max: 8, areas: [] };
		var id = this.root.id;
		var html = '<h3 class="osb-title">' + esc(L.step_table) + '</h3>';
		var top = c.large_mode === 'call' ? c.max + 1 : Math.max(c.max, c.request_max || 0);
		var quick = [];
		for (var n = c.min; n <= Math.min(c.max, c.min + 7); n++) { quick.push(n); }
		html += '<div class="osb-party"><span class="osb-party__label" id="' + id + '-cv">' + esc(L.covers) + '</span><div class="osb-chips" role="group" aria-labelledby="' + id + '-cv">' +
			quick.map(function (v) { return '<button type="button" class="osb-chip' + (s.covers === v ? ' is-active' : '') + '" data-osb="covers" data-value="' + v + '" aria-pressed="' + (s.covers === v) + '">' + v + '</button>'; }).join('');
		if (top > quick[quick.length - 1]) {
			var more = '';
			for (var m = quick[quick.length - 1] + 1; m <= top; m++) {
				var lab = (c.large_mode === 'call' && m > c.max) ? (c.max + '+') : String(m);
				more += '<option value="' + m + '"' + (s.covers === m ? ' selected' : '') + '>' + esc(lab) + '</option>';
			}
			var bigSel = s.covers > quick[quick.length - 1];
			html += '<label class="osb-party__more' + (bigSel ? ' is-active' : '') + '"><span class="screen-reader-text">' + esc(T.moreGuests) + '</span><select name="osb_covers_more" aria-label="' + esc(T.moreGuests) + '"><option value="">' + esc(T.moreGuests) + '</option>' + more + '</select></label>';
		}
		html += '</div></div>';
		if (c.areas && c.areas.length > 1) {
			html += '<div class="osb-party"><span class="osb-party__label">' + esc(L.area) + '</span><div class="osb-chips">' +
				'<button type="button" class="osb-chip' + (!s.area ? ' is-active' : '') + '" data-osb="area" data-value="0">' + esc(L.area_any) + '</button>' +
				c.areas.map(function (a) { return '<button type="button" class="osb-chip' + (s.area === a.id ? ' is-active' : '') + '" data-osb="area" data-value="' + a.id + '">' + esc(a.name) + '</button>'; }).join('') + '</div></div>';
		}
		if (this.tooBig()) {
			var msg = (L.large_party || '').replace('%d', c.request_max || c.max);
			return html + '<div class="osb-alert osb-alert--info" role="status">' + esc(msg) + (c.phone ? ' <a href="tel:' + esc(c.phone.replace(/[^\d+]/g, '')) + '"><strong>' + esc(c.phone) + '</strong></a>' : '') + '</div>';
		}
		html += '<div class="osb-datetime"><div class="osb-calendar">' + this.renderCalendar() + '</div><div class="osb-slots" aria-live="polite">';
		if (!s.date) {
			html += s.dates === null ? '<div class="osb-loading">' + esc(T.loading) + '</div>' : '<p class="osb-muted">' + esc(s.dates.length ? T.pickDate : L.no_tables) + (s.dates.length || !c.phone ? '' : ' ' + esc((T.callUs || '%s').replace('%s', c.phone))) + '</p>';
		} else {
			html += '<p class="osb-slots__date">' + esc(prettyDate(s.date)) + '</p>';
			if (s.slots === null) {
				html += '<div class="osb-loading">' + esc(T.loading) + '</div>';
			} else if (!s.slots.length) {
				html += '<p class="osb-muted">' + esc(L.no_tables) + '</p>';
			} else {
				var groups = [];
				s.slots.forEach(function (x) {
					var g = groups.length ? groups[groups.length - 1] : null;
					if (!g || g.id !== x.period_id) { g = { id: x.period_id, name: x.period, list: [] }; groups.push(g); }
					g.list.push(x);
				});
				groups.forEach(function (g) {
					if (groups.length > 1) { html += '<p class="osb-slots__period">' + esc(g.name) + '</p>'; }
					html += '<div class="osb-slots__grid">' + g.list.map(function (x) {
						return '<button type="button" class="osb-slot' + (s.time === x.time ? ' is-selected' : '') + '" data-osb="time" data-value="' + esc(x.time) + '">' + esc(x.label) + '</button>';
					}).join('') + '</div>';
				});
				if (s.isRequest) { html += '<p class="osb-muted osb-slots__note">' + esc(L.request_note) + '</p>'; }
			}
		}
		html += '</div></div>';
		return html;
	};

	/* ---------- pricing ---------- */

	Booking.prototype.unitPrice = function () {
		var s = this.s;
		if (s.event) { return s.event.price; }
		if (s.course) { return s.course.price; }
		if (s.staff && typeof s.staff.price === 'number') { return s.staff.price; }
		return s.service ? s.service.price : 0;
	};

	// The booking itself, before extras (0 when a bundle pays for it).
	Booking.prototype.basePrice = function () {
		var s = this.s;
		if (s.mode === 'bundles') { return s.bundle ? s.bundle.price : 0; }
		if (s.pass) { return 0; }
		if (s.mode === 'tables') { return this.tablePayment().amount; }
		if (s.mode === 'resources') { return s.ropt ? s.ropt.amount : 0; }
		return Math.round(this.unitPrice() * s.seats * 100) / 100;
	};

	Booking.prototype.subtotal = function () {
		return Math.round((this.basePrice() + this.addonsTotal()) * 100) / 100;
	};

	// One date's total.
	Booking.prototype.dateTotal = function () {
		var off = this.s.coupon ? this.s.coupon.discount : 0;
		return Math.max(0, Math.round((this.subtotal() - off) * 100) / 100);
	};

	// Everything to pay now (all dates of a repeat booking).
	Booking.prototype.total = function () {
		var s = this.s;
		if (s.repeatOn && s.repeatPreview && s.repeatPreview.dates) { return s.repeatPreview.total; }
		return this.dateTotal();
	};

	/* ---------- add-ons ---------- */

	Booking.prototype.itemTokens = function () {
		var s = this.s;
		if (s.mode === 'bundles') { return ['bundles']; }
		if (s.mode === 'tables') { return ['tables']; }
		if (s.mode === 'resources') {
			var t = ['resources'];
			this.rChosen().forEach(function (r) { t.push('r:' + r.id); if (r.category) { t.push('rcat:' + r.category); } });
			return t;
		}
		if (s.event) { return ['events', 'e:' + s.event.id]; }
		if (s.course) { return ['courses', 'c:' + s.course.course_id]; }
		if (s.service) { var o = ['services', 's:' + s.service.id]; if (s.service.category) { o.push('cat:' + s.service.category); } return o; }
		return [];
	};

	Booking.prototype.addonList = function () {
		if (this.s.mode === 'bundles') { return []; }
		var tok = this.itemTokens();
		return (G.addons || []).filter(function (a) {
			return !a.applies.length || a.applies.some(function (x) { return tok.indexOf(x) > -1; });
		});
	};

	// Units an add-on is charged for (the server works out the final figure).
	Booking.prototype.addonFactor = function (a) {
		var s = this.s;
		if (a.per === 'person') { return s.mode === 'tables' ? s.covers : (s.mode === 'resources' ? (s.excl ? 1 : s.places) : s.seats); }
		if (a.per === 'hour') {
			var m = 60;
			if (s.mode === 'resources') { m = s.hire === 'time' ? s.minutes : (s.ropt && s.ropt.minutes ? s.ropt.minutes : 240); } else if (s.service) { m = s.service.duration; } else if (s.event && s.event.minutes) { m = s.event.minutes; }
			return Math.max(0.25, Math.round(m / 60 * 100) / 100);
		}
		if (a.per === 'day') { return s.mode === 'resources' && s.hire === 'day' ? s.rdays : 1; }
		return 1;
	};

	Booking.prototype.addonQty = function (a) {
		var q = this.s.addons[a.id] || 0;
		return a.required ? Math.max(1, q) : q;
	};

	Booking.prototype.addonsTotal = function () {
		var self = this;
		return Math.round(this.addonList().reduce(function (sum, a) { return sum + a.price * self.addonQty(a) * self.addonFactor(a); }, 0) * 100) / 100;
	};

	Booking.prototype.chosenAddons = function () {
		var self = this;
		var out = {};
		this.addonList().forEach(function (a) { var q = self.addonQty(a); if (q > 0) { out[a.id] = q; } });
		return out;
	};

	Booking.prototype.addonsHtml = function () {
		var self = this;
		var list = this.addonList();
		if (!list.length) { return ''; }
		var html = '<fieldset class="osb-addons"><legend>' + esc(T.extras) + '</legend><p class="osb-addons__hint">' + esc(T.extrasHint) + '</p>';
		list.forEach(function (a) {
			var q = self.addonQty(a);
			var line = a.price * self.addonFactor(a) * Math.max(1, q);
			var price = a.price > 0 ? money(line) : L.free;
			var desc = a.description ? String(a.description).replace(/<[^>]+>/g, '') : '';
			var body = (a.image ? '<img class="osb-addon__img" src="' + esc(a.image) + '" alt="" loading="lazy">' : '') +
				'<span class="osb-addon__body"><b>' + esc(a.name) + '</b>' + (desc ? '<small>' + esc(desc) + '</small>' : '') + '<small class="osb-addon__unit">' + esc(a.price_label) + '</small></span>';
			if (a.required) {
				html += '<div class="osb-addon is-selected">' + body + '<span class="osb-addon__price">' + esc(price) + '<small>' + esc(T.included) + '</small></span></div>';
			} else if (a.max > 1) {
				html += '<div class="osb-addon osb-addon--qty' + (q ? ' is-selected' : '') + '">' + body +
					'<span class="osb-addon__price">' + (q ? esc(price) : '') + '</span>' +
					'<span class="osb-qty"><button type="button" class="osb-qty__btn" data-osb="addon-dec" data-value="' + a.id + '" aria-label="−"' + (q < 1 ? ' disabled' : '') + '>−</button><span class="osb-qty__n" aria-live="polite">' + q + '</span><button type="button" class="osb-qty__btn" data-osb="addon-inc" data-value="' + a.id + '" aria-label="+"' + (q >= a.max ? ' disabled' : '') + '>+</button></span></div>';
			} else {
				html += '<label class="osb-addon' + (q ? ' is-selected' : '') + '"><input type="checkbox" name="osb_addon_' + a.id + '" value="1"' + (q ? ' checked' : '') + '>' + body +
					'<span class="osb-addon__price">' + esc(a.price > 0 ? money(line) : L.free) + '</span></label>';
			}
		});
		return html + '</fieldset>';
	};

	Booking.prototype.setAddon = function (id, qty) {
		var a = (G.addons || []).filter(function (x) { return x.id === id; })[0];
		if (!a) { return; }
		this.collect();
		this.s.addons[id] = Math.max(0, Math.min(a.max, qty));
		this.afterChange();
	};

	// Something that changes the price: coupons and repeat previews need fresh figures.
	Booking.prototype.afterChange = function () {
		var s = this.s;
		if (s.coupon) { return this.applyCoupon(s.coupon.code); }
		if (s.repeatOn) { return this.loadRepeat(); }
		this.render();
	};

	/* ---------- repeat ---------- */

	Booking.prototype.repeatType = function () {
		var s = this.s;
		return s.mode === 'tables' ? 'table' : (s.mode === 'resources' ? 'resource' : (s.mode === 'services' && s.service ? 'service' : ''));
	};

	Booking.prototype.canRepeat = function () {
		var R = G.repeat || {};
		var t = this.repeatType();
		return !!t && !this.s.resched && (R.types || []).indexOf(t) > -1 && Object.keys(R.freqs || {}).length > 0 && !this.s.isRequest;
	};

	Booking.prototype.repeatHtml = function () {
		var s = this.s;
		if (!this.canRepeat()) { return ''; }
		var R = G.repeat;
		var html = '<div class="osb-repeat' + (s.repeatOn ? ' is-on' : '') + '"><label class="osb-repeat__toggle"><input type="checkbox" name="osb_repeat_on" value="1"' + (s.repeatOn ? ' checked' : '') + '> <span>' + esc(T.repeatAsk) + '</span></label>';
		if (s.repeatOn) {
			var freqs = Object.keys(R.freqs);
			var counts = '';
			for (var n = 2; n <= R.max; n++) { counts += '<option value="' + n + '"' + (s.repeat.count === n ? ' selected' : '') + '>' + n + '</option>'; }
			html += '<div class="osb-repeat__opts"><div class="osb-field"><label for="' + this.root.id + '-rfreq">' + esc(T.repeatHow) + '</label><select id="' + this.root.id + '-rfreq" name="osb_repeat_freq">' +
				freqs.map(function (k) { return '<option value="' + k + '"' + (s.repeat.freq === k ? ' selected' : '') + '>' + esc(R.freqs[k]) + '</option>'; }).join('') + '</select></div>' +
				'<div class="osb-field"><label for="' + this.root.id + '-rcount">' + esc(T.repeatTimes) + '</label><select id="' + this.root.id + '-rcount" name="osb_repeat_count">' + counts + '</select></div></div>';
			var p = s.repeatPreview;
			if (!p || p.busy) {
				html += '<p class="osb-loading osb-repeat__wait">' + esc(T.repeatChecking) + '</p>';
			} else if (p.dates) {
				html += '<p class="osb-repeat__sum">' + esc((T.repeatSummary || '%1$d / %2$d').replace('%1$d', p.ok).replace('%2$d', p.dates.length)) + '</p><ul class="osb-repeat__dates">' + p.dates.map(function (d) {
					return '<li class="' + (d.ok ? 'is-ok' : 'is-taken') + '"><span>' + esc(d.label) + '</span><small>' + esc(d.ok ? (d.amount > 0 ? money(d.amount) : T.repeatFree) : T.repeatTaken) + '</small></li>';
				}).join('') + '</ul>';
			}
		}
		return html + '</div>';
	};

	Booking.prototype.loadRepeat = function () {
		var self = this;
		var s = this.s;
		if (!s.repeatOn || !this.canRepeat()) { s.repeatPreview = null; return this.render(); }
		var R = G.repeat;
		var freqs = Object.keys(R.freqs || {});
		if (freqs.indexOf(s.repeat.freq) === -1) { s.repeat.freq = freqs[0]; }
		s.repeat.count = Math.min(R.max, Math.max(2, s.repeat.count || 4));
		var body = this.bookingBody();
		var sig = JSON.stringify(body);
		s.repeatPreview = { busy: true, sig: sig };
		this.render();
		api('/repeat/preview', { method: 'POST', body: body }).then(function (d) {
			if (!s.repeatPreview || s.repeatPreview.sig !== sig) { return; }
			d.sig = sig;
			s.repeatPreview = d;
			s.method = self.total() > 0 ? s.method : null;
			self.render();
		}).catch(function (e) { s.repeatOn = false; s.repeatPreview = null; s.error = e.message; self.render(); });
	};

	/* ---------- bundles ---------- */

	Booking.prototype.pickBundle = function (id) {
		var s = this.s;
		s.bundle = (s.bundles || []).filter(function (x) { return x.id === id; })[0] || null;
		if (!s.bundle) { return; }
		s.method = s.bundle.methods.length === 1 ? s.bundle.methods[0].id : null;
		this.go('details');
	};

	Booking.prototype.view_bundle = function () {
		var list = this.s.bundles || [];
		if (!list.length) { return '<p class="osb-empty">' + esc(T.noBundles) + '</p>'; }
		var html = '<h3 class="osb-title">' + esc(T.stepBundle) + '</h3><div class="osb-cards">';
		list.forEach(function (x) {
			html += '<button type="button" class="osb-card osb-card--bundle" data-osb="bundle" data-value="' + x.id + '" style="--osb-item:' + esc(x.color) + '">' +
				(x.image ? '<img class="osb-card__img" src="' + esc(x.image) + '" alt="" loading="lazy">' : '<span class="osb-card__dot"></span>') +
				'<span class="osb-card__body"><span class="osb-card__name">' + esc(x.name) + '</span>' +
				'<span class="osb-card__meta">' + esc(x.credits_label + ' · ' + x.validity) + '</span>' +
				'<span class="osb-card__meta">' + esc(T.covers + ': ' + x.covers) + '</span>' +
				(x.description ? '<span class="osb-card__desc">' + x.description + '</span>' : '') +
				'</span><span class="osb-card__price">' + esc(x.price_label) + (x.each_label ? '<small class="osb-card__each">' + esc(x.each_label) + '</small>' : '') + '</span></button>';
		});
		return html + '</div>';
	};

	Booking.prototype.passQuery = function () {
		var q = this.itemQuery();
		q.resource_ids = this.s.mode === 'resources' ? this.s.rsel : [];
		q.dates = this.s.repeatOn && this.s.repeatPreview && this.s.repeatPreview.ok ? this.s.repeatPreview.ok : 1;
		return q;
	};

	Booking.prototype.applyPass = function (code) {
		var self = this;
		var s = this.s;
		this.collect();
		code = (code || '').trim();
		if (!code) { return; }
		s.passBusy = true; s.passMsg = ''; this.render();
		var body = this.passQuery();
		body.code = code;
		api('/bundles/check', { method: 'POST', body: body }).then(function (res) {
			s.passBusy = false; s.pass = res; s.passInput = res.code; s.coupon = null;
			s.method = self.dateTotal() > 0 ? s.method : null;
			if (s.repeatOn) { return self.loadRepeat(); }
			self.render();
		}).catch(function (e) {
			s.passBusy = false; s.pass = null; s.passInput = code; s.passMsg = e.message; self.render();
		});
	};

	Booking.prototype.passHtml = function () {
		var s = this.s;
		if (!G.bundles || s.mode === 'bundles' || s.mode === 'tables') { return ''; }
		var html = '<div class="osb-coupon osb-pass">';
		if (s.pass) {
			html += '<p class="osb-coupon__applied"><span>✓ ' + esc(s.pass.label) + '</span> <button type="button" class="osb-linkbtn" data-osb="pass-remove">' + esc(T.remove) + '</button></p>';
		} else if (s.passOpen || s.passMsg) {
			html += '<div class="osb-field"><label for="' + this.root.id + '-pass">' + esc(T.bundleLabel) + '</label><div class="osb-coupon__row"><input id="' + this.root.id + '-pass" type="text" name="osb_pass" value="' + esc(s.passInput || '') + '" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="BND-XXXX-XXXX"><button type="button" class="osb-btn osb-btn--ghost" data-osb="pass-apply"' + (s.passBusy ? ' disabled' : '') + '>' + esc(s.passBusy ? T.loading : T.apply) + '</button></div>' +
				(s.passMsg ? '<p class="osb-coupon__msg" role="alert">' + esc(s.passMsg) + '</p>' : '') + '</div>';
		} else {
			html += '<button type="button" class="osb-linkbtn" data-osb="pass-open">' + esc(T.haveBundle) + '</button>';
		}
		return html + '</div>';
	};

	Booking.prototype.itemQuery = function () {
		var s = this.s;
		if (s.mode === 'bundles') { return { type: 'bundle', bundle_id: s.bundle ? s.bundle.id : 0, seats: 1 }; }
		if (s.mode === 'tables') { return { type: 'table', seats: s.covers, area_id: s.area || 0 }; }
		if (s.mode === 'resources') { return { type: 'resource', seats: s.excl ? 1 : s.places }; }
		return {
			type: s.event ? 'event' : (s.course ? 'course' : 'service'),
			service_id: s.service ? s.service.id : 0,
			staff_id: s.course ? 0 : this.staffId(),
			event_id: s.event ? s.event.id : 0,
			intake_id: s.course ? s.course.id : 0,
			seats: s.seats
		};
	};

	Booking.prototype.applyCoupon = function (code) {
		var self = this;
		var s = this.s;
		this.collect();
		code = (code || '').trim();
		if (!code) { return; }
		s.couponBusy = true;
		s.couponMsg = '';
		this.render();
		var body = this.itemQuery();
		body.code = code;
		body.email = s.values.email || '';
		api('/coupons/check', { method: 'POST', body: body }).then(function (res) {
			s.couponBusy = false;
			s.coupon = res;
			s.couponInput = res.code;
			s.method = self.total() > 0 ? s.method : null;
			self.render();
		}).catch(function (e) {
			s.couponBusy = false;
			s.coupon = null;
			s.couponInput = code;
			s.couponMsg = e.message;
			self.render();
		});
	};

	Booking.prototype.maxSeats = function () {
		var s = this.s;
		if (s.mode === 'tables' || s.mode === 'resources' || s.mode === 'bundles') { return 1; }
		if (s.event) { return Math.max(1, s.event.max_per_booking); }
		if (s.course) { return Math.max(1, s.course.max_per_booking); }
		if (s.service && s.service.capacity > 1) {
			var slot = (s.slots || []).filter(function (x) { return x.time === s.time; })[0];
			return Math.max(1, slot ? slot.seats_left : s.service.capacity);
		}
		return 1;
	};

	Booking.prototype.methods = function () {
		var s = this.s;
		if (s.mode === 'tables') { return this.tablePayment().mode === 'deposit' && s.tcfg ? s.tcfg.payment.methods : []; }
		if (s.mode === 'resources') { var rc = this.rChosen(); return rc.length ? rc[0].methods : []; }
		if (s.mode === 'bundles') { return s.bundle ? s.bundle.methods : []; }
		var item = s.event || s.course || s.service;
		return (item ? item.methods : []) || [];
	};

	/* ---------- submit ---------- */

	Booking.prototype.collect = function () {
		var s = this.s;
		var form = this.root.querySelector('form.osb-details');
		if (!form) { return; }
		(G.fields || []).forEach(function (f) {
			if (f.type === 'radio') {
				var r = form.querySelector('[name="osb_' + f.key + '"]:checked');
				s.values[f.key] = r ? r.value : '';
				return;
			}
			var el = form.querySelector('[name="osb_' + f.key + '"]');
			if (!el) { return; }
			s.values[f.key] = f.type === 'checkbox' ? (el.checked ? 1 : '') : el.value.trim();
		});
		var sameBox = form.querySelector('[name="osb_guests_same"]');
		if (sameBox) { s.guestsSame = sameBox.checked; }
		var gf = this.guestFields();
		for (var gi = 2; gi <= s.seats; gi++) {
			var gv = s.guests[gi - 2] || (s.guests[gi - 2] = {});
			gf.forEach(function (f) {
				var nm = 'osb_g' + gi + '_' + f.key;
				if (f.type === 'radio') {
					var rr = form.querySelector('[name="' + nm + '"]:checked');
					if (form.querySelector('[name="' + nm + '"]')) { gv[f.key] = rr ? rr.value : ''; }
					return;
				}
				var ge = form.querySelector('[name="' + nm + '"]');
				if (ge) { gv[f.key] = f.type === 'checkbox' ? (ge.checked ? 1 : '') : ge.value.trim(); }
			});
		}
		var seats = form.querySelector('[name="osb_seats"]');
		if (seats) { s.seats = Math.min(this.maxSeats(), Math.max(1, parseInt(seats.value, 10) || 1)); }
		var m = form.querySelector('[name="osb_method"]:checked');
		if (m) { s.method = m.value; }
		var t = form.querySelector('[name="osb_terms"]');
		s.terms = t ? t.checked : true;
		var hp = form.querySelector('[name="website"]');
		s.hp = hp ? hp.value : '';
		var cc = form.querySelector('[name="osb_coupon"]');
		if (cc) { s.couponInput = cc.value; }
		var pc = form.querySelector('[name="osb_pass"]');
		if (pc) { s.passInput = pc.value; }
		this.addonList().forEach(function (a) {
			var box = form.querySelector('[name="osb_addon_' + a.id + '"]');
			if (box && box.type === 'checkbox') { s.addons[a.id] = box.checked ? 1 : 0; }
		});
		var ro = form.querySelector('[name="osb_repeat_on"]');
		if (ro) { s.repeatOn = ro.checked; }
		var rf = form.querySelector('[name="osb_repeat_freq"]');
		if (rf) { s.repeat.freq = rf.value; }
		var rc = form.querySelector('[name="osb_repeat_count"]');
		if (rc) { s.repeat.count = parseInt(rc.value, 10) || 2; }
	};

	Booking.prototype.submit = function () {
		var self = this;
		var s = this.s;
		this.collect();

		var missing = this.fields().filter(function (f) { return Number(f.required) && !s.values[f.key]; });
		if (!missing.length && s.seats > 1 && !s.guestsSame) {
			var gfs = this.guestFields();
			for (var gn = 2; gn <= s.seats; gn++) {
				var gvals = s.guests[gn - 2] || {};
				if (gfs.some(function (f) { return Number(f.required) && !gvals[f.key]; })) { missing = [gn]; break; }
			}
		}
		if (missing.length) { s.error = T.required; return this.render(); }
		var form = this.root.querySelector('form.osb-details');
		if (form && form.checkValidity && !form.checkValidity()) { form.reportValidity(); return; }
		if (G.terms && !s.terms) { s.error = T.terms; return this.render(); }
		if (this.total() > 0 && !s.method) { s.error = T.chooseMethod; return this.render(); }
		if (this.cfg.preview) { s.error = T.previewOnly; return this.render(); }

		s.busy = true;
		s.error = '';
		this.render();

		var body = this.bookingBody();
		body.fields = (function (all) { var out = {}; self.fields().forEach(function (f) { out[f.key] = all[f.key]; }); return out; })(s.values);
		body.guests = s.seats > 1 && !s.guestsSame ? (function () {
			var gfs = self.guestFields(), list = [];
			for (var n = 2; n <= s.seats; n++) {
				var src = s.guests[n - 2] || {}, one = {};
				gfs.forEach(function (f) { one[f.key] = src[f.key] != null ? src[f.key] : ''; });
				list.push(one);
			}
			return list;
		})() : [];
		body.payment_method = this.total() > 0 ? s.method : '';
		body.waitlist = this.cfg.waitlist || '';
		body.return_url = this.cfg.returnUrl || window.location.href;
		body.website = s.hp || '';
		if (s.repeatOn && this.canRepeat()) { body.repeat = { freq: s.repeat.freq, count: s.repeat.count }; }
		this.submitBody(body);
	};

	// What's being booked, as the server wants it (also used to check repeat dates).
	Booking.prototype.bookingBody = function () {
		var s = this.s;
		var tables = s.mode === 'tables';
		var resv = s.mode === 'resources';
		return {
			type: s.mode === 'bundles' ? 'bundle' : (resv ? 'resource' : (tables ? 'table' : (s.event ? 'event' : (s.course ? 'course' : 'service')))),
			bundle_id: s.bundle ? s.bundle.id : 0,
			addons: this.chosenAddons(),
			pass: s.pass ? s.pass.code : '',
			repeat: s.repeatOn ? { freq: s.repeat.freq, count: s.repeat.count } : null,
			resource_ids: resv ? s.rsel : [],
			hire: resv ? s.hire : '',
			minutes: resv && s.hire === 'time' ? s.minutes : 0,
			days: resv && s.hire === 'day' ? s.rdays : 1,
			half: resv && s.ropt && s.ropt.half ? s.ropt.half : '',
			exclusive: resv ? s.excl : false,
			area_id: tables ? (s.area || 0) : 0,
			service_id: s.service ? s.service.id : 0,
			staff_id: s.course ? 0 : this.staffId(),
			event_id: s.event ? s.event.id : 0,
			intake_id: s.course ? s.course.id : 0,
			date: s.date || '',
			time: s.time || '',
			seats: tables ? s.covers : (resv ? (s.excl ? 1 : s.places) : s.seats),
			coupon: s.coupon ? s.coupon.code : ''
		};
	};

	Booking.prototype.submitBody = function (body) {
		var self = this;
		var s = this.s;
		api('/bookings', { method: 'POST', body: body }).then(function (res) {
			if (res.redirect) {
				s.redirecting = true;
				self.render();
				window.location.href = res.redirect;
				return;
			}
			s.busy = false;
			s.result = res.details;
			s.skipped = res.skipped || [];
			s.token = res.token;
			s.outcome = res.status === 'pending' ? 'pending_approval' : 'success';
			self.go('done');
		}).catch(function (e) {
			s.busy = false;
			s.error = e.message;
			// The slot may have gone — refresh times.
			if (e.data && e.data.code === 'osb_unavailable' && s.mode === 'resources') {
				s.time = null; s.ropt = null;
				s.step = 'res_when';
				var rmsg = e.message;
				self.pickDate(s.date);
				self.s.error = rmsg;
				return self.render(true);
			}
			if (e.data && e.data.code === 'osb_unavailable' && s.mode === 'tables') {
				s.time = null;
				s.step = 'table';
				var tmsg = e.message;
				self.pickDate(s.date);
				self.s.error = tmsg;
				return self.render(true);
			}
			if (e.data && e.data.code === 'osb_unavailable' && s.service) {
				s.time = null;
				self.go('datetime');
				self.s.error = e.message;
				return self.pickDate(s.date);
			}
			if (e.data && (e.data.code === 'osb_full' || e.data.code === 'osb_course_closed') && s.course) {
				// Places changed while they were filling in the form: refresh the list.
				var msg = e.message;
				return api('/courses', { query: { ids: (self.cfg.courses || []).join(',') } }).then(function (d) {
					s.courses = d;
					s.course = null;
					s.step = 'course';
					s.error = msg;
					self.render(true);
				}).catch(function () { self.render(); });
			}
			if (e.data && e.data.code === 'osb_pass') {
				s.pass = null;
				s.passMsg = e.message;
				s.error = '';
			}
			if (e.data && e.data.code === 'osb_coupon') {
				s.coupon = null;
				s.couponMsg = e.message;
				s.error = '';
			}
			self.render();
		});
	};

	Booking.prototype.loadResult = function (token, outcome) {
		var self = this;
		var s = this.s;
		this.loading();
		api('/bookings/' + encodeURIComponent(token)).then(function (d) {
			s.result = d;
			s.token = token;
			s.outcome = outcome || 'view';
			if (outcome === 'success' && d.status_key === 'pending') { s.outcome = 'pending_approval'; }
			if (outcome === 'success' && d.status_key === 'pending_payment') { s.outcome = 'pending'; }
			s.step = 'done';
			self.render();
		}).catch(function (e) {
			self.fatal(e.message);
			self.cleanUrl();
		});
	};

	Booking.prototype.cancelBooking = function (all) {
		var self = this;
		var s = this.s;
		if (!window.confirm(all ? T.cancelAllConfirm : T.cancelConfirm)) { return; }
		s.busy = true;
		this.render();
		api('/bookings/' + encodeURIComponent(s.token) + '/cancel', { method: 'POST', body: { all: all ? 1 : 0 } }).then(function (d) {
			s.busy = false;
			s.result = d;
			s.outcome = 'customer_cancelled';
			self.render();
		}).catch(function (e) { s.busy = false; s.error = e.message; self.render(); });
	};

	Booking.prototype.cleanUrl = function () {
		if (window.history && window.history.replaceState) {
			var url = new URL(window.location.href);
			['osb_booking', 'osb_outcome'].forEach(function (k) { url.searchParams.delete(k); });
			window.history.replaceState({}, '', url.toString());
		}
	};

	/* ---------- events ---------- */

	Booking.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('click', function (e) {
			var el = e.target.closest('[data-osb]');
			if (!el || !self.root.contains(el) || el.disabled) { return; }
			var act = el.getAttribute('data-osb');
			var val = el.getAttribute('data-value');
			switch (act) {
				case 'mode': e.preventDefault(); self.s.mode = val; self.reset(); break;
				case 'category': e.preventDefault(); self.s.category = val; self.render(); break;
				case 'service': e.preventDefault(); self.pickService(parseInt(val, 10)); break;
				case 'staff': e.preventDefault(); self.pickStaff(parseInt(val, 10)); break;
				case 'month': e.preventDefault(); self.s.autoJumped = 99; self.shiftMonth(parseInt(val, 10)); break;
				case 'date': e.preventDefault(); self.pickDate(val); break;
				case 'time': e.preventDefault(); self.pickTime(val); break;
				case 'event': e.preventDefault(); self.pickEvent(parseInt(val, 10)); break;
				case 'course': e.preventDefault(); self.pickCourse(parseInt(val, 10)); break;
				case 'back': e.preventDefault(); self.collect(); self.back(); break;
				case 'goto': e.preventDefault(); self.collect(); self.go(val); break;
				case 'submit': e.preventDefault(); self.submit(); break;
				case 'again': e.preventDefault(); self.cleanUrl(); self.s.token = null; if (self.s.services || self.s.events || self.s.courses || self.s.tcfg || self.s.rlist) { self.reset(); } else { self.start(); } break;
				case 'covers': e.preventDefault(); self.setCovers(parseInt(val, 10)); break;
				case 'res': e.preventDefault(); self.toggleRes(parseInt(val, 10)); break;
				case 'res-next': e.preventDefault(); self.enterRes(); break;
				case 'hire': e.preventDefault(); self.s.hire = val; self.resChanged(); break;
				case 'excl': e.preventDefault(); self.s.excl = val === '1'; self.resChanged(); break;
				case 'ropt': e.preventDefault(); self.pickResOpt(parseInt(val, 10)); break;
				case 'area': e.preventDefault(); self.s.area = parseInt(val, 10) || 0; self.s.autoJumped = 0; self.enterTables(); break;
				case 'cancel': e.preventDefault(); self.cancelBooking(); break;
				case 'waitlist': e.preventDefault(); self.openWaitlist(val); break;
				case 'waitlist-submit': e.preventDefault(); self.submitWaitlist(); break;
				case 'coupon-open': e.preventDefault(); self.collect(); self.s.couponOpen = true; self.render(); var ci = self.root.querySelector('[name="osb_coupon"]'); if (ci) { ci.focus(); } break;
				case 'coupon-apply': e.preventDefault(); var inp = self.root.querySelector('[name="osb_coupon"]'); self.applyCoupon(inp ? inp.value : ''); break;
				case 'coupon-remove': e.preventDefault(); self.collect(); self.s.coupon = null; self.s.couponInput = ''; self.s.couponMsg = ''; self.afterChange(); break;
				case 'pass-open': e.preventDefault(); self.collect(); self.s.passOpen = true; self.render(); var pi = self.root.querySelector('[name="osb_pass"]'); if (pi) { pi.focus(); } break;
				case 'pass-apply': e.preventDefault(); var pin = self.root.querySelector('[name="osb_pass"]'); self.applyPass(pin ? pin.value : ''); break;
				case 'pass-remove': e.preventDefault(); self.collect(); self.s.pass = null; self.s.passInput = ''; self.s.passMsg = ''; self.afterChange(); break;
				case 'addon-inc': e.preventDefault(); self.setAddon(parseInt(val, 10), (self.s.addons[parseInt(val, 10)] || 0) + 1); break;
				case 'addon-dec': e.preventDefault(); self.setAddon(parseInt(val, 10), (self.s.addons[parseInt(val, 10)] || 0) - 1); break;
				case 'bundle': e.preventDefault(); self.pickBundle(parseInt(val, 10)); break;
				case 'cancel-all': e.preventDefault(); self.cancelBooking(true); break;
				case 'reschedule': e.preventDefault(); self.startReschedule(); break;
				case 'resched-confirm': e.preventDefault(); self.confirmReschedule(); break;
				case 'resched-cancel': e.preventDefault(); self.s.result = self.s.resched ? self.s.resched.from : self.s.result; self.s.resched = null; self.s.outcome = 'view'; self.go('done'); break;
			}
		});
		this.root.addEventListener('change', function (e) {
			if (e.target.name === 'osb_covers_more' && e.target.value) { return self.setCovers(parseInt(e.target.value, 10)); }
			if (e.target.name === 'osb_minutes') { self.s.minutes = parseInt(e.target.value, 10); return self.resChanged(); }
			if (e.target.name === 'osb_rdays') { self.s.rdays = parseInt(e.target.value, 10); return self.resChanged(); }
			if (e.target.name === 'osb_places') { var R = self.resRules(); self.s.places = Math.max(1, Math.min(R.max, parseInt(e.target.value, 10) || 1)); return self.resChanged(); }
			if (/^osb_addon_/.test(e.target.name)) { self.collect(); return self.afterChange(); }
			if (e.target.name === 'osb_repeat_on' || e.target.name === 'osb_repeat_freq' || e.target.name === 'osb_repeat_count') {
				self.collect();
				if (self.s.pass && self.s.repeatOn) { self.s.pass = null; self.s.passMsg = T.bundleLabel ? '' : ''; }
				return self.loadRepeat();
			}
			if (e.target.name === 'osb_seats' && self.s.repeatOn) { self.collect(); return self.loadRepeat(); }
			if (e.target.name === 'osb_seats' || e.target.name === 'osb_method' || e.target.name === 'osb_guests_same') {
				var before = self.s.seats;
				self.collect();
				if (e.target.name === 'osb_seats' && self.s.coupon && before !== self.s.seats) { return self.applyCoupon(self.s.coupon.code); }
				self.render();
			}
		});
		this.root.addEventListener('submit', function (e) {
			e.preventDefault();
			if (e.target.classList.contains('osb-wl')) { return self.submitWaitlist(); }
			self.submit();
		});
	};

	/* ---------- rendering ---------- */

	Booking.prototype.render = function (scroll) {
		var s = this.s;
		var html = '';
		if (s.step !== 'done' && !s.resched) {
			html += this.renderTabs();
			html += this.renderProgress();
		}
		html += '<div class="osb-body">';
		if (s.error) { html += '<div class="osb-alert osb-alert--error" role="alert">' + esc(s.error) + '</div>'; }
		if (s.notice && s.step === 'details') { html += '<div class="osb-alert osb-alert--info" role="status">' + esc(s.notice) + '</div>'; }
		if (s.wlExpired && (s.step === 'event' || s.step === 'course' || s.step === 'service')) { html += '<div class="osb-alert osb-alert--info" role="status">' + esc(T.wlExpired) + '</div>'; }
		html += this['view_' + s.step]();
		html += '</div>';
		this.root.innerHTML = html;
		this.root.classList.toggle('osb-is-busy', !!s.busy);

		if (scroll) {
			var r = this.root.getBoundingClientRect();
			if (r.top < 0 || r.top > window.innerHeight * 0.6) {
				window.scrollTo({ top: window.pageYOffset + r.top - 80, behavior: 'smooth' });
			}
			var h = this.root.querySelector('.osb-title');
			if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
		}
	};

	Booking.prototype.renderTabs = function () {
		var s = this.s;
		if (this.cfg.mode !== 'all') { return ''; }
		if (['service', 'event', 'course', 'table', 'resource', 'bundle'].indexOf(s.step) === -1) { return ''; }
		var tabs = [];
		if (s.services && s.services.length) { tabs.push(['services', L.tab_services]); }
		if (s.events && s.events.length) { tabs.push(['events', L.tab_events]); }
		if (s.courses && s.courses.length) { tabs.push(['courses', L.tab_courses]); }
		if (s.rlist && s.rlist.length) { tabs.push(['resources', L.tab_resources]); }
		if (s.tcfg) { tabs.push(['tables', L.tab_tables]); }
		if (s.bundles && s.bundles.length) { tabs.push(['bundles', T.tabBundles]); }
		if (tabs.length < 2) { return ''; }
		return '<div class="osb-tabs" role="tablist">' + tabs.map(function (t) {
			return '<button type="button" role="tab" aria-selected="' + (s.mode === t[0]) + '" class="osb-tab' + (s.mode === t[0] ? ' is-active' : '') + '" data-osb="mode" data-value="' + t[0] + '">' + esc(t[1]) + '</button>';
		}).join('') + '</div>';
	};

	Booking.prototype.renderProgress = function () {
		var steps = this.steps();
		if (steps.length < 2) { return ''; }
		var cur = steps.indexOf(this.s.step);
		var names = { service: L.step_service, staff: L.step_staff, datetime: L.step_datetime, event: L.step_event, course: L.step_course, table: L.step_table, resource: L.step_resource, res_when: L.step_res_when, bundle: T.stepBundle, details: L.step_details };
		return '<ol class="osb-progress">' + steps.map(function (st, i) {
			var cls = i < cur ? 'is-done' : (i === cur ? 'is-current' : '');
			var inner = i < cur ? '<button type="button" data-osb="goto" data-value="' + st + '">' + esc(names[st]) + '</button>' : '<span>' + esc(names[st]) + '</span>';
			return '<li class="' + cls + '"' + (i === cur ? ' aria-current="step"' : '') + '><b>' + (i + 1) + '</b>' + inner + '</li>';
		}).join('') + '</ol>';
	};

	Booking.prototype.backBtn = function () {
		var steps = this.steps();
		return steps.indexOf(this.s.step) > 0 ? '<button type="button" class="osb-btn osb-btn--ghost" data-osb="back">← ' + esc(L.back) + '</button>' : '';
	};

	Booking.prototype.view_service = function () {
		var s = this.s;
		var list = s.services || [];
		if (!list.length) { return '<p class="osb-empty">' + esc(T.noServices) + '</p>'; }
		var cats = [];
		list.forEach(function (x) { if (x.category && cats.indexOf(x.category) === -1) { cats.push(x.category); } });
		var html = '<h3 class="osb-title">' + esc(L.step_service) + '</h3>';
		if (cats.length > 1 && !this.cfg.category) {
			html += '<div class="osb-chips"><button type="button" class="osb-chip' + (!s.category ? ' is-active' : '') + '" data-osb="category" data-value="">' + esc(T.all) + '</button>' +
				cats.map(function (c) { return '<button type="button" class="osb-chip' + (s.category === c ? ' is-active' : '') + '" data-osb="category" data-value="' + esc(c) + '">' + esc(c) + '</button>'; }).join('') + '</div>';
		}
		html += '<div class="osb-cards">';
		list.filter(function (x) { return !s.category || x.category === s.category; }).forEach(function (x) {
			html += '<button type="button" class="osb-card" data-osb="service" data-value="' + x.id + '" style="--osb-item:' + esc(x.color) + '">' +
				(x.image ? '<img class="osb-card__img" src="' + esc(x.image) + '" alt="" loading="lazy">' : '<span class="osb-card__dot"></span>') +
				'<span class="osb-card__body"><span class="osb-card__name">' + esc(x.name) + '</span>' +
				'<span class="osb-card__meta osb-card__duration">' + x.duration + ' ' + esc(T.minutes) + (x.online ? ' · ' + esc(T.online) : '') + '</span>' +
				(x.description ? '<span class="osb-card__desc">' + x.description + '</span>' : '') +
				'</span><span class="osb-card__price">' + esc(x.price_label) + '</span></button>';
		});
		return html + '</div>';
	};

	Booking.prototype.view_staff = function () {
		var s = this.s;
		var html = '<h3 class="osb-title">' + esc(L.step_staff) + '</h3><div class="osb-cards osb-cards--staff">';
		if (G.anyStaff && (s.staffList || []).length > 1) {
			html += '<button type="button" class="osb-card" data-osb="staff" data-value="0"><span class="osb-avatar osb-avatar--any">★</span><span class="osb-card__body"><span class="osb-card__name">' + esc(L.any_staff) + '</span></span></button>';
		}
		(s.staffList || []).forEach(function (x) {
			var price = x.price !== s.service.price ? '<span class="osb-card__price">' + esc(x.price_label) + '</span>' : '';
			html += '<button type="button" class="osb-card' + (s.staff && s.staff.id === x.id ? ' is-selected' : '') + '" data-osb="staff" data-value="' + x.id + '">' +
				(x.image ? '<img class="osb-avatar" src="' + esc(x.image) + '" alt="">' : '<span class="osb-avatar">' + esc(x.name.charAt(0)) + '</span>') +
				'<span class="osb-card__body"><span class="osb-card__name">' + esc(x.name) + '</span>' +
				(x.description ? '<span class="osb-card__desc">' + x.description + '</span>' : '') + '</span>' + price + '</button>';
		});
		return html + '</div><div class="osb-actions">' + this.backBtn() + '</div>';
	};

	Booking.prototype.view_reschedule = function () {
		var s = this.s;
		var from = s.resched ? s.resched.from : {};
		var slot = (s.slots || []).filter(function (x) { return x.time === s.time; })[0] || { label: s.time };
		return '<h3 class="osb-title">' + esc(T.rescheduleCheck) + '</h3>' +
			'<div class="osb-done__card osb-resched"><p class="osb-summary__item">' + esc(from.item_name) + '</p><dl>' +
			'<dt>' + esc(T.rescheduleFrom) + '</dt><dd><s>' + esc(from.date + ', ' + from.time) + '</s></dd>' +
			'<dt>' + esc(T.rescheduleTo) + '</dt><dd><strong>' + esc(prettyDate(s.date) + ', ' + slot.label) + '</strong></dd>' +
			(from.staff_name ? '<dt>' + esc(T.with) + '</dt><dd>' + esc(from.staff_name) + '</dd>' : '') +
			'</dl></div><div class="osb-actions"><button type="button" class="osb-btn osb-btn--ghost" data-osb="goto" data-value="datetime">← ' + esc(L.back) + '</button>' +
			'<button type="button" class="osb-btn osb-btn--primary" data-osb="resched-confirm"' + (s.busy ? ' disabled' : '') + '>' + esc(s.busy ? T.loading : T.rescheduleConfirm) + '</button></div>';
	};

	Booking.prototype.view_datetime = function () {
		var s = this.s;
		var html = '<h3 class="osb-title">' + esc(s.resched ? T.rescheduleTitle : L.step_datetime) + '</h3>';
		html += '<p class="osb-subtitle">' + esc(s.service.name) + (s.staff && !G.solo ? ' · ' + esc(s.staff.name) : '') + '</p>';
		html += '<div class="osb-datetime"><div class="osb-calendar">' + this.renderCalendar() + '</div><div class="osb-slots" aria-live="polite">';
		if (!s.date) {
			html += s.dates === null ? '<div class="osb-loading">' + esc(T.loading) + '</div>' : '<p class="osb-muted">' + esc(s.dates.length ? T.pickDate : L.no_slots) + '</p>';
		} else {
			html += '<p class="osb-slots__date">' + esc(prettyDate(s.date)) + '</p>';
			if (s.slots === null) {
				html += '<div class="osb-loading">' + esc(T.loading) + '</div>';
			} else if (!s.slots.length) {
				html += '<p class="osb-muted">' + esc(L.no_slots) + '</p>';
			} else {
				html += '<div class="osb-slots__grid">' + s.slots.map(function (x) {
					var left = s.service.capacity > 1 ? '<small class="osb-seats-left">' + esc((L.seats_left || '%d').replace('%d', x.seats_left)) + '</small>' : '';
					return '<button type="button" class="osb-slot' + (s.time === x.time ? ' is-selected' : '') + '" data-osb="time" data-value="' + esc(x.time) + '">' + esc(x.label) + left + '</button>';
				}).join('') + '</div>';
			}
		}
		html += '</div></div><div class="osb-actions">' + (s.resched ? '<button type="button" class="osb-btn osb-btn--ghost" data-osb="resched-cancel">← ' + esc(T.keepBooking) + '</button>' : this.backBtn()) + '</div>';
		return html;
	};

	Booking.prototype.renderCalendar = function () {
		var s = this.s;
		var parts = s.month.split('-');
		var y = parseInt(parts[0], 10);
		var m = parseInt(parts[1], 10) - 1;
		var first = new Date(y, m, 1);
		var days = new Date(y, m + 1, 0).getDate();
		var sow = parseInt(G.startOfWeek, 10) || 0;
		var offset = (first.getDay() - sow + 7) % 7;
		var today = ymd(new Date());
		var minMonth = s.bounds ? s.bounds.min.slice(0, 7) : today.slice(0, 7);
		var maxMonth = s.bounds ? s.bounds.max.slice(0, 7) : '9999-12';

		var html = '<div class="osb-cal__head">' +
			'<button type="button" class="osb-cal__nav" data-osb="month" data-value="-1" aria-label="' + esc(T.prevMonth) + '"' + (s.month <= minMonth ? ' disabled' : '') + '>‹</button>' +
			'<span class="osb-cal__title">' + esc((G.months || [])[m] || '') + ' ' + y + '</span>' +
			'<button type="button" class="osb-cal__nav" data-osb="month" data-value="1" aria-label="' + esc(T.nextMonth) + '"' + (s.month >= maxMonth ? ' disabled' : '') + '>›</button></div>';
		html += '<div class="osb-cal__grid' + (s.dates === null ? ' is-loading' : '') + '">';
		for (var w = 0; w < 7; w++) {
			html += '<span class="osb-cal__dow">' + esc((G.weekdays || [])[(w + sow) % 7] || '') + '</span>';
		}
		for (var i = 0; i < offset; i++) { html += '<span></span>'; }
		for (var d = 1; d <= days; d++) {
			var date = y + '-' + pad(m + 1) + '-' + pad(d);
			var ok = s.dates && s.dates.indexOf(date) > -1;
			var cls = 'osb-cal__day' + (ok ? ' is-available' : '') + (s.date === date ? ' is-selected' : '') + (date === today ? ' is-today' : '');
			html += '<button type="button" class="' + cls + '" data-osb="date" data-value="' + date + '"' + (ok ? '' : ' disabled') + '>' + d + '</button>';
		}
		return html + '</div>';
	};

	Booking.prototype.view_event = function () {
		var s = this.s;
		var list = s.events || [];
		var html = '<h3 class="osb-title">' + esc(L.step_event) + '</h3>';
		if (!list.length) { return html + '<p class="osb-empty">' + esc(L.no_events) + '</p>'; }
		html += '<div class="osb-events">';
		list.forEach(function (x) {
			var full = x.seats_left < 1;
			if (full && x.waitlist) { html += '<div class="osb-course is-full">'; }
			html += '<button type="button" class="osb-event' + (full ? ' is-full' : '') + '" data-osb="event" data-value="' + x.id + '"' + (full ? ' disabled' : '') + ' style="--osb-item:' + esc(x.color) + '">' +
				'<span class="osb-event__date"><b>' + esc(x.day) + '</b>' + esc(x.month) + '</span>' +
				(x.image ? '<img class="osb-event__img" src="' + esc(x.image) + '" alt="" loading="lazy">' : '') +
				'<span class="osb-card__body"><span class="osb-card__name">' + esc(x.name) + '</span>' +
				'<span class="osb-card__meta">' + esc(x.date_label) + ' · ' + esc(x.time_label) + (x.location ? ' · ' + esc(x.location) : '') + '</span>' +
				(x.description ? '<span class="osb-card__desc">' + x.description + '</span>' : '') +
				'<span class="osb-event__left">' + esc(full ? L.sold_out : (L.seats_left || '%d').replace('%d', x.seats_left)) + '</span></span>' +
				'<span class="osb-card__price">' + esc(x.price_label) + '</span></button>';
			if (full && x.waitlist) { html += '<button type="button" class="osb-linkbtn osb-waitlist-btn" data-osb="waitlist" data-value="event:' + x.id + '">' + esc(L.waitlist_join) + '</button></div>'; }
		});
		return html + '</div>';
	};

	Booking.prototype.openWaitlist = function (val) {
		var p = String(val).split(':');
		var s = this.s;
		var list = p[0] === 'course' ? (s.courses || []) : (s.events || []);
		var item = list.filter(function (x) { return x.id === parseInt(p[1], 10); })[0];
		if (!item) { return; }
		s.wlItem = { type: p[0], item: item };
		s.wlDone = null;
		this.go('waitlist');
	};

	Booking.prototype.submitWaitlist = function () {
		var self = this;
		var s = this.s;
		var form = this.root.querySelector('form.osb-wl');
		if (!form) { return; }
		if (form.checkValidity && !form.checkValidity()) { form.reportValidity(); return; }
		var val = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ''; };
		s.busy = true; s.error = ''; this.render();
		api('/waitlist', { method: 'POST', body: { type: s.wlItem.type, id: s.wlItem.item.id, name: val('wl_name'), email: val('wl_email'), phone: val('wl_phone'), seats: parseInt(val('wl_seats'), 10) || 1, website: val('website') } }).then(function (res) {
			s.busy = false;
			s.wlDone = res;
			s.values.name = val('wl_name'); s.values.email = val('wl_email');
			self.render();
		}).catch(function (e) { s.busy = false; s.error = e.message; self.render(); });
	};

	Booking.prototype.view_waitlist = function () {
		var s = this.s;
		var x = s.wlItem.item;
		var back = '<button type="button" class="osb-btn osb-btn--ghost" data-osb="goto" data-value="' + (s.wlItem.type === 'course' ? 'course' : 'event') + '">← ' + esc(L.back) + '</button>';
		var head = '<h3 class="osb-title">' + esc(L.waitlist_title) + '</h3><p class="osb-subtitle">' + esc(x.name) + ' · ' + esc(x.date_label) + '</p>';
		if (s.wlDone) {
			return head + '<div class="osb-done osb-done--info"><div class="osb-done__icon" aria-hidden="true">✓</div><p>' + esc(L.waitlist_done) + '</p>' +
				(s.wlDone.ahead ? '<p class="osb-muted">' + esc(s.wlDone.ahead === 1 && T.wlAheadOne ? T.wlAheadOne : (T.wlAhead || '%d').replace('%d', s.wlDone.ahead)) + '</p>' : '') + '</div><div class="osb-actions">' + back + '</div>';
		}
		var id = this.root.id;
		var max = Math.max(1, x.max_per_booking || 1);
		return head + '<p>' + esc(L.waitlist_text) + '</p><form class="osb-details osb-wl" novalidate>' +
			'<div class="osb-field"><label for="' + id + '-wln">' + esc(T.yourName) + ' <span class="osb-req">*</span></label><input id="' + id + '-wln" name="wl_name" required autocomplete="name" value="' + esc(s.values.name || '') + '"></div>' +
			'<div class="osb-field osb-field--half"><label for="' + id + '-wle">' + esc(T.email) + ' <span class="osb-req">*</span></label><input id="' + id + '-wle" type="email" name="wl_email" required autocomplete="email" value="' + esc(s.values.email || '') + '"></div>' +
			'<div class="osb-field osb-field--half"><label for="' + id + '-wlp">' + esc(T.phone) + '</label><input id="' + id + '-wlp" type="tel" name="wl_phone" autocomplete="tel" value="' + esc(s.values.phone || '') + '"></div>' +
			(max > 1 ? '<div class="osb-field osb-field--half"><label for="' + id + '-wls">' + esc(L.seats) + '</label><input id="' + id + '-wls" type="number" name="wl_seats" min="1" max="' + max + '" value="1"></div>' : '') +
			'<div class="osb-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>' +
			'<div class="osb-actions">' + back + '<button type="button" class="osb-btn osb-btn--primary" data-osb="waitlist-submit"' + (s.busy ? ' disabled' : '') + '>' + esc(s.busy ? T.loading : L.waitlist_join) + '</button></div></form>';
	};

	Booking.prototype.scheduleHtml = function (list, defaultLocation) {
		if (!list || !list.length) { return ''; }
		// Only mention a session's location when it differs from the course's usual one.
		list = list.map(function (x) { return x.location === defaultLocation ? Object.assign({}, x, { location: '' }) : x; });
		return '<ol class="osb-schedule">' + list.map(function (x) {
			return '<li><span class="osb-schedule__date">' + esc(x.date_label) + '</span><span class="osb-schedule__time">' + esc(x.time_label) + '</span>' +
				(x.location ? '<span class="osb-schedule__where">' + esc(x.location) + '</span>' : '') +
				(x.note ? '<span class="osb-schedule__note">' + esc(x.note) + '</span>' : '') + '</li>';
		}).join('') + '</ol>';
	};

	Booking.prototype.view_course = function () {
		var self = this;
		var s = this.s;
		var list = s.courses || [];
		var html = '<h3 class="osb-title">' + esc(L.step_course) + '</h3>';
		if (!list.length) { return html + '<p class="osb-empty">' + esc(L.no_courses) + '</p>'; }
		html += '<div class="osb-events osb-courses">';
		list.forEach(function (x) {
			var full = x.seats_left < 1;
			var meta = [x.range_label, (L.sessions || '%d').replace('%d', x.session_count)];
			html += '<div class="osb-course' + (full ? ' is-full' : '') + '" style="--osb-item:' + esc(x.color) + '">' +
				'<button type="button" class="osb-event' + (full ? ' is-full' : '') + '" data-osb="course" data-value="' + x.id + '"' + (full ? ' disabled' : '') + '>' +
				'<span class="osb-event__date"><b>' + esc(x.day) + '</b>' + esc(x.month) + '</span>' +
				(x.image ? '<img class="osb-event__img" src="' + esc(x.image) + '" alt="" loading="lazy">' : '') +
				'<span class="osb-card__body"><span class="osb-card__name">' + esc(x.name) + (x.intake_name ? ' <span class="osb-course__intake">· ' + esc(x.intake_name) + '</span>' : '') + '</span>' +
				'<span class="osb-card__meta">' + esc(meta.join(' · ')) + '</span>' +
				(x.pattern || x.location ? '<span class="osb-card__meta">' + esc([x.pattern, x.location].filter(Boolean).join(' · ')) + '</span>' : '') +
				(x.description ? '<span class="osb-card__desc">' + x.description + '</span>' : '') +
				'<span class="osb-event__left">' + esc(full ? L.sold_out : (L.seats_left || '%d').replace('%d', x.seats_left)) + '</span></span>' +
				'<span class="osb-card__price">' + esc(x.price_label) + '</span></button>' +
				(full && x.waitlist ? '<button type="button" class="osb-linkbtn osb-waitlist-btn" data-osb="waitlist" data-value="course:' + x.id + '">' + esc(L.waitlist_join) + '</button>' : '') +
				'<details class="osb-course__dates"><summary>' + esc(L.show_dates) + '</summary>' + self.scheduleHtml(x.sessions, x.location) + '</details>' +
				'</div>';
		});
		return html + '</div>';
	};

	Booking.prototype.fields = function () {
		var s = this.s;
		if (s.mode === 'bundles') { return (G.fields || []).filter(function (f) { return ['name', 'email', 'phone'].indexOf(f.key) > -1; }); }
		var type = s.mode === 'tables' ? 'table' : (s.mode === 'resources' ? 'resource' : (s.event ? 'event' : (s.course ? 'course' : 'service')));
		var id = s.mode === 'resources' ? (s.rsel[0] || 0) : (s.event ? s.event.id : (s.course ? s.course.course_id : (s.service ? s.service.id : 0)));
		var kind = { service: ['services', 's'], event: ['events', 'e'], course: ['courses', 'c'], table: ['tables', 't'], resource: ['resources', 'r'] }[type];
		return (G.fields || []).filter(function (f) {
			var sf = f.show_for || [];
			if (!sf.length || f.key === 'name' || f.key === 'email') { return true; }
			return sf.indexOf(kind[0]) > -1 || sf.indexOf(kind[1] + ':' + id) > -1;
		});
	};

	// Questions repeated for each extra place: name (required), email (optional) and any field set to "Ask for each place".
	Booking.prototype.guestFields = function () {
		return this.fields().filter(function (f) {
			return f.key === 'name' || (f.per_place != null ? Number(f.per_place) : f.key === 'email');
		}).map(function (f) {
			var g = Object.assign({}, f);
			if (f.key === 'name') { g.required = 1; }
			if (f.key === 'email' || f.key === 'phone') { g.required = 0; }
			return g;
		});
	};

	Booking.prototype.guestsHtml = function () {
		var self = this;
		var s = this.s;
		if (s.seats < 2) { return ''; }
		var same = !!s.guestsSame;
		var html = '<div class="osb-guests"><h4 class="osb-guests__title">' + esc(T.whoComing) + '</h4>' +
			'<div class="osb-field osb-field--check"><label><input type="checkbox" name="osb_guests_same" value="1"' + (same ? ' checked' : '') + '> ' + esc(T.guestsSame) + '</label></div>';
		if (!same) {
			var gf = this.guestFields();
			for (var i = 2; i <= s.seats; i++) {
				var vals = s.guests[i - 2] || {};
				html += '<fieldset class="osb-guest"><legend>' + esc((T.place || 'Place %d').replace('%d', i)) + '</legend><div class="osb-fields">' +
					gf.map(function (f) { return self.fieldHtml(f, vals, 'osb_g' + i + '_'); }).join('') + '</div></fieldset>';
			}
		}
		return html + '</div>';
	};

	Booking.prototype.fieldHtml = function (f, vals, prefix) {
		vals = vals || this.s.values;
		var v = vals[f.key] != null ? vals[f.key] : '';
		var name = (prefix || 'osb_') + f.key;
		var id = this.root.id + '-' + (prefix || '') + f.key;
		var req = Number(f.required) ? ' required' : '';
		var star = Number(f.required) ? ' <span class="osb-req" aria-hidden="true">*</span>' : '';
		var ph = f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '';
		var input = '';
		switch (f.type) {
			case 'textarea':
				input = '<textarea id="' + id + '" name="' + name + '" rows="3"' + req + ph + '>' + esc(v) + '</textarea>';
				break;
			case 'select':
				input = '<select id="' + id + '" name="' + name + '"' + req + '><option value=""></option>' + f.options.map(function (o) {
					return '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>';
				}).join('') + '</select>';
				break;
			case 'radio':
				return '<fieldset class="osb-field osb-field--' + (f.width || 'full') + '"><legend>' + esc(f.label) + star + '</legend><div class="osb-radios">' + f.options.map(function (o, i) {
					return '<label><input type="radio" name="' + name + '" value="' + esc(o) + '"' + (o === v ? ' checked' : '') + (i === 0 ? req : '') + '> ' + esc(o) + '</label>';
				}).join('') + '</div></fieldset>';
			case 'checkbox':
				return '<div class="osb-field osb-field--full osb-field--check"><label><input type="checkbox" name="' + name + '" value="1"' + (v ? ' checked' : '') + req + '> ' + esc(f.label) + star + '</label></div>';
			default:
				var type = ['email', 'tel', 'number', 'date'].indexOf(f.type) > -1 ? f.type : 'text';
				var ac = prefix ? 'off' : { name: 'name', email: 'email', phone: 'tel' }[f.key];
				input = '<input id="' + id + '" type="' + type + '" name="' + name + '" value="' + esc(v) + '"' + req + ph + (ac ? ' autocomplete="' + ac + '"' : '') + '>';
		}
		return '<div class="osb-field osb-field--' + (f.width || 'full') + '"><label for="' + id + '">' + esc(f.label) + star + '</label>' + input + '</div>';
	};

	// Extras, bundle and repeat lines shared by every summary.
	Booking.prototype.extraRows = function (rows) {
		var self = this;
		var s = this.s;
		this.addonList().forEach(function (a) {
			var q = self.addonQty(a);
			if (q > 0) { rows.push([(q > 1 ? q + ' × ' : '') + a.name, a.price > 0 ? money(a.price * q * self.addonFactor(a)) : L.free]); }
		});
		if (s.pass) { rows.push([T.paidWith, s.pass.name]); }
		if (s.repeatOn && s.repeatPreview && s.repeatPreview.dates) {
			rows.push([T.repeats, (G.repeat.freqs[s.repeat.freq] || '')]);
			rows.push([T.dates, s.repeatPreview.ok]);
		}
		return rows;
	};

	Booking.prototype.summaryHtml = function () {
		var s = this.s;
		var rows = [];
		var extra = '';
		if (s.mode === 'bundles') {
			var b = s.bundle || {};
			rows.push([T.includes, b.credits_label || '']);
			rows.push([T.covers, b.covers || '']);
			if (b.validity) { rows.push(['', b.validity]); }
			var bt = this.total();
			return '<aside class="osb-summary"><h4>' + esc(L.summary) + '</h4><p class="osb-summary__item">' + esc(b.name || '') + '</p>' +
				'<dl>' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' +
				'<p class="osb-summary__total"><span>' + esc(T.total) + '</span><strong>' + esc(bt > 0 ? money(bt) : L.free) + '</strong></p></aside>';
		}
		if (s.mode === 'resources') {
			var x = s.ropt || {};
			var hl = { time: L.res_time, half: L.res_half, day: L.res_day };
			rows.push([T.when, prettyDate(s.date) + ', ' + (s.hire === 'time' ? x.label + ' – ' + x.end_label : (s.hire === 'half' ? x.label + ' (' + x.range + ')' : x.range))]);
			if (s.hire === 'time') { rows.push([L.res_length, durLabel(s.minutes)]); } else if (s.hire === 'day' && s.rdays > 1) { rows.push([L.res_days, s.rdays]); } else { rows.push([T.resHow, hl[s.hire]]); }
			rows.push(s.excl ? [T.resUse, L.res_book_out] : [L.res_places, s.places]);
			var rloc = this.rChosen().map(function (r) { return r.location; }).filter(Boolean)[0];
			if (rloc) { rows.push([T.location, rloc]); }
			this.extraRows(rows);
			var rt = this.total();
			return '<aside class="osb-summary"><h4>' + esc(L.summary) + '</h4>' +
				'<p class="osb-summary__item">' + esc(this.rChosen().map(function (r) { return r.name; }).join(' + ')) + '</p>' +
				'<dl>' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' +
				'<p class="osb-summary__total"><span>' + esc(T.total) + '</span><strong>' + esc(rt > 0 ? money(rt) : L.free) + '</strong></p></aside>';
		}
		if (s.mode === 'tables') {
			var tp = this.tablePayment();
			var slot = (s.slots || []).filter(function (x) { return x.time === s.time; })[0] || { label: s.time };
			rows.push([T.when, prettyDate(s.date) + ', ' + slot.label]);
			rows.push([L.covers, s.covers]);
			var ar = s.area && s.tcfg ? (s.tcfg.areas || []).filter(function (a) { return a.id === s.area; })[0] : null;
			if (ar) { rows.push([L.area, ar.name]); }
			var note = '';
			if (s.isRequest) { note = L.request_note; } else if (tp.mode === 'deposit') { note = L.deposit_note; } else if (tp.mode === 'card_hold') { note = (L.card_hold_note || '').replace('%s', money(tp.fee)); }
			if (tp.mode === 'card_hold') { rows.push([T.cardHold, money(tp.fee)]); }
			this.extraRows(rows);
			var pol = s.tcfg && s.tcfg.policy ? '<div class="osb-summary__policy">' + s.tcfg.policy + '</div>' : '';
			var ttl = this.total();
			return '<aside class="osb-summary"><h4>' + esc(L.summary) + '</h4>' +
				'<p class="osb-summary__item">' + esc((L.table_item || '%d').replace('%d', s.covers)) + '</p>' +
				'<dl>' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' +
				(note ? '<p class="osb-summary__note">' + esc(note) + '</p>' : '') + pol +
				(tp.mode === 'deposit' || ttl > 0 ? '<p class="osb-summary__total"><span>' + esc(tp.mode === 'deposit' ? T.depositNow : T.total) + '</span><strong>' + esc(money(ttl)) + '</strong></p>' : '') + '</aside>';
		}
		if (s.event) {
			rows.push([T.when, s.event.date_label + ', ' + s.event.time_label]);
			if (s.event.location) { rows.push([T.location, s.event.location]); }
		} else if (s.course) {
			rows.push([T.starts, s.course.date_label]);
			rows.push([T.sessionsLabel, s.course.session_count]);
			if (s.course.pattern) { rows.push([T.when, s.course.pattern]); }
			if (s.course.location) { rows.push([T.location, s.course.location]); }
			extra = '<details class="osb-course__dates" open><summary>' + esc(L.schedule) + '</summary>' + this.scheduleHtml(s.course.sessions, s.course.location) + '</details>';
		} else {
			rows.push([T.when, prettyDate(s.date) + ', ' + ((s.slots || []).filter(function (x) { return x.time === s.time; })[0] || { label: s.time }).label]);
			if (s.service && s.service.online) { rows.push([T.location, T.online]); }
			if (!G.solo) { rows.push([T.with, s.staff ? s.staff.name : L.any_staff]); }
		}
		if (s.seats > 1) { rows.push([L.seats, s.seats]); }
		this.extraRows(rows);
		if (s.coupon && s.coupon.discount > 0) {
			rows.push([T.subtotal, money(this.subtotal())]);
			rows.push([s.coupon.kind === 'voucher' ? T.giftVoucher + ' ' + s.coupon.code : T.discount + ' ' + s.coupon.code, '−' + money(s.coupon.discount)]);
		}
		var total = this.total();
		return '<aside class="osb-summary"><h4>' + esc(L.summary) + '</h4>' +
			'<p class="osb-summary__item">' + esc(s.event ? s.event.name : (s.course ? s.course.name : s.service.name)) + '</p>' +
			'<dl>' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' + extra +
			'<p class="osb-summary__total"><span>' + esc(T.total) + '</span><strong>' + esc(total > 0 ? money(total) : L.free) + '</strong></p></aside>';
	};

	Booking.prototype.view_details = function () {
		var self = this;
		var s = this.s;
		var max = this.maxSeats();
		var total = this.total();
		var methods = this.methods();
		var html = '<h3 class="osb-title">' + esc(L.step_details) + '</h3><div class="osb-details-wrap"><form class="osb-details" novalidate>';

		if (max > 1) {
			html += '<div class="osb-field osb-field--half"><label for="' + this.root.id + '-seats">' + esc(L.seats) + '</label><input id="' + this.root.id + '-seats" type="number" name="osb_seats" min="1" max="' + max + '" value="' + s.seats + '"></div><div class="osb-field osb-field--half"></div>';
		}
		if (s.seats > 1) { html += '<h4 class="osb-guests__title osb-guests__title--you">' + esc((T.placeYou || 'Your details').replace('%d', 1)) + '</h4>'; }
		html += '<div class="osb-fields">' + this.fields().map(function (f) { return self.fieldHtml(f); }).join('') + '</div>';
		html += this.guestsHtml();
		html += '<div class="osb-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>';
		html += this.addonsHtml();
		html += this.repeatHtml();
		html += this.passHtml();

		if (G.coupons && this.subtotal() > 0 && s.mode !== 'tables' && s.mode !== 'bundles' && !s.pass) {
			html += '<div class="osb-coupon">';
			if (s.coupon) {
				html += '<p class="osb-coupon__applied"><span>✓ <b>' + esc(s.coupon.code) + '</b> — ' + esc(s.coupon.label) + ' (−' + esc(money(s.coupon.discount)) + ')</span> <button type="button" class="osb-linkbtn" data-osb="coupon-remove">' + esc(T.remove) + '</button></p>';
			} else if (s.couponOpen || s.couponMsg) {
				html += '<div class="osb-field"><label for="' + this.root.id + '-coupon">' + esc(T.couponLabel) + '</label><div class="osb-coupon__row"><input id="' + this.root.id + '-coupon" type="text" name="osb_coupon" value="' + esc(s.couponInput || '') + '" autocomplete="off" autocapitalize="characters" spellcheck="false"><button type="button" class="osb-btn osb-btn--ghost" data-osb="coupon-apply"' + (s.couponBusy ? ' disabled' : '') + '>' + esc(s.couponBusy ? T.loading : T.apply) + '</button></div>' +
					(s.couponMsg ? '<p class="osb-coupon__msg" role="alert">' + esc(s.couponMsg) + '</p>' : '') + '</div>';
			} else {
				html += '<button type="button" class="osb-linkbtn" data-osb="coupon-open">' + esc(T.haveCode) + '</button>';
			}
			html += '</div>';
		}

		if (total > 0) {
			html += '<fieldset class="osb-methods"><legend>' + esc(L.step_payment) + '</legend>';
			methods.forEach(function (m) {
				html += '<label class="osb-method' + (s.method === m.id ? ' is-selected' : '') + '"><input type="radio" name="osb_method" value="' + esc(m.id) + '"' + (s.method === m.id ? ' checked' : '') + '> <span><b>' + esc(m.title) + '</b>' + (m.description ? '<small>' + esc(m.description) + '</small>' : '') + '</span></label>';
			});
			html += '</fieldset>';
		}
		if (G.terms) {
			html += '<div class="osb-field osb-field--check osb-terms"><label><input type="checkbox" name="osb_terms" value="1"' + (s.terms ? ' checked' : '') + '> <span>' + G.terms + '</span></label></div>';
		}

		var chosen = methods.filter(function (m) { return m.id === s.method; })[0];
		var label = total > 0 && chosen && chosen.online ? L.pay : L.book;
		if (s.mode === 'tables') {
			if (s.isRequest) { label = T.sendRequest; } else if (this.tablePayment().mode === 'card_hold') { label = T.saveCard; }
		}
		html += '<div class="osb-actions">' + this.backBtn() +
			'<button type="submit" class="osb-btn osb-btn--primary"' + (s.busy ? ' disabled' : '') + '>' + esc(s.redirecting ? T.redirecting : (s.busy ? T.loading : label)) + '</button></div>';
		html += '</form>' + this.summaryHtml() + '</div>';
		return html;
	};

	Booking.prototype.seriesHtml = function (d) {
		if (!d.series || d.series.length < 2) { return ''; }
		return '<details class="osb-course__dates osb-series" open><summary>' + esc(T.dates + ' (' + d.series_count + ')') + '</summary><ul class="osb-repeat__dates">' + d.series.map(function (x) {
			return '<li class="' + (x.active ? 'is-ok' : 'is-taken') + (x.current ? ' is-current' : '') + '"><span>' + esc(x.label) + '</span><small>' + esc(x.status) + '</small></li>';
		}).join('') + '</ul></details>';
	};

	Booking.prototype.bundleCodeHtml = function (d) {
		if (!d.bundle) { return ''; }
		if (!d.bundle.code) { return d.bundle.pending ? '<p class="osb-access osb-access--later">' + esc(d.bundle.pending) + '</p>' : ''; }
		return '<div class="osb-access"><span class="osb-access__label">' + esc(T.bundleCode) + '</span><strong class="osb-access__code">' + esc(d.bundle.code) + '</strong>' +
			'<p class="osb-access__note">' + esc(d.bundle.credits + (d.bundle.expires ? ' · ' + T.useBy + ' ' + d.bundle.expires : '') + '. ' + T.bundleUse) + '</p></div>';
	};

	Booking.prototype.accessHtml = function (d) {
		if (d.status_key === 'cancelled') { return ''; }
		var html = (d.access || []).map(function (a) {
			return '<div class="osb-access"><span class="osb-access__label">' + esc(a.label) + '</span><strong class="osb-access__code">' + esc(a.code) + '</strong>' +
				(a.note ? '<p class="osb-access__note">' + esc(a.note) + '</p>' : '') + '</div>';
		}).join('');
		var note = d.access_later || (!html && d.access_held) || '';
		if (note) { html += '<p class="osb-access osb-access--later">' + esc(note) + '</p>'; }
		return html;
	};

	Booking.prototype.view_done = function () {
		var s = this.s;
		var d = s.result || {};
		var title = L.success_title;
		var text = L.success_text;
		var tone = 'success';
		switch (s.outcome) {
			case 'pending_approval': text = L.pending_text; break;
			case 'pending': text = T.paymentCheck; tone = 'info'; break;
			case 'cancelled': title = T.yourBooking; text = L.cancelled_text; tone = 'error'; break;
			case 'failed': title = T.yourBooking; text = T.error; tone = 'error'; break;
			case 'customer_cancelled': title = T.yourBooking; text = T.cancelledNow; tone = 'info'; break;
			case 'rescheduled': title = T.yourBooking; text = T.rescheduledNow; tone = 'success'; break;
			case 'view': title = T.yourBooking; text = ''; tone = 'info'; break;
		}
		var isCourse = d.type === 'course';
		var rows = [
			[T.reference, '#' + d.id],
			isCourse ? [T.starts, d.date + ', ' + d.time] : (d.type === 'bundle' ? [T.when, d.date] : [T.when, d.date + ', ' + d.time + (d.end_time ? ' – ' + d.end_time : '')])
		];
		// (resource bookings add how they're hired below)
		if (isCourse) { rows.push([T.sessionsLabel, d.session_count]); }
		if (d.staff_name) { rows.push([T.with, d.staff_name]); }
		if (d.location) { rows.push([T.location, d.location]); }
		if (d.type === 'resource') {
			rows.push([T.resHow, d.hire_label + (d.exclusive ? ' · ' + L.res_book_out : '')]);
			if (!d.exclusive && d.seats > 1) { rows.push([L.res_places, d.seats]); }
		} else if (d.type === 'table') { rows.push([L.covers, d.covers || d.seats]); } else if (d.seats > 1) { rows.push([L.seats, d.seats]); }
		if (d.card_held && d.noshow_fee) { rows.push([T.cardHold, d.noshow_fee]); }
		if (d.attendees && d.attendees.length > 1 && d.attendees.some(function (a) { return !a.same; })) {
			rows.push([T.attendees, d.attendees.map(function (a) { return a.same ? (d.attendees[0].name + ' (' + (T.place || 'Place %d').replace('%d', a.place) + ')') : a.name; }).join(', ')]);
		}
		(d.addons || []).forEach(function (a) { rows.push([a.name, a.amount]); });
		if (d.pass_label) { rows.push([T.paidWith, d.pass_label]); }
		if (d.series_label) { rows.push([T.repeats, d.series_label]); if (d.series_count > 1 && d.series_total) { rows.push([T.allDates, d.series_total]); } }
		if (d.discount) { rows.push([T.discount + (d.coupon_code ? ' ' + d.coupon_code : ''), '−' + d.discount]); }
		if (d.type !== 'table' || d.card_held || d.payment_status_key !== 'free') {
			rows.push([T.payment, d.type === 'table' && d.card_held ? d.payment_method : d.amount + ' · ' + d.payment_method + ' (' + d.payment_status + ')']);
		}
		rows.push([T.status, d.status]);

		var html = '<div class="osb-done osb-done--' + tone + '">' +
			'<div class="osb-done__icon" aria-hidden="true">' + (tone === 'success' ? '✓' : (tone === 'error' ? '!' : 'i')) + '</div>' +
			'<h3 class="osb-title">' + esc(title) + '</h3>' + (text ? '<p>' + esc(text) + '</p>' : '') +
			'<div class="osb-done__card"><p class="osb-summary__item">' + esc(d.item_name) + '</p><dl>' +
			rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' +
			(isCourse && d.schedule && d.schedule.length ? '<details class="osb-course__dates" open><summary>' + esc(L.schedule) + '</summary>' + this.scheduleHtml(d.schedule, d.location) + '</details>' : '') + '</div>' +
			this.accessHtml(d) + this.seriesHtml(d) + this.bundleCodeHtml(d) +
			(s.skipped && s.skipped.length && s.outcome !== 'view' ? '<p class="osb-alert osb-alert--info">' + esc((T.skippedNote || '%s').replace('%s', s.skipped.join(', '))) + '</p>' : '') +
			'<div class="osb-actions osb-actions--center">';
		// Money still owed that can be paid online (not while a payment just made is being confirmed).
		if (d.pay_url && s.outcome !== 'pending') {
			html += '<a class="osb-btn osb-btn--primary osb-btn--pay" href="' + esc(d.pay_url) + '">' + esc(d.pay_label + ' — ' + d.amount_due) + '</a>';
		}
		(d.invoices || []).forEach(function (inv) {
			html += '<a class="osb-btn osb-btn--ghost osb-btn--invoice" href="' + esc(inv.url) + '" target="_blank" rel="noopener">' + esc(T.downloadInvoice ? T.downloadInvoice.replace('%s', inv.label) : inv.label) + '</a>';
		});
		if (d.meeting_url && d.status_key !== 'cancelled') {
			html += '<a class="osb-btn osb-btn--ghost" href="' + esc(d.meeting_url) + '" target="_blank" rel="noopener">' + esc(T.joinOnline) + '</a>';
		}
		if (d.can_reschedule && s.token && s.outcome !== 'customer_cancelled') {
			html += '<button type="button" class="osb-btn osb-btn--ghost" data-osb="reschedule">' + esc(T.reschedule) + '</button>';
		}
		if (d.can_cancel && s.token && s.outcome !== 'customer_cancelled') {
			html += '<button type="button" class="osb-btn osb-btn--ghost osb-btn--danger" data-osb="cancel"' + (s.busy ? ' disabled' : '') + '>' + esc(T.cancelBooking) + '</button>';
			if (d.series && d.series.filter(function (x) { return x.active; }).length > 1) {
				html += '<button type="button" class="osb-btn osb-btn--ghost osb-btn--danger" data-osb="cancel-all"' + (s.busy ? ' disabled' : '') + '>' + esc(T.cancelAll) + '</button>';
			}
		}
		if (G.portalUrl) { html += '<a class="osb-btn osb-btn--ghost" href="' + esc(G.portalUrl) + '">' + esc(T.myBookings) + '</a>'; }
		if (!this.cfg.off) { html += '<button type="button" class="osb-btn osb-btn--primary" data-osb="again">' + esc(T.bookAnother) + '</button>'; }
		html += '</div></div>';
		return html;
	};

	function boot() {
		var nodes = document.querySelectorAll('.osb-booking[data-config]');
		for (var i = 0; i < nodes.length; i++) {
			if (!nodes[i].osbBooking) { nodes[i].osbBooking = new Booking(nodes[i]); }
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	// Elementor editor preview and popups render widgets after load.
	if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', function () {
			if (window.elementorFrontend && window.elementorFrontend.hooks) {
				window.elementorFrontend.hooks.addAction('frontend/element_ready/osam_booking_form.default', boot);
			}
		});
	}
	window.OSBBooking = { boot: boot };
})();
