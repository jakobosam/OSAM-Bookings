/* OSAM Bookings — My bookings panel (no dependencies) */
(function () {
	'use strict';

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function Portal(root) {
		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-portal') || '{}'); } catch (e) { /* ignore */ }
		this.root = root;
		this.cfg = cfg;
		this.T = cfg.i18n || {};
		this.s = { view: 'loading', email: '', data: null, error: '', notice: cfg.notice || '', busy: false, confirm: null, showPast: false };
		this.bind();
		this.load();
	}

	Portal.prototype.api = function (path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' };
		if (this.cfg.nonce) { init.headers['X-WP-Nonce'] = this.cfg.nonce; }
		if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		var T = this.T;
		return fetch(this.cfg.rest + path + (path.indexOf('?') > -1 ? '&' : '?') + '_=' + Date.now(), init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) {
					var err = new Error((data && data.message) || T.error);
					err.status = r.status;
					throw err;
				}
				return data;
			});
		});
	};

	Portal.prototype.load = function () {
		var self = this;
		this.render();
		this.api('/me').then(function (d) {
			if (d.signed_in) { self.s.data = d; self.s.view = 'panel'; } else { self.s.view = 'start'; }
			self.render();
		}).catch(function () { self.s.view = 'start'; self.s.error = self.T.error; self.render(); });
	};

	Portal.prototype.render = function () {
		var s = this.s;
		var T = this.T;
		var html = '';
		if (s.error) { html += '<div class="osb-alert osb-alert--error" role="alert">' + esc(s.error) + '</div>'; }
		if (s.notice) { html += '<div class="osb-alert osb-alert--info" role="status">' + esc(s.notice) + '</div>'; }
		if (s.view === 'loading') {
			html += '<div class="osb-loading">' + esc(T.loading) + '</div>';
		} else if (s.view === 'start') {
			html += '<div class="osb-portal__signin"><h3 class="osb-title">' + esc(T.signInTitle) + '</h3><p class="osb-subtitle">' + esc(T.signInText) + '</p>' +
				'<form class="osb-portal__form" data-form="start" novalidate><div class="osb-field"><label for="osb-portal-email">' + esc(T.email) + '</label>' +
				'<input id="osb-portal-email" type="email" name="email" autocomplete="email" inputmode="email" required value="' + esc(s.email) + '"></div>' +
				'<button type="submit" class="osb-btn osb-btn--primary osb-btn--block"' + (s.busy ? ' disabled' : '') + '>' + esc(s.busy ? T.loading : T.sendLink) + '</button></form></div>';
		} else if (s.view === 'sent') {
			html += '<div class="osb-portal__signin"><div class="osb-done__icon osb-portal__icon" aria-hidden="true">✉</div><h3 class="osb-title">' + esc(T.sentTitle) + '</h3>' +
				'<p class="osb-subtitle">' + esc((T.sentText || '%s').replace('%s', s.email)) + '</p>' +
				'<form class="osb-portal__form" data-form="code" novalidate><div class="osb-field"><label for="osb-portal-code">' + esc(T.codeLabel) + '</label>' +
				'<input id="osb-portal-code" class="osb-portal__code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" required></div>' +
				'<button type="submit" class="osb-btn osb-btn--primary osb-btn--block"' + (s.busy ? ' disabled' : '') + '>' + esc(s.busy ? T.loading : T.signIn) + '</button></form>' +
				'<p class="osb-portal__alt"><button type="button" class="osb-linkbtn" data-act="restart">' + esc(T.otherEmail) + '</button></p></div>';
		} else {
			html += this.panelHtml();
		}
		this.root.innerHTML = html;
		this.root.classList.toggle('osb-is-busy', !!s.busy);
	};

	Portal.prototype.panelHtml = function () {
		var self = this;
		var s = this.s;
		var T = this.T;
		var d = s.data || {};
		var html = '<div class="osb-portal__head"><div><h3 class="osb-title">' + esc(T.title) + '</h3><p class="osb-subtitle">' + esc(T.signedInAs) + ' <b>' + esc(d.email) + '</b></p></div>' +
			'<div class="osb-portal__headbtns"><a class="osb-btn osb-btn--primary" href="' + esc(this.cfg.bookUrl) + '">' + esc(T.bookNow) + '</a><button type="button" class="osb-btn osb-btn--ghost" data-act="logout">' + esc(T.signOut) + '</button></div></div>';

		if (d.bundles && d.bundles.length) {
			html += '<h4 class="osb-portal__h">' + esc(T.bundles) + '</h4><div class="osb-portal__bundles">' + d.bundles.map(function (b) {
				var pct = b.of ? Math.round(b.left / b.of * 100) : 0;
				return '<div class="osb-portal__bundle"><div class="osb-portal__bundletop"><b>' + esc(b.name) + '</b><span><strong>' + b.left + '</strong> / ' + b.of + ' ' + esc(T.left) + '</span></div>' +
					'<div class="osb-portal__meter"><i style="width:' + pct + '%"></i></div>' +
					(b.code ? '<p class="osb-portal__bcode">' + esc(T.code) + ': <code>' + esc(b.code) + '</code></p>' : '') +
					'<p class="osb-muted">' + esc(b.covers) + (b.expires ? ' · ' + esc(T.useBy) + ' ' + esc(b.expires) : '') + '</p></div>';
			}).join('') + '</div>';
		}

		html += '<h4 class="osb-portal__h">' + esc(T.upcoming) + '</h4>';
		if (!d.upcoming || !d.upcoming.length) {
			html += '<div class="osb-portal__empty"><p>' + esc(T.noUpcoming) + '</p><a class="osb-btn osb-btn--primary" href="' + esc(this.cfg.bookUrl) + '">' + esc(T.bookNow) + '</a></div>';
		} else {
			html += '<div class="osb-portal__list">' + d.upcoming.map(function (b) { return self.cardHtml(b, true); }).join('') + '</div>';
		}
		if (d.past && d.past.length) {
			var past = s.showPast ? d.past : d.past.slice(0, 3);
			html += '<h4 class="osb-portal__h">' + esc(T.past) + '</h4><div class="osb-portal__list osb-portal__list--past">' + past.map(function (b) { return self.cardHtml(b, false); }).join('') + '</div>';
			if (!s.showPast && d.past.length > 3) { html += '<p class="osb-portal__alt"><button type="button" class="osb-linkbtn" data-act="more">' + esc(T.showMore) + '</button></p>'; }
		}
		return html;
	};

	Portal.prototype.cardHtml = function (b, upcoming) {
		var T = this.T;
		var s = this.s;
		var meta = [b.when];
		if (b.with) { meta.push(b.with); }
		if (b.where) { meta.push(b.where); }
		var html = '<article class="osb-portal__card osb-portal__card--' + esc(b.status_key) + '">' +
			'<div class="osb-portal__date" aria-hidden="true"><b>' + esc(b.day) + '</b>' + esc(b.month) + '</div>' +
			'<div class="osb-portal__main"><div class="osb-portal__top"><h5>' + esc(b.title) + '</h5><span class="osb-portal__status">' + esc(b.status) + '</span></div>' +
			'<p class="osb-portal__meta">' + meta.map(esc).join(' · ') + '</p>';
		if (b.series) { html += '<p class="osb-portal__meta">' + esc(T.repeats) + ': ' + esc(b.series) + '</p>'; }
		(b.rows || []).forEach(function (r) { html += '<p class="osb-portal__meta">' + esc(T[r[0]] || r[0]) + ': ' + esc(r[1]) + '</p>'; });
		if (upcoming) {
			(b.access || []).forEach(function (a) {
				html += '<div class="osb-portal__access"><span>' + esc(a.label) + '</span><strong>' + esc(a.code) + '</strong>' + (a.note ? '<small>' + esc(a.note) + '</small>' : '') + '</div>';
			});
			if (b.access_note) { html += '<p class="osb-portal__meta osb-portal__meta--note">' + esc(b.access_note) + '</p>'; }
		}
		if (s.confirm && s.confirm.token === b.token) {
			html += '<div class="osb-portal__confirm" role="alertdialog"><p>' + esc(s.confirm.all ? T.confirmAll : T.confirmCancel) + '</p>' +
				'<button type="button" class="osb-btn osb-btn--ghost" data-act="keep">' + esc(T.keep) + '</button>' +
				'<button type="button" class="osb-btn osb-btn--danger-solid" data-act="do-cancel"' + (s.busy ? ' disabled' : '') + '>' + esc(s.busy ? T.loading : T.yesCancel) + '</button></div>';
		} else {
			var btns = '';
			if (b.pay_url) { btns += '<a class="osb-btn osb-btn--primary osb-btn--sm" href="' + esc(b.pay_url) + '">' + esc(b.pay_label) + '</a>'; }
			if (upcoming && b.can_move) { btns += '<a class="osb-btn osb-btn--ghost osb-btn--sm" href="' + esc(b.manage_url) + '">' + esc(T.manage) + '</a>'; }
			if (upcoming && b.can_cancel) {
				btns += '<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-btn--danger" data-act="cancel" data-token="' + esc(b.token) + '">' + esc(T.cancel) + '</button>';
				if (b.series_lead) { btns += '<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-btn--danger" data-act="cancel-all" data-token="' + esc(b.token) + '">' + esc(T.cancelAll) + '</button>'; }
			}
			if (upcoming && !b.can_cancel && !b.can_move) { btns += '<span class="osb-portal__meta">' + esc(T.cantCancel) + '</span>'; }
			if (!upcoming) { btns += '<a class="osb-btn osb-btn--ghost osb-btn--sm" href="' + esc(this.cfg.bookUrl) + '">' + esc(T.bookAgain) + '</a>'; }
			btns += '<a class="osb-btn osb-btn--ghost osb-btn--sm" href="' + esc(b.manage_url) + '">' + esc(T.view) + '</a>';
			(b.invoices || []).forEach(function (inv) {
				btns += '<a class="osb-btn osb-btn--ghost osb-btn--sm" href="' + esc(inv.url) + '" target="_blank" rel="noopener" title="' + esc(inv.label) + '">' + esc(inv.label) + '</a>';
			});
			html += '<div class="osb-portal__btns">' + btns + '</div>';
		}
		return html + '</div></article>';
	};

	Portal.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('submit', function (e) {
			e.preventDefault();
			var form = e.target;
			var s = self.s;
			s.error = ''; s.notice = '';
			if (form.getAttribute('data-form') === 'start') {
				var email = (form.email.value || '').trim();
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { s.error = self.T.email; self.render(); return; }
				s.email = email; s.busy = true; self.render();
				self.api('/start', { method: 'POST', body: { email: email } }).then(function () {
					s.busy = false; s.view = 'sent'; self.render();
					var c = self.root.querySelector('[name="code"]'); if (c) { c.focus(); }
				}).catch(function (err) { s.busy = false; s.error = err.message; self.render(); });
			} else if (form.getAttribute('data-form') === 'code') {
				var code = (form.code.value || '').replace(/\D/g, '');
				s.busy = true; self.render();
				self.api('/code', { method: 'POST', body: { email: s.email, code: code } }).then(function (d) {
					s.busy = false; s.data = d; s.view = 'panel'; self.render();
				}).catch(function (err) { s.busy = false; s.error = err.message; self.render(); });
			}
		});
		this.root.addEventListener('click', function (e) {
			var el = e.target.closest('[data-act]');
			if (!el || !self.root.contains(el)) { return; }
			var act = el.getAttribute('data-act');
			var s = self.s;
			if (act === 'restart') { s.view = 'start'; s.error = ''; self.render(); }
			if (act === 'more') { s.showPast = true; self.render(); }
			if (act === 'logout') {
				s.busy = true; self.render();
				self.api('/logout', { method: 'POST' }).then(function () { s.busy = false; s.data = null; s.view = 'start'; self.render(); }).catch(function () { s.busy = false; s.view = 'start'; self.render(); });
			}
			if (act === 'cancel' || act === 'cancel-all') { s.confirm = { token: el.getAttribute('data-token'), all: act === 'cancel-all' }; s.error = ''; s.notice = ''; self.render(); }
			if (act === 'keep') { s.confirm = null; self.render(); }
			if (act === 'do-cancel' && s.confirm) {
				s.busy = true; self.render();
				self.api('/cancel', { method: 'POST', body: { token: s.confirm.token, all: s.confirm.all ? 1 : 0 } }).then(function (d) {
					s.busy = false; s.confirm = null; s.data = d; s.notice = self.T.cancelled; self.render();
				}).catch(function (err) {
					s.busy = false; s.confirm = null; s.error = err.message;
					if (err.status === 403) { s.view = 'start'; }
					self.render();
				});
			}
		});
	};

	function boot() {
		var nodes = document.querySelectorAll('[data-portal]');
		for (var i = 0; i < nodes.length; i++) {
			if (!nodes[i].osbPortal) { nodes[i].osbPortal = new Portal(nodes[i]); }
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
