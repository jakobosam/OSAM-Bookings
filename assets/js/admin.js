/* OSAM Bookings — admin UI */
(function ($) {
	'use strict';

	var cfg = window.OSB_ADMIN || { i18n: {} };
	var T = cfg.i18n || {};

	/* ---------- helpers ---------- */
	function toast(msg) {
		var $t = $('.osb-toast');
		if (!$t.length) { $t = $('<div class="osb-toast" role="status"></div>').appendTo(document.body); }
		$t.text(msg).addClass('is-on');
		clearTimeout($t.data('t'));
		$t.data('t', setTimeout(function () { $t.removeClass('is-on'); }, 1600));
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		var $tmp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo(document.body);
		$tmp[0].select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		$tmp.remove();
		return $.Deferred().resolve().promise();
	}

	function api(path, data) {
		return $.ajax({ url: cfg.rest + path, data: data, headers: { 'X-WP-Nonce': cfg.nonce } });
	}

	function debounce(fn, ms) {
		var t;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(ctx, args); }, ms);
		};
	}

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	$(function () {
		/* ---------- colour pickers ---------- */
		$('.osb-color').each(function () {
			var $el = $(this);
			$el.wpColorPicker({
				change: function (e, ui) { setTimeout(function () { $el.val(ui.color.toString()).trigger('osb:color'); }, 0); },
				clear: function () { setTimeout(function () { $el.trigger('osb:color'); }, 0); }
			});
		});

		/* ---------- copy ---------- */
		$(document).on('click', '.osb-copy', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var $b = $(this);
			var text = $b.data('copyFrom') ? $($b.data('copyFrom')).text() : String($b.attr('data-copy'));
			$.when(copyText(text)).always(function () {
				toast((T.copied || 'Copied') + ': ' + text);
				$b.addClass('is-copied');
				setTimeout(function () { $b.removeClass('is-copied'); }, 1200);
			});
		});

		/* ---------- confirm deletes ---------- */
		$(document).on('click', '.osb-delete', function (e) {
			e.stopPropagation();
			if (!window.confirm(T.confirmDelete)) { e.preventDefault(); }
		});

		/* ---------- clickable rows ---------- */
		$(document).on('click', 'tr[data-href]', function (e) {
			if ($(e.target).closest('a, button, input, select, label, .osb-handle').length) { return; }
			window.location.href = $(this).data('href');
		});

		/* ---------- bulk bar ---------- */
		function syncBulk($form) {
			var n = $form.find('tbody input[name="ids[]"]:checked').length;
			$form.find('.osb-bulkbar').prop('hidden', !n).find('.osb-bulkbar__count').text((T.selected || '%d selected').replace('%d', n));
		}
		$(document).on('change', '.osb-check-all', function () {
			var $form = $(this).closest('form');
			$form.find('tbody input[name="ids[]"]').prop('checked', this.checked);
			syncBulk($form);
		});
		$(document).on('change', 'input[name="ids[]"]', function () { syncBulk($(this).closest('form')); });

		/* ---------- media picker ---------- */
		$(document).on('click', '.osb-image-pick', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.osb-image-field');
			var frame = wp.media({ title: T.chooseImage, multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
				$wrap.find('input').val(att.id).trigger('change');
				$wrap.find('img').attr('src', url).show();
				$wrap.find('.osb-image-clear').show();
			});
			frame.open();
		});
		$(document).on('click', '.osb-image-clear', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.osb-image-field');
			$wrap.find('input').val(0).trigger('change');
			$wrap.find('img').hide();
			$(this).hide();
		});

		/* ---------- repeaters ---------- */
		$(document).on('click', '.osb-row-add', function (e) {
			e.preventDefault();
			var $scope = $(this).closest('.osb-repeater-wrap, .osb-card, form');
			var $rep = $scope.find('.osb-repeater').first();
			var html = $('#' + $rep.data('template')).html().replace(/__i__/g, 'n' + Date.now());
			var $target = $rep.is('table') ? $rep.find('tbody') : $rep;
			var $row = $(html).appendTo($target);
			$scope.find('.osb-empty-row').prop('hidden', true);
			$row.find('input:visible').first().trigger('focus');
			$row.closest('form').trigger('osb:dirty');
		});
		$(document).on('click', '.osb-row-remove', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var $row = $(this).closest('tr, .osb-repeat-row, .osb-fieldrow');
			var $scope = $row.closest('.osb-repeater-wrap');
			var $form = $row.closest('form');
			$row.remove();
			if ($scope.length && !$scope.find('tbody tr, .osb-repeat-row').length) { $scope.find('.osb-empty-row').prop('hidden', false); }
			$form.trigger('osb:dirty');
		});

		/* ---------- form builder ---------- */
		$(document).on('click', '.osb-fieldrow__head', function (e) {
			if ($(e.target).closest('.osb-row-remove, .osb-handle').length) { return; }
			$(this).closest('.osb-fieldrow').toggleClass('is-open');
		});
		$(document).on('input', '.osb-field-label', function () {
			var $row = $(this).closest('.osb-fieldrow');
			$row.find('.osb-fieldrow__label').text($(this).val() || '…');
			var $key = $row.find('.osb-field-key');
			if ($key.prop('readonly') || $key.data('touched')) { return; }
			$key.val($(this).val().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40));
		});
		$(document).on('input', '.osb-field-key', function () { $(this).data('touched', true); });
		$(document).on('change', '.osb-field-type', function () {
			var $row = $(this).closest('.osb-fieldrow');
			$row.attr('data-type', $(this).val()).find('.osb-fieldrow__type').text($(this).find(':selected').text());
		});
		$('.osb-fieldlist').sortable({ handle: '.osb-handle', axis: 'y', update: function () { $(this).closest('form').trigger('osb:dirty'); } });

		/* ---------- drag sorting ---------- */
		function saveOrder($list, items) {
			var ids = items.map(function () { return $(this).data('id'); }).get();
			$.post(window.ajaxurl, { action: 'osb_sort', nonce: cfg.nonce, table: $list.data('table'), ids: ids });
		}
		$('.osb-sortable tbody').sortable({
			handle: '.osb-handle', axis: 'y',
			helper: function (e, tr) { var $o = tr.children(); var $h = tr.clone(); $h.children().each(function (i) { $(this).width($o.eq(i).outerWidth()); }); return $h; },
			update: function () { saveOrder($(this).closest('table'), $(this).find('tr')); }
		});
		$('.osb-sortable-grid').sortable({
			handle: '.osb-handle', items: '> .osb-person', placeholder: 'osb-person ui-sortable-placeholder',
			update: function () { saveOrder($(this), $(this).find('> .osb-person')); }
		});

		/* ---------- weekly schedule ---------- */
		function rangeHtml(day, idx, a, b, name) {
			name = name || 'schedule';
			return '<span class="osb-range"><input type="time" name="' + name + '[' + day + '][' + idx + '][0]" value="' + a + '"><i>–</i>' +
				'<input type="time" name="' + name + '[' + day + '][' + idx + '][1]" value="' + b + '">' +
				'<button type="button" class="osb-iconbtn osb-range-remove" aria-label="Remove"><span class="dashicons dashicons-no-alt"></span></button></span>';
		}
		$(document).on('click', '.osb-range-add', function (e) {
			e.preventDefault();
			var $day = $(this).closest('.osb-week__day');
			var $last = $day.find('.osb-range').last();
			var start = $last.length ? $last.find('input').eq(1).val() : '09:00';
			var h = Math.min(23, parseInt(start.split(':')[0], 10) + 1);
			var end = $last.length ? (h < 10 ? '0' : '') + h + ':00' : '17:00';
			if ($last.length) {
				var sh = Math.min(22, parseInt(start.split(':')[0], 10) + 1);
				start = (sh < 10 ? '0' : '') + sh + ':' + start.split(':')[1];
				end = (Math.min(23, sh + 4) < 10 ? '0' : '') + Math.min(23, sh + 4) + ':00';
			}
			$(this).before(rangeHtml($day.data('day'), 'n' + Date.now(), start, end, $day.closest('.osb-week').data('name')));
			$day.closest('form').trigger('osb:dirty');
		});
		$(document).on('click', '.osb-range-remove', function (e) {
			e.preventDefault();
			var $day = $(this).closest('.osb-week__day');
			$(this).closest('.osb-range').remove();
			if (!$day.find('.osb-range').length) {
				$day.addClass('is-off').find('.osb-day-toggle').prop('checked', false);
			}
			$day.closest('form').trigger('osb:dirty');
		});
		$(document).on('change', '.osb-day-toggle', function () {
			var $day = $(this).closest('.osb-week__day');
			if (this.checked) {
				$day.removeClass('is-off');
				if (!$day.find('.osb-range').length) {
					$day.find('.osb-range-add').before(rangeHtml($day.data('day'), 'n' + Date.now(), '09:00', '17:00', $day.closest('.osb-week').data('name')));
				}
			} else {
				$day.addClass('is-off').find('.osb-range').remove();
			}
		});
		$(document).on('click', '.osb-copy-monday', function (e) {
			e.preventDefault();
			var $week = $(this).closest('.osb-week-wrap, .osb-card, form').find('.osb-week').first();
			var ranges = $week.find('.osb-week__day[data-day="1"] .osb-range').map(function () {
				var $i = $(this).find('input');
				return [[$i.eq(0).val(), $i.eq(1).val()]];
			}).get();
			for (var d = 2; d <= 5; d++) {
				var $day = $week.find('.osb-week__day[data-day="' + d + '"]');
				$day.find('.osb-range').remove();
				var $btn = $day.find('.osb-range-add');
				ranges.forEach(function (r, i) { $btn.before(rangeHtml(d, i, r[0], r[1], $week.data('name'))); });
				$day.toggleClass('is-off', !ranges.length).find('.osb-day-toggle').prop('checked', !!ranges.length);
			}
			$week.closest('form').trigger('osb:dirty');
		});

		/* ---------- duration quick picks ---------- */
		$(document).on('click', '.osb-quickpick button', function () {
			var $qp = $(this).closest('.osb-quickpick');
			$('#' + $qp.data('target')).val($(this).data('v')).trigger('change');
			$qp.find('button').removeClass('is-active');
			$(this).addClass('is-active');
		});

		/* ---------- gateway / payment cards ---------- */
		$(document).on('change', '.osb-gateway__toggle', function () { $(this).closest('.osb-gateway').toggleClass('is-on', this.checked); });
		$(document).on('change', '.osb-paycard__head input[type=checkbox]', function () { $(this).closest('.osb-paycard').toggleClass('is-on', this.checked); });

		/* ---------- unsaved changes ---------- */
		var dirty = false;
		$('#osb-settings-form, .osb-admin form.osb-form').not('.osb-checkable').on('change input osb:dirty osb:color', function (e) {
			if ($(e.target).closest('.osb-preview').length) { return; }
			dirty = true;
			$(this).find('.osb-savebar__dirty').prop('hidden', false);
		}).on('submit', function () { dirty = false; });
		window.addEventListener('beforeunload', function (e) {
			if (dirty) { e.preventDefault(); e.returnValue = T.unsaved || ''; }
		});

		/* ---------- override warnings (manual booking + reschedule) ---------- */
		$('.osb-checkable').each(function () {
			var $form = $(this);
			var isManual = $form.hasClass('osb-manual');
			var $out = $form.find('.osb-live-warnings');
			var check = debounce(function () {
				var type = isManual ? $form.find('input[name=type]:checked').val() : $form.data('type');
				var data = {
					type: type,
					service_id: $form.find('[name=service_id]').val(),
					staff_id: $form.find('[name=staff_id]').val(),
					event_id: $form.find('[name=event_id]').val(),
					intake_id: $form.find('[name=intake_id]').val(),
					date: $form.find('[name=date]').val(),
					time: $form.find('[name=time]').val(),
					seats: $form.find('[name=seats]').val() || 1,
					exclude: $form.data('exclude') || 0
				};
				api('/admin/check', data).done(function (res) {
					if (!res.warnings || !res.warnings.length) {
						$out.html('<span class="osb-ok"><span class="dashicons dashicons-yes-alt"></span>' + esc(T.allClear) + '</span>');
						return;
					}
					$out.html('<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><strong>' + esc(T.warnTitle) + '</strong><ul>' +
						res.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div></div>');
				});
			}, 250);
			$form.on('change input', '[data-check], [name=seats]', check);
			if (isManual) { check(); }

			if (!isManual) { return; }
			var toggle = function () {
				var type = $form.find('input[name=type]:checked').val();
				$form.find('.osb-if-service').prop('hidden', type !== 'service');
				$form.find('.osb-if-event').prop('hidden', type !== 'event');
				$form.find('.osb-if-course').prop('hidden', type !== 'course');
			};
			$form.on('change', 'input[name=type]', toggle);
			toggle();

			var loadSlots = function () {
				var $hints = $('#osb_m_slots').html('<span class="osb-muted">…</span>');
				api('/admin/slots', { service_id: $('#osb_m_service').val(), staff_id: $('#osb_m_staff').val(), date: $('#osb_m_date').val() }).done(function (slots) {
					$hints.empty();
					if (!slots.length) { $hints.html('<span class="osb-muted">' + esc(T.noSlots) + '</span>'); return; }
					slots.forEach(function (s) {
						$('<button type="button"></button>').text(s.label).toggleClass('is-selected', s.time === $('#osb_m_time').val()).on('click', function () {
							$('#osb_m_time').val(s.time).trigger('change');
							$hints.find('.is-selected').removeClass('is-selected');
							$(this).addClass('is-selected');
						}).appendTo($hints);
					});
				});
			};
			$('#osb_m_service, #osb_m_staff, #osb_m_date').on('change', loadSlots);
			loadSlots();
		});

		/* ---------- calendar ---------- */
		var el = document.getElementById('osb-calendar');
		if (el) {
			if (!window.FullCalendar) {
				el.innerHTML = '<div class="osb-cal-offline">The calendar couldn’t load. Try reloading the page; if it keeps happening, a security or optimisation plugin may be blocking its script.</div>';
			} else {
				var cal = new FullCalendar.Calendar(el, {
					initialView: window.innerWidth < 782 ? 'listWeek' : 'timeGridWeek',
					firstDay: parseInt(el.dataset.firstDay || '1', 10),
					// A bare locale object makes FullCalendar format dates the site's way (e.g. "Mon 19 Oct"
					// for en-GB) without loading its translation files; button labels stay as set below.
					locale: { code: (el.dataset.locale || 'en').toLowerCase() },
					buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day', list: 'List' },
					dayHeaderFormat: { weekday: 'short', day: 'numeric', month: 'short' },
					height: 'auto',
					nowIndicator: true,
					allDaySlot: false,
					slotMinTime: '07:00:00',
					slotMaxTime: '21:00:00',
					headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
					events: function (info, success, failure) {
						api('/admin/calendar', { start: info.startStr, end: info.endStr, staff_id: $('#osb-cal-staff').val() || '' }).done(success).fail(failure);
					},
					eventClassNames: function (arg) {
						return /pending/.test(arg.event.extendedProps.status) ? ['osb-ev-pending'] : [];
					},
					eventDidMount: function (arg) {
						if (arg.event.extendedProps.staff) { arg.el.title = arg.event.title + ' (' + arg.event.extendedProps.staff + ')'; }
					},
					dateClick: function (info) {
						var d = info.dateStr.slice(0, 10);
						var t = info.dateStr.length > 10 ? info.dateStr.slice(11, 16) : '09:00';
						window.location.href = el.dataset.new + '&date=' + d + '&time=' + t;
					}
				});
				cal.render();
				$('#osb-cal-staff').on('change', function () { cal.refetchEvents(); });
			}
		}

		/* ---------- appearance live preview ---------- */
		var $designer = $('.osb-designer');
		if ($designer.length) {
			var $form = $designer.closest('form');
			var preview = function () { return $designer.find('.osb-booking').get(0); };
			var toggleMap = {
				show_images: 'images', show_descriptions: 'descriptions', show_duration: 'durations', show_prices: 'prices',
				show_category_filter: 'filter', show_progress: 'progress', show_summary: 'summary', show_seats_left: 'seats-left'
			};
			var fonts = { inherit: 'inherit', system: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif', serif: 'Georgia, "Times New Roman", serif' };
			var swapClass = function (node, prefix, value) {
				node.className = node.className.split(/\s+/).filter(function (c) { return c.indexOf(prefix) !== 0; }).join(' ') + ' ' + prefix + value;
			};
			var apply = function () {
				var node = preview();
				if (!node) { return; }
				var v = function (n) { return $form.find('[name="' + n + '"]').filter(function () { return this.type !== 'radio' || this.checked; }).last().val(); };
				var on = function (n) { return $form.find('input[type=checkbox][name="' + n + '"]').is(':checked'); };
				swapClass(node, 'osb-design-', v('design'));
				swapClass(node, 'osb-layout-', v('service_layout'));
				swapClass(node, 'osb-cols-', v('service_columns'));
				swapClass(node, 'osb-btn-', v('button_shape'));
				swapClass(node, 'osb-density-', v('density'));
				Object.keys(toggleMap).forEach(function (k) { node.classList.toggle('osb-hide-' + toggleMap[k], !on(k)); });
				node.style.setProperty('--osb-accent', v('primary_color') || '#161a40');
				node.style.setProperty('--osb-on-accent', v('on_accent_color') || '#ffffff');
				node.style.setProperty('--osb-text', v('text_color') || '#1f2933');
				node.style.setProperty('--osb-bg', v('background_color') || '#ffffff');
				node.style.setProperty('--osb-radius', (parseInt(v('border_radius'), 10) || 0) + 'px');
				node.style.setProperty('--osb-font-size', (parseInt(v('font_size'), 10) || 15) + 'px');
				node.style.fontFamily = fonts[v('font_family')] || 'inherit';
				var mw = parseInt(v('max_width'), 10) || 0;
				node.style.maxWidth = mw ? mw + 'px' : '';
				node.style.marginLeft = node.style.marginRight = mw ? 'auto' : '';
				$designer.find('.osb-swatchbtn').each(function () { $(this).toggleClass('is-active', $(this).data('color') === (v('primary_color') || '').toLowerCase()); });
			};
			var applyLabels = debounce(function () {
				if (!window.OSB_BOOKING || !preview() || !preview().osbBooking) { return; }
				$form.find('[name^="labels["]').each(function () {
					var key = this.name.slice(7, -1);
					window.OSB_BOOKING.labels[key] = this.value || this.placeholder;
				});
				preview().osbBooking.render();
			}, 200);
			$form.on('change input osb:color', apply);
			$form.on('input', '[name^="labels["]', applyLabels);
			$designer.on('click', '.osb-swatchbtn', function () {
				var c = $(this).data('color');
				$form.find('[name=primary_color]').wpColorPicker('color', c);
				apply();
			});
			$designer.on('click', '.osb-preview__sizes button', function () {
				$(this).addClass('is-active').siblings().removeClass('is-active');
				$designer.find('.osb-preview__frame').css('max-width', $(this).data('w'));
			});
			apply();
		}

		/* ---------- shortcode builder ---------- */
		var $builder = $('.osb-builder');
		if ($builder.length) {
			var defaults = $builder.data('defaults') || {};
			var $code = $('#osb-built');
			var $stage = $('#osb-builder-preview');
			var base = $stage.data('baseConfig') || {};
			var build = debounce(function () {
				var type = $builder.find('input[name=b_type]:checked').val();
				var atts = [];
				var services = $builder.find('input[name=b_service]:checked').map(function () { return this.value; }).get();
				var events = $builder.find('input[name=b_event]:checked').map(function () { return this.value; }).get();
				var courses = $builder.find('input[name=b_course]:checked').map(function () { return this.value; }).get();
				var cat = $builder.find('[name=b_category]').val() || '';
				var staff = $builder.find('[name=b_staff]').val() || '';
				$builder.find('.osb-b-services').prop('hidden', type !== 'all' && type !== 'services');
				$builder.find('.osb-b-events').prop('hidden', type !== 'all' && type !== 'events');
				$builder.find('.osb-b-courses').prop('hidden', type !== 'all' && type !== 'courses');
				if (type !== 'all') { atts.push('type="' + type + '"'); }
				if (type === 'all' || type === 'services') {
					if (services.length) { atts.push('service="' + services.join(',') + '"'); } else if (cat) { atts.push('category="' + cat.replace(/"/g, '') + '"'); }
					if (staff) { atts.push('staff="' + staff + '"'); }
				}
				if ((type === 'all' || type === 'events') && events.length) { atts.push('event="' + events.join(',') + '"'); }
				if ((type === 'all' || type === 'courses') && courses.length) { atts.push('course="' + courses.join(',') + '"'); }
				var display = {};
				$builder.find('select[data-attr]').each(function () {
					var a = $(this).data('attr');
					display[a] = this.value || defaults[a];
					if (this.value) { atts.push(a + '="' + this.value + '"'); }
				});
				var accent = $('#b_accent_on').is(':checked') ? $('#b_accent').val() : '';
				if (accent) { atts.push('accent="' + accent + '"'); }
				$code.text('[osam_booking' + (atts.length ? ' ' + atts.join(' ') : '') + ']');

				// Rebuild the preview form.
				var mode = type;
				if (mode === 'all') {
					var given = [];
					if (services.length || cat) { given.push('services'); }
					if (events.length) { given.push('events'); }
					if (courses.length) { given.push('courses'); }
					if (given.length === 1) { mode = given[0]; }
				}
				var svc = type === 'all' || type === 'services';
				var config = $.extend({}, base, {
					mode: mode,
					services: svc ? services.map(Number) : [],
					events: type === 'all' || type === 'events' ? events.map(Number) : [],
					courses: type === 'all' || type === 'courses' ? courses.map(Number) : [],
					staff: svc ? Number(staff || 0) : 0,
					category: !svc || services.length ? '' : cat
				});
				var classes = ['osb-booking', 'osb-design-' + display.design, 'osb-layout-' + display.layout, 'osb-cols-' + display.columns, 'osb-btn-' + display.buttons, 'osb-density-' + display.density];
				['images', 'descriptions', 'durations', 'prices', 'filter', 'progress', 'summary', 'seats_left'].forEach(function (a) {
					if (display[a] === 'no') { classes.push('osb-hide-' + a.replace('_', '-')); }
				});
				var $node = $('<div></div>').attr('class', classes.join(' ')).attr('id', 'osb-builder-form').attr('data-config', JSON.stringify(config));
				if (accent) { $node[0].style.setProperty('--osb-accent', accent); }
				$stage.empty().append($node);
				if (window.OSBBooking) { window.OSBBooking.boot(); }
			}, 150);
			$builder.on('change input', 'input, select', build);
			build();
		}

		/* ---------- email designer ---------- */
		var $ed = $('#osb-email-design');
		if ($ed.length) {
			var $eform = $ed.closest('form');
			var $frame = $ed.find('.osb-emailpreview__frame');
			var $pick = $ed.find('.osb-emailpreview__pick');
			var $subj = $ed.find('.osb-emailpreview__subject strong');
			var req = null;
			var renderEmail = debounce(function () {
				var key = $pick.val();
				var design = {};
				$eform.find('[name^="email_"]').each(function () {
					if ((this.type === 'radio' || this.type === 'checkbox') && !this.checked) {
						if (this.type === 'checkbox' && !(this.name in design)) { design[this.name] = '0'; }
						return;
					}
					design[this.name] = this.value;
				});
				var data = { template: key, design: design };
				var $tpl = $eform.find('.osb-email-tpl[data-key="' + key + '"]');
				if ($tpl.length) {
					data.subject = $tpl.find('[name="emails[' + key + '][subject]"]').val();
					data.body = $tpl.find('[name="emails[' + key + '][body]"]').val();
				}
				if (req && req.abort) { req.abort(); }
				$ed.addClass('is-loading');
				req = $.ajax({ url: cfg.rest + '/admin/email-preview', method: 'POST', contentType: 'application/json', data: JSON.stringify(data), headers: { 'X-WP-Nonce': cfg.nonce } }).done(function (res) {
					$subj.text(res.subject || '');
					var doc = $frame[0].contentDocument;
					doc.open(); doc.write(res.html); doc.close();
					var fit = function () { try { $frame.height(Math.max(300, doc.documentElement.scrollHeight)); } catch (e) { /* ignore */ } };
					fit();
					$(doc).find('img').on('load', fit);
				}).always(function () { $ed.removeClass('is-loading'); });
			}, 250);
			$eform.on('change input osb:color', '[name^="email_"], .osb-email-tpl textarea, .osb-email-tpl input', renderEmail);
			$eform.on('osb:color', renderEmail);
			$pick.on('change', renderEmail);
			$ed.on('input', '.osb-range', function () {
				$(this).closest('.osb-field').find('.osb-range-out').text(this.value + ($(this).data('unit') || ''));
			});
			$ed.on('click', '.osb-preview__sizes button', function () {
				$(this).addClass('is-active').siblings().removeClass('is-active');
				$frame.css('max-width', $(this).data('w'));
				renderEmail();
			});
			var setColor = function (name, val) {
				var $in = $eform.find('[name="' + name + '"]');
				if (!$in.length || !val) { return; }
				if ($in.hasClass('wp-color-picker')) { $in.wpColorPicker('color', val); } else { $in.val(val); }
			};
			$ed.on('click', '.osb-emailtheme', function () {
				var vals = $(this).data('theme');
				if ($(this).data('themeSite')) {
					var $c = $ed.find('.osb-emailcolors');
					var acc = $c.data('siteAccent');
					vals = { email_accent: acc, email_header_bg: acc, email_header_text: '#ffffff', email_button_text: '#ffffff', email_text: $c.data('siteText') };
				}
				$.each(vals || {}, setColor);
				$(this).addClass('is-active').siblings().removeClass('is-active');
				$eform.trigger('osb:dirty');
				renderEmail();
			});
			$(document).on('click', '[data-osb-email-preview]', function () {
				$pick.val($(this).data('osbEmailPreview'));
				renderEmail();
				$('html, body').animate({ scrollTop: $ed.offset().top - 50 }, 250);
			});
			$(document).on('click', '[data-osb-email-reset]', function () {
				var $t = $(this).closest('.osb-email-tpl');
				var key = $t.data('key');
				$t.find('[name="emails[' + key + '][subject]"]').val($t.attr('data-default-subject'));
				$t.find('[name="emails[' + key + '][body]"]').val($t.attr('data-default-body')).trigger('change');
				$eform.trigger('osb:dirty');
				$pick.val(key);
				renderEmail();
			});
			renderEmail();
		}

		/* ---------- codes: which items ---------- */
		$(document).on('change', '.osb-applies', function () {
			var v = this.value;
			$('.osb-applies__items').each(function () { $(this).prop('hidden', $(this).data('for') !== v); });
		});

		/* ---------- form fields: "ask this for" summary ---------- */
		$(document).on('change', '.osb-showfor input', function () {
			var $d = $(this).closest('.osb-showfor');
			var names = $d.find('input:checked').map(function () { return $(this).next('span').text(); }).get();
			$d.find('.osb-showfor__sum').text(names.length ? names.join(', ') : $d.find('.osb-showfor__sum').data('all'));
		});

		/* ---------- confirm links ---------- */
		$(document).on('click', '[data-osb-confirm]', function (e) {
			if (!window.confirm($(this).attr('data-osb-confirm'))) { e.preventDefault(); }
		});

		/* ---------- course schedule builder ---------- */
		var $sessions = $('.osb-sessions');
		if ($sessions.length) {
			var $body = $sessions.find('tbody');
			var tpl = $('#osb-session-template').html() || '';
			var next = $body.find('tr').length;
			var dayMs = 86400000;

			var parseDate = function (s) {
				var p = String(s || '').split('-');
				return p.length === 3 ? new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])) : null;
			};
			var fmtDate = function (d) {
				return d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2);
			};
			var dayName = function (s) {
				var d = parseDate(s);
				if (!d) { return ''; }
				try { return d.toLocaleDateString(document.documentElement.lang || undefined, { weekday: 'short', timeZone: 'UTC' }); } catch (e) { return ''; }
			};
			var renumber = function () {
				// Keep rows in date order so the numbering matches what customers see.
				var rows = $body.find('tr').get().sort(function (a, b) {
					var ka = ($(a).find('.osb-session__date').val() || '9999') + ($(a).find('.osb-session__start').val() || '');
					var kb = ($(b).find('.osb-session__date').val() || '9999') + ($(b).find('.osb-session__start').val() || '');
					return ka < kb ? -1 : (ka > kb ? 1 : 0);
				});
				$body.append(rows);
				$body.find('tr').each(function (i) {
					var date = $(this).find('.osb-session__date').val();
					$(this).find('.osb-session__num').html('<b>' + (i + 1) + '</b><small>' + esc(dayName(date)) + '</small>');
				});
				$('.osb-sessions__empty').prop('hidden', $body.find('tr').length > 0);
			};
			var addRow = function (vals) {
				var $row = $(tpl.replace(/__i__/g, 'n' + (next++)));
				vals = vals || {};
				var $last = $body.find('tr').last();
				// New rows copy the previous row's times, location and tutor; date moves on a week.
				if (!vals.date && $last.length) {
					var d = parseDate($last.find('.osb-session__date').val());
					vals.date = d ? fmtDate(new Date(d.getTime() + 7 * dayMs)) : '';
					vals.start = vals.start || $last.find('.osb-session__start').val();
					vals.end = vals.end || $last.find('.osb-session__end').val();
					vals.location = $last.find('[name$="[location]"]').val();
					vals.staff = $last.find('.osb-session__staff').val();
				}
				if (vals.date) { $row.find('.osb-session__date').val(vals.date); }
				if (vals.start) { $row.find('.osb-session__start').val(vals.start); }
				if (vals.end) { $row.find('.osb-session__end').val(vals.end); }
				if (vals.location) { $row.find('[name$="[location]"]').val(vals.location); }
				if (vals.staff != null && vals.staff !== '') { $row.find('.osb-session__staff').val(vals.staff); }
				$body.append($row);
				return $row;
			};

			$(document).on('click', '[data-osb-session-add]', function () {
				var $row = addRow();
				renumber();
				$row.find('.osb-session__date').trigger('focus');
				$sessions.closest('form').trigger('osb:dirty');
			});
			$sessions.on('click', '.osb-session__remove', function () {
				var $tr = $(this).closest('tr');
				if (Number($tr.find('[name$="[id]"]').val()) > 0 && !window.confirm(T.removeSession)) { return; }
				$tr.remove();
				renumber();
				$sessions.closest('form').trigger('osb:dirty');
			});
			$sessions.on('change', '.osb-session__date, .osb-session__start', renumber);
			$sessions.on('change', '.osb-session__start', function () {
				// Keep the length when the start moves past the end.
				var $tr = $(this).closest('tr');
				var $end = $tr.find('.osb-session__end');
				if (!$end.val() || $end.val() <= this.value) {
					var p = this.value.split(':');
					var h = Math.min(23, (+p[0] || 0) + 1);
					$end.val(('0' + h).slice(-2) + ':' + (p[1] || '00'));
				}
			});

			$(document).on('click', '[data-osb-repeat]', function () {
				var replace = $(this).data('osbRepeat') === 'replace';
				var first = parseDate($('#osb_r_date').val());
				var count = Math.max(1, Math.min(200, parseInt($('#osb_r_count').val(), 10) || 1));
				var every = $('#osb_r_every').val();
				if (!first) { $('#osb_r_date').trigger('focus'); return; }
				if (replace && !window.confirm(T.replaceAll)) { return; }
				if (replace) { $body.empty(); }
				var dates = [];
				var d = first;
				var guard = 0;
				while (dates.length < count && guard++ < 2000) {
					if (every === 'wd') {
						var wd = d.getUTCDay();
						if (wd !== 0 && wd !== 6) { dates.push(fmtDate(d)); }
						d = new Date(d.getTime() + dayMs);
					} else if (every === 'm') {
						var m = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + dates.length, 1));
						var last = new Date(Date.UTC(m.getUTCFullYear(), m.getUTCMonth() + 1, 0)).getUTCDate();
						m.setUTCDate(Math.min(first.getUTCDate(), last));
						dates.push(fmtDate(m));
					} else {
						dates.push(fmtDate(d));
						d = new Date(d.getTime() + (parseInt(every, 10) || 7) * dayMs);
					}
				}
				var staff = $body.find('.osb-session__staff').first().val();
				dates.forEach(function (date) {
					addRow({ date: date, start: $('#osb_r_start').val(), end: $('#osb_r_end').val(), staff: staff });
				});
				renumber();
				$(this).closest('details').prop('open', false);
				$sessions.closest('form').trigger('osb:dirty');
			});

			$(document).on('click', '[data-osb-session-shift]', function () {
				var days = parseInt($('#osb_shift_days').val(), 10) || 0;
				if (!days) { return; }
				$body.find('.osb-session__date').each(function () {
					var d = parseDate(this.value);
					if (d) { this.value = fmtDate(new Date(d.getTime() + days * dayMs)); }
				});
				renumber();
				$sessions.closest('form').trigger('osb:dirty');
			});
			renumber();
		}

		/* ---------- course register ---------- */
		var $register = $('.osb-register');
		if ($register.length) {
			var order = ['booked', 'attended', 'absent'];
			var labels = T.attendance || {};
			var setMark = function ($btn, value) {
				$btn.attr('data-value', value).removeClass('osb-mark--booked osb-mark--attended osb-mark--absent').addClass('osb-mark--' + value);
				$btn.siblings('input').val(value);
				var aria = String($btn.attr('aria-label') || '').replace(/: [^:]*$/, '');
				$btn.attr('aria-label', aria + ': ' + (labels[value] || value));
			};
			var regDirty = false;
			$register.on('click', '.osb-mark', function () {
				var cur = $(this).attr('data-value');
				setMark($(this), order[(order.indexOf(cur) + 1) % order.length]);
				regDirty = true;
			});
			$register.on('click', '.osb-register__col', function () {
				var col = $(this).data('col');
				$register.find('td[data-col="' + col + '"] .osb-mark').each(function () { setMark($(this), 'attended'); });
				regDirty = true;
			});
			$register.closest('form').on('submit', function () { regDirty = false; });
			window.addEventListener('beforeunload', function (e) {
				if (regDirty) { e.preventDefault(); e.returnValue = T.unsaved || ''; }
			});
		}
	});
})(jQuery);

