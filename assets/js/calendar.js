/* OSAM Bookings — central calendar (Bookings → Calendar). */
(function ($) {
	'use strict';

	var cfg = window.OSB_CAL || {};
	var T = cfg.i18n || {};
	var STORE = 'osb_ccal_v1';

	function api(method, path, data) {
		return $.ajax({ url: cfg.rest + path, method: method, data: data, headers: { 'X-WP-Nonce': cfg.nonce } });
	}
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
	}
	function load() {
		try { return JSON.parse(window.localStorage.getItem(STORE)) || {}; } catch (e) { return {}; }
	}
	function save(state) {
		try { window.localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private window */ }
	}

	$(function () {
		var el = document.getElementById('osb-ccal');
		if (!el) { return; }
		if (!window.FullCalendar) {
			el.innerHTML = '<div class="osb-cal-offline">' + esc(T.offline) + '</div>';
			return;
		}

		var $wrap = $('#osb-ccal-wrap');
		var $panel = $('#osb-cpanel');
		var $body = $panel.find('.osb-cpanel__body');
		var $msg = $panel.find('.osb-cpanel__msg');
		var history = [];
		var current = null;
		var saved = load();

		// Restore the viewer's filters.
		$wrap.find('[data-ccal-type]').each(function () {
			if (saved.types && saved.types.indexOf(this.getAttribute('data-ccal-type')) === -1) { this.checked = false; }
		});
		$wrap.find('[data-ccal-layer]').each(function () {
			if (saved.layers && saved.layers.indexOf(this.getAttribute('data-ccal-layer')) === -1) { this.checked = false; }
		});
		if (saved.who && $('#osb-ccal-who option[value="' + saved.who + '"]').length) { $('#osb-ccal-who').val(saved.who); }
		$('#osb-ccal-cancelled').prop('checked', !!saved.cancelled);

		function params() {
			return {
				types: $wrap.find('[data-ccal-type]:checked').map(function () { return this.getAttribute('data-ccal-type'); }).get(),
				layers: $wrap.find('[data-ccal-layer]:checked').map(function () { return this.getAttribute('data-ccal-layer'); }).get(),
				who: $('#osb-ccal-who').val() || '',
				cancelled: $('#osb-ccal-cancelled').is(':checked') ? 1 : 0,
				q: $.trim($('#osb-ccal-q').val() || '')
			};
		}

		var cal = new FullCalendar.Calendar(el, {
			initialView: saved.view || (window.innerWidth < 782 ? 'listWeek' : 'timeGridWeek'),
			initialDate: saved.date || undefined,
			firstDay: parseInt(el.dataset.firstDay || '1', 10),
			locale: { code: (el.dataset.locale || 'en').toLowerCase() },
			buttonText: { today: T.today, month: T.month, week: T.week, day: T.day, list: T.list },
			allDayText: T.allDay,
			noEventsText: T.noEvents,
			moreLinkText: T.more,
			dayHeaderFormat: { weekday: 'short', day: 'numeric', month: 'short' },
			views: { dayGridMonth: { dayHeaderFormat: { weekday: 'short' } } },
			height: 'auto',
			nowIndicator: true,
			allDaySlot: true,
			dayMaxEvents: 4,
			slotMinTime: '06:00:00',
			slotMaxTime: '22:00:00',
			scrollTime: '08:00:00',
			expandRows: true,
			headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
			events: function (info, success, failure) {
				var p = params();
				$('.osb-ccal__search-note').prop('hidden', !p.q);
				api('GET', '/admin/cal/feed', {
					start: info.startStr, end: info.endStr, types: p.types.join(','), layers: p.layers.join(','),
					who: p.who, cancelled: p.cancelled, q: p.q
				}).done(success).fail(failure);
			},
			eventDidMount: function (arg) {
				var x = arg.event.extendedProps || {};
				var tip = arg.event.title + (x.staff ? ' — ' + x.staff : '') + (x.sub ? ' (' + x.sub + ')' : '');
				arg.el.setAttribute('title', tip);
				if (/osb-ev-unpaid/.test(arg.el.className)) {
					var t = arg.el.querySelector('.fc-event-title, .fc-list-event-title');
					if (t) { t.insertAdjacentHTML('afterbegin', '<span class="osb-ev-dot" aria-hidden="true"></span>'); }
				}
			},
			eventClick: function (info) {
				info.jsEvent.preventDefault();
				var x = info.event.extendedProps || {};
				history = [];
				if (x.info) { showInfo(x.info, x.kind); return; }
				open(x.kind, x.ref);
			},
			dateClick: function (info) {
				var d = info.dateStr.slice(0, 10);
				var t = info.dateStr.length > 10 ? info.dateStr.slice(11, 16) : '09:00';
				var who = $('#osb-ccal-who').val() || '';
				if (who.indexOf('resource:') === 0) {
					window.location.href = el.dataset.resNew + '&date=' + d + '&resource=' + who.slice(9) + '#osb-res-new';
					return;
				}
				window.location.href = el.dataset.new + '&date=' + d + '&time=' + t;
			},
			datesSet: function (info) {
				persist({ view: info.view.type, date: cal ? cal.getDate().toISOString().slice(0, 10) : undefined });
			}
		});
		cal.render();

		function persist(extra) {
			var p = params();
			var state = $.extend(load(), { types: p.types, layers: p.layers, who: p.who, cancelled: p.cancelled }, extra || {});
			save(state);
		}

		$wrap.on('change', '[data-ccal-type], [data-ccal-layer], #osb-ccal-who, #osb-ccal-cancelled', function () {
			persist();
			cal.refetchEvents();
		});
		var qTimer;
		$('#osb-ccal-q').on('input search', function () {
			clearTimeout(qTimer);
			qTimer = setTimeout(function () {
				var q = $.trim($('#osb-ccal-q').val() || '');
				if (q && cal.view.type !== 'listWeek' && cal.view.type !== 'dayGridMonth') { cal.changeView('dayGridMonth'); }
				cal.refetchEvents();
			}, 300);
		});

		/* ---------- detail panel ---------- */
		function openPanel() {
			$panel.addClass('is-open').attr('aria-hidden', 'false');
			$('body').addClass('osb-cpanel-open');
		}
		function closePanel() {
			$panel.removeClass('is-open').attr('aria-hidden', 'true');
			$('body').removeClass('osb-cpanel-open');
			current = null;
			history = [];
		}
		function setBack() { $panel.find('.osb-cpanel__back').prop('hidden', !history.length); }
		function flash(text, bad) {
			$msg.text(text || '').toggleClass('is-bad', !!bad);
			if (text) { setTimeout(function () { $msg.text(''); }, 4000); }
		}

		function open(kind, id, fromList) {
			if (fromList && current) { history.push(current); }
			current = { kind: kind, id: id };
			setBack();
			openPanel();
			$body.html('<p class="osb-cpanel__loading">' + esc(T.loading) + '</p>');
			api('GET', '/admin/cal/item', { kind: kind, id: id }).done(function (r) {
				$body.html(r.html);
			}).fail(function (x) {
				$body.html(x.responseJSON && x.responseJSON.html ? x.responseJSON.html : '<p class="osb-muted">' + esc(T.error) + '</p>');
			});
		}

		function showInfo(info, kind) {
			current = null;
			setBack();
			var html = '<div class="osb-cpanel__head"><span class="osb-cpanel__type osb-cpanel__type--' + esc(kind) + '">' + esc(info.title) + '</span></div>';
			html += '<dl class="osb-cpanel__dl">';
			(info.rows || []).forEach(function (r) { html += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; });
			html += '</dl>';
			if (info.note) { html += '<p class="osb-help">' + esc(info.note) + '</p>'; }
			if (info.link) { html += '<div class="osb-cpanel__actions"><a class="osb-btn osb-btn--ghost" href="' + esc(info.link[1]) + '">' + esc(info.link[0]) + ' →</a></div>'; }
			$body.html(html);
			openPanel();
		}

		$panel.on('click', '.osb-cpanel__close', closePanel);
		$panel.on('click', '.osb-cpanel__back', function () {
			var prev = history.pop();
			if (prev) { open(prev.kind, prev.id); setBack(); }
		});
		$(document).on('keydown', function (e) { if ('Escape' === e.key && $panel.hasClass('is-open')) { closePanel(); } });
		$panel.on('click', '[data-cal-open]', function () { open('booking', $(this).data('cal-open'), true); });

		$panel.on('click', '[data-cal-act]', function () {
			var $b = $(this);
			var act = $b.data('cal-act');
			var id = $b.closest('[data-booking]').data('booking');
			if ('cancelled' === act && !window.confirm(T.confirmCancel)) { return; }
			$panel.find('[data-cal-act]').prop('disabled', true);
			api('POST', '/admin/cal/action', { id: id, act: act, notify: $panel.find('.osb-cal-notify').is(':checked') ? 1 : 0 }).done(function (r) {
				$body.html(r.html);
				flash(r.message);
				cal.refetchEvents();
			}).fail(function (x) {
				flash((x.responseJSON && x.responseJSON.message) || T.error, true);
				$panel.find('[data-cal-act]').prop('disabled', false);
			});
		});
	});
})(jQuery);