/* Features tab: highlight a tile as soon as it's switched. */
jQuery( function ( $ ) {
	$( document ).on( 'change', '[data-osb-feature]', function () {
		$( this ).closest( '.osb-feature' ).toggleClass( 'is-on', this.checked );
	} );
} );

/* Online delivery: show the link box / help for the chosen mode. */
jQuery( function ( $ ) {
	$( document ).on( 'change', '[data-osb-online-mode]', function () {
		var mode = this.value;
		$( this ).closest( '[data-osb-online]' ).find( '[data-osb-online-help]' ).each( function () {
			var show = ( ' ' + $( this ).attr( 'data-osb-online-help' ) + ' ' ).indexOf( ' ' + mode + ' ' ) !== -1;
			$( this ).prop( 'hidden', ! show || ! mode );
		} );
	} );
} );

/* Import: show the chosen file name; drag-and-drop highlight. */
jQuery( function ( $ ) {
	$( document ).on( 'change', '.osb-dropfile input[type=file]', function () {
		var name = this.files && this.files[0] ? this.files[0].name : '';
		var $box = $( this ).closest( '.osb-dropfile' ).toggleClass( 'has-file', !! name );
		if ( name ) { $box.find( 'strong' ).text( name ); $box.find( 'small' ).text( '' ); }
	} );
	$( document ).on( 'dragenter dragover', '.osb-dropfile', function () { $( this ).addClass( 'is-over' ); } );
	$( document ).on( 'dragleave drop', '.osb-dropfile', function () { $( this ).removeClass( 'is-over' ); } );
} );

/* ---------- custom emails editor ---------- */
jQuery( function ( $ ) {
	var $f = $( '.osb-ce-form' );
	if ( ! $f.length ) { return; }
	var cfg = window.OSB_ADMIN || {};
	var timed = [ 'before_start', 'after_end', 'after_booked' ];

	function data() {
		var out = {};
		$.each( $f.serializeArray(), function ( i, p ) {
			var n = p.name;
			if ( n === 'action' || n === '_wpnonce' || n === '_wp_http_referer' ) { return; }
			if ( n.slice( -2 ) === '[]' ) {
				n = n.slice( 0, -2 );
				( out[ n ] = out[ n ] || [] ).push( p.value );
			} else {
				out[ n ] = p.value; // Hidden "0" then checkbox "1": the last one wins.
			}
		} );
		return out;
	}

	function sync() {
		var t = $f.find( '.osb-ce-trigger' ).val() || '';
		var on = [ t ];
		if ( timed.indexOf( t ) > -1 ) { on.push( 'timed' ); }
		if ( t.indexOf( 'replace:' ) === 0 ) { on.push( 'replace' ); }
		$f.find( '[data-osb-show]' ).each( function () {
			var want = String( $( this ).data( 'osbShow' ) ).split( ' ' );
			var show = want.some( function ( w ) { return on.indexOf( w ) > -1; } );
			$( this ).prop( 'hidden', ! show );
		} );
		var n = parseInt( $f.find( '[name=offset]' ).val(), 10 ) || 0;
		var u = $f.find( '[name=unit]' ).val();
		var units = { minutes: [ 'minute', 'minutes' ], hours: [ 'hour', 'hours' ], days: [ 'day', 'days' ], weeks: [ 'week', 'weeks' ] }[ u ] || [ '', '' ];
		var amount = n + ' ' + ( n === 1 ? units[ 0 ] : units[ 1 ] );
		var words = { before_start: amount + ' before the booking starts', after_end: amount + ' after the booking ends', after_booked: amount + ' after the booking was made' }[ t ] || '';
		$f.find( '.osb-ce-when-text' ).text( words );
		$f.find( '.osb-ce-reply-email' ).prop( 'hidden', $f.find( '.osb-ce-reply' ).val() !== 'other' );
	}

	var timer, req;
	var $frame = $f.find( '.osb-ce-preview__frame' );
	function preview() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			if ( req && req.abort ) { req.abort(); }
			$f.find( '.osb-ce-preview' ).addClass( 'is-loading' );
			req = $.ajax( { url: cfg.rest + '/admin/custom-email-preview', method: 'POST', contentType: 'application/json', data: JSON.stringify( data() ), headers: { 'X-WP-Nonce': cfg.nonce } } ).done( function ( res ) {
				$f.find( '.osb-emailpreview__subject strong' ).text( res.subject || '' );
				$f.find( '.osb-ce-preview__sample' ).text( res.sample || '' );
				var doc = $frame[ 0 ].contentDocument;
				doc.open(); doc.write( res.html ); doc.close();
				var fit = function () { try { $frame.height( Math.max( 320, doc.documentElement.scrollHeight ) ); } catch ( e ) { /* ignore */ } };
				fit();
				$( doc ).find( 'img' ).on( 'load', fit );
			} ).always( function () { $f.find( '.osb-ce-preview' ).removeClass( 'is-loading' ); } );
		}, 300 );
	}

	$f.on( 'change input', ':input', function ( e ) {
		if ( $( e.target ).closest( '.osb-ce-test' ).length ) { return; }
		sync();
		preview();
	} );
	$f.on( 'click', '.osb-preview__sizes button', function () {
		$( this ).addClass( 'is-active' ).siblings().removeClass( 'is-active' );
		$frame.css( 'max-width', $( this ).data( 'w' ) );
		preview();
	} );
	$f.on( 'click', '[data-osb-ce-test]', function () {
		var $b = $( this ), $msg = $f.find( '.osb-ce-test__msg' );
		var d = data();
		d.test_to = $f.find( '.osb-ce-test__to' ).val();
		$b.prop( 'disabled', true );
		$msg.removeClass( 'is-error' ).text( '…' );
		$.ajax( { url: cfg.rest + '/admin/custom-email-test', method: 'POST', contentType: 'application/json', data: JSON.stringify( d ), headers: { 'X-WP-Nonce': cfg.nonce } } ).done( function ( res ) {
			$msg.toggleClass( 'is-error', ! res.ok ).text( res.message || '' );
		} ).fail( function ( x ) {
			$msg.addClass( 'is-error' ).text( ( x.responseJSON && x.responseJSON.message ) || 'Error' );
		} ).always( function () { $b.prop( 'disabled', false ); } );
	} );
	// Ticking "All appointments" etc. dims the single items in that group.
	$f.on( 'change', '.osb-showfor__group input', function () {
		var $g = $( this ).closest( '.osb-showfor__group' );
		$g.toggleClass( 'has-all', $g.find( '.is-all input' ).is( ':checked' ) );
	} );
	$f.find( '.osb-showfor__group' ).each( function () { $( this ).toggleClass( 'has-all', $( this ).find( '.is-all input' ).is( ':checked' ) ); } );

	sync();
	preview();
} );
