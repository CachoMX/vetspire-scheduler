/**
 * Vetspire Scheduler front-end widget.
 * Vanilla JS, no dependencies. Talks only to the WP REST proxy
 * (never to the Vetspire API directly).
 *
 * Layouts: full (default), bar (Thrive-style strip), calendar (Chewy-style
 * month picker), float (VCA-style "first available" card). All layouts share
 * the same booking modal and analytics events.
 */
(function () {
	'use strict';

	var CFG = window.vspsConfig || { restUrl: '/wp-json/vetspire/v1', analytics: 1 };

	var I18N_DEFAULTS = {
		loading: 'Loading available times…',
		noOptions: 'Online booking is not available right now. Please call the clinic.',
		loadFailed: 'Could not load booking options. Please call the clinic.',
		timesFailed: 'Could not load times. Please try again later.',
		noTimes: 'No online times available in the next %d days. Please call the clinic.',
		apptType: 'Appointment type',
		open: 'open',
		today: 'Today',
		tomorrow: 'Tomorrow',
		firstName: 'First name',
		lastName: 'Last name',
		email: 'Email',
		phone: 'Phone',
		petName: 'Pet name',
		dog: 'Dog',
		cat: 'Cat',
		other: 'Other',
		reason: 'Reason for visit (optional)',
		cancel: 'Cancel',
		confirm: 'Confirm Booking',
		booking: 'Booking…',
		booked: "✅ You're booked!",
		confirmationTo: 'A confirmation will be sent to your email. See you soon!',
		close: 'Close',
		bookingFailed: 'Booking failed. Please try another time or call the clinic.',
		at: 'at',
		todaysAvailability: "Today's Availability",
		availability: 'Availability',
		viewAll: 'View All',
		bookOnline: 'Book Online',
		firstAvailable: 'Book First Available Appointment',
		moreAppointments: 'More available appointments »',
		showingTimesFor: 'Showing available times for',
		back: '‹ Back',
		currentlyViewing: 'Currently Viewing',
		hoursTitle: 'Hours',
		website: 'Visit Website',
		reviews: 'Google Reviews',
		directions: 'Get Directions',
		callUs: 'Call Us',
		breed: 'Breed (optional)',
		sexLabel: 'Sex (optional)',
		male: 'Male',
		female: 'Female',
		ageYears: 'Age in years (optional)',
		neuteredQ: 'Spayed / Neutered? (optional)',
		yes: 'Yes',
		no: 'No'
	};
	var I18N = {};
	(function () {
		var src = CFG.i18n || {};
		Object.keys(I18N_DEFAULTS).forEach(function (k) {
			I18N[k] = src[k] || I18N_DEFAULTS[k];
		});
	})();

	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
		'August', 'September', 'October', 'November', 'December'];
	var DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

	/* ---------- analytics ---------- */

	function track(eventName, payload) {
		var data = payload || {};
		try {
			document.dispatchEvent(new CustomEvent('vetspire:' + eventName, { detail: data }));
		} catch (e) { /* older browsers */ }
		if (CFG.analytics) {
			window.dataLayer = window.dataLayer || [];
			var entry = { event: 'vsps_' + eventName };
			Object.keys(data).forEach(function (k) { entry['vsps_' + k] = data[k]; });
			window.dataLayer.push(entry);
		}
	}

	/* ---------- helpers ---------- */

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function escHtml(str) {
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	function fetchJson(url, options) {
		options = options || {};
		if (CFG.nonce) {
			options.headers = options.headers || {};
			options.headers['X-WP-Nonce'] = CFG.nonce;
		}
		return window.fetch(url, options).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) {
					var err = new Error((json && json.message) || 'Request failed');
					err.status = res.status;
					throw err;
				}
				return json;
			});
		});
	}

	function dateFromIso(iso) {
		return new Date(iso + 'T12:00:00');
	}

	function sameDay(a, b) {
		return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}

	function formatDateLabel(iso) {
		var d = dateFromIso(iso);
		var today = new Date();
		var tomorrow = new Date(today.getTime() + 86400000);
		if (sameDay(d, today)) { return I18N.today; }
		if (sameDay(d, tomorrow)) { return I18N.tomorrow; }
		return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
	}

	function formatShortDate(iso) {
		var d = dateFromIso(iso);
		return (d.getMonth() + 1) + '/' + d.getDate() + '/' + d.getFullYear();
	}

	function formatLongDate(iso) {
		return dateFromIso(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function formatTime(hhmm) {
		var parts = hhmm.split(':');
		var h = parseInt(parts[0], 10);
		var suffix = h >= 12 ? 'PM' : 'AM';
		var h12 = h % 12 === 0 ? 12 : h % 12;
		return h12 + ':' + parts[1] + ' ' + suffix;
	}

	/* ---------- widget ---------- */

	function Widget(root) {
		this.root = root;
		try {
			this.config = JSON.parse(root.getAttribute('data-vsps-config'));
		} catch (e) {
			return;
		}
		this.layout = this.config.layout || 'full';
		this.body = root.querySelector('.vsps-body');
		this.state = { types: [], typeId: null, days: [], selectedDate: null, calMonth: null };
		this.root.classList.add('vsps-layout-' + this.layout);
		this.init();
	}

	Widget.prototype.init = function () {
		var self = this;
		if (!this.config._embedded) {
			track('widget_view', { location_id: this.config.locationId, layout: this.layout });
		}
		fetchJson(CFG.restUrl + '/types?location_id=' + this.config.locationId)
			.then(function (data) {
				var types = data.types || [];
				if (self.config.typeIds && self.config.typeIds.length) {
					types = types.filter(function (t) {
						return self.config.typeIds.indexOf(parseInt(t.id, 10)) !== -1;
					});
				}
				if (!types.length) {
					self.showMessage(I18N.noOptions);
					return;
				}
				// Admin-chosen primary type goes first (bar/float book it; the
				// dropdown in full/calendar preselects it).
				if (self.config.defaultTypeId) {
					var primary = types.filter(function (t) {
						return parseInt(t.id, 10) === parseInt(self.config.defaultTypeId, 10);
					})[0];
					if (primary) {
						types = [primary].concat(types.filter(function (t) { return t !== primary; }));
					}
				}
				self.state.types = types;
				self.state.typeId = types[0].id;
				self.renderShell();
				self.loadAvailability();
				self.loadLocationInfo();
			})
			.catch(function () {
				self.showMessage(I18N.loadFailed);
			});
	};

	Widget.prototype.loadLocationInfo = function () {
		var self = this;
		if (this.config._embedded) { return; }
		fetchJson(CFG.restUrl + '/location-info?location_id=' + this.config.locationId)
			.then(function (info) {
				self.locInfo = info;
				self.applyLocationInfo();
			})
			.catch(function () { /* optional enrichment; stay silent */ });
	};

	Widget.prototype.locationChip = function () {
		var st = this.locInfo && this.locInfo.status;
		if (!st) { return null; }
		return el('span', 'vsps-chip ' + (st.open ? 'vsps-chip-open' : 'vsps-chip-closed'), st.label);
	};

	Widget.prototype.applyLocationInfo = function () {
		var self = this;
		if (!this.locInfo) { return; }
		if ('bar' === this.layout) {
			if (this.barLabel) { this.fillBarLabel(this.barLabel); }
			return;
		}
		if ('float' === this.layout) { return; }
		// full / calendar: one compact line under the title.
		if (this.locLine) { return; }
		var line = el('div', 'vsps-locline');
		var nameBtn = el('button', 'vsps-locline-name', '');
		nameBtn.type = 'button';
		nameBtn.appendChild(el('span', null, '\ud83d\udccd ' + this.locInfo.name));
		nameBtn.appendChild(el('span', 'vsps-locline-arrow', '\u203a'));
		nameBtn.addEventListener('click', function () { self.openDrawer(); });
		line.appendChild(nameBtn);
		var chip = this.locationChip();
		if (chip) { line.appendChild(chip); }
		this.locLine = line;
		this.body.insertBefore(line, this.body.firstChild ? this.body.firstChild.nextSibling : null);
	};

	Widget.prototype.fillBarLabel = function (label) {
		var self = this;
		label.innerHTML = '';
		if (this.locInfo) {
			label.appendChild(el('span', 'vsps-bar-viewing', I18N.currentlyViewing));
			var nameBtn = el('button', 'vsps-bar-name', '');
			nameBtn.type = 'button';
			nameBtn.appendChild(el('span', null, this.locInfo.name));
			nameBtn.appendChild(el('span', 'vsps-locline-arrow', '\u203a'));
			nameBtn.addEventListener('click', function () { self.openDrawer(); });
			label.appendChild(nameBtn);
			var chip = this.locationChip();
			if (chip) { label.appendChild(chip); }
		} else {
			label.appendChild(el('span', 'vsps-bar-title', this.barIsToday ? I18N.todaysAvailability : I18N.availability));
			if (!this.barIsToday && this.barDate) {
				label.appendChild(el('span', 'vsps-bar-date', formatDateLabel(this.barDate)));
			}
		}
	};

	/** Thrive-style slide-in drawer with map, contact info and hours. */
	Widget.prototype.openDrawer = function () {
		var self = this;
		var info = this.locInfo;
		if (!info) { return; }
		track('location_details_open', { location_id: this.config.locationId });

		var overlay = el('div', 'vsps-overlay vsps-drawer-overlay');
		var drawer = el('aside', 'vsps-drawer');
		overlay.appendChild(drawer);
		try {
			var primary = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary');
			if (primary) { overlay.style.setProperty('--vsps-primary', primary.trim()); }
		} catch (e) { /* non-blocking */ }

		function onKeydown(e) { if (e.key === 'Escape') { close(); } }
		function close() {
			document.removeEventListener('keydown', onKeydown);
			overlay.remove();
		}
		document.addEventListener('keydown', onKeydown);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

		var closeBtn = el('button', 'vsps-modal-close', '\u00d7');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', I18N.close);
		closeBtn.addEventListener('click', close);
		drawer.appendChild(closeBtn);

		// Map (keyless Google embed; lat/long when present, address otherwise).
		var query = (info.latitude && info.longitude)
			? info.latitude + ',' + info.longitude
			: (info.address || info.name).replace(/\n/g, ', ');
		if (query) {
			var map = document.createElement('iframe');
			map.className = 'vsps-drawer-map';
			map.setAttribute('loading', 'lazy');
			map.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
			map.src = 'https://maps.google.com/maps?q=' + encodeURIComponent(query) + '&z=14&output=embed';
			drawer.appendChild(map);
		}

		drawer.appendChild(el('h4', 'vsps-drawer-name', info.name));
		if (info.address) {
			var addr = el('p', 'vsps-drawer-address', info.address);
			drawer.appendChild(addr);
		}
		var chip = this.locationChip();
		if (chip) { drawer.appendChild(chip); }

		if (info.weekly && info.weekly.length) {
			drawer.appendChild(el('h5', 'vsps-drawer-hours-title', I18N.hoursTitle));
			var table = el('table', 'vsps-drawer-hours');
			var todayName = new Date().toLocaleDateString('en-US', { weekday: 'long' });
			info.weekly.forEach(function (row) {
				var tr = el('tr', row[0] === todayName ? 'is-today' : null);
				tr.appendChild(el('td', null, row[0]));
				tr.appendChild(el('td', null, row[1]));
				table.appendChild(tr);
			});
			drawer.appendChild(table);
		}

		var book = el('button', 'vsps-btn-primary vsps-drawer-book', I18N.bookOnline);
		book.type = 'button';
		book.addEventListener('click', function () {
			close();
			self.openFullModal();
		});
		drawer.appendChild(book);

		if (info.phone) {
			var call = el('a', 'vsps-drawer-call', I18N.callUs + ' ' + info.phone);
			call.href = 'tel:' + info.phone.replace(/[^0-9+]/g, '');
			drawer.appendChild(call);
		}

		var links = el('p', 'vsps-drawer-links', '');
		function addLink(href, text) {
			var a = el('a', null, text);
			a.href = href;
			a.target = '_blank';
			a.rel = 'noopener';
			links.appendChild(a);
		}
		if (info.address) {
			addLink('https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(info.address.replace(/\n/g, ', ')), I18N.directions);
		}
		if (info.website) { addLink(info.website, I18N.website); }
		if (info.googleLink) { addLink(info.googleLink, I18N.reviews); }
		if (links.childNodes.length) { drawer.appendChild(links); }

		document.body.appendChild(overlay);
	};

	Widget.prototype.showMessage = function (text) {
		this.body.innerHTML = '';
		this.body.appendChild(el('p', 'vsps-message', text));
	};

	/** bar/float use the first type and hide the selector to stay compact. */
	Widget.prototype.usesTypeSelect = function () {
		return ('full' === this.layout || 'calendar' === this.layout) && this.state.types.length > 1;
	};

	Widget.prototype.renderShell = function () {
		var self = this;
		this.body.innerHTML = '';

		if (this.usesTypeSelect()) {
			var select = el('select', 'vsps-type-select');
			this.state.types.forEach(function (t) {
				var opt = el('option', null, t.name);
				opt.value = t.id;
				select.appendChild(opt);
			});
			select.addEventListener('change', function () {
				self.state.typeId = select.value;
				self.loadAvailability();
			});
			var typeWrap = el('div', 'vsps-type-wrap');
			typeWrap.appendChild(el('label', 'vsps-label', I18N.apptType));
			typeWrap.appendChild(select);
			this.body.appendChild(typeWrap);
		} else if ('full' === this.layout || 'calendar' === this.layout) {
			this.body.appendChild(el('p', 'vsps-single-type', this.state.types[0].name));
		}

		this.contentEl = el('div', 'vsps-content');
		this.body.appendChild(this.contentEl);
	};

	Widget.prototype.currentType = function () {
		var id = this.state.typeId;
		return this.state.types.filter(function (t) { return t.id === id; })[0];
	};

	Widget.prototype.loadAvailability = function () {
		var self = this;
		// Race guard: only the latest request may update state (type can be
		// switched while a slower fetch is still in flight).
		var requestId = (this.lastRequestId = (this.lastRequestId || 0) + 1);
		this.contentEl.innerHTML = '';
		this.contentEl.appendChild(el('p', 'vsps-loading', I18N.loading));

		var days = 'calendar' === this.layout ? 14 : this.config.days;
		var url = CFG.restUrl + '/availability?location_id=' + this.config.locationId +
			'&appointment_type_id=' + this.state.typeId + '&days=' + days;

		fetchJson(url).then(function (data) {
			if (requestId !== self.lastRequestId) { return; }
			self.state.days = data.days || [];
			var firstWithSlots = null;
			self.state.days.forEach(function (d) {
				if (!firstWithSlots && d.slots.length) { firstWithSlots = d.date; }
			});
			if (!firstWithSlots) {
				self.contentEl.innerHTML = '';
				self.contentEl.appendChild(el('p', 'vsps-message', I18N.noTimes.replace('%d', String(days))));
				return;
			}
			self.state.selectedDate = firstWithSlots;
			self.state.calMonth = null;
			self.renderLayout();
		}).catch(function () {
			if (requestId !== self.lastRequestId) { return; }
			self.contentEl.innerHTML = '';
			self.contentEl.appendChild(el('p', 'vsps-message', I18N.timesFailed));
		});
	};

	Widget.prototype.renderLayout = function () {
		this.contentEl.innerHTML = '';
		if ('bar' === this.layout) {
			this.renderBar();
		} else if ('calendar' === this.layout) {
			this.renderCalendar();
		} else if ('float' === this.layout) {
			this.renderFloat();
		} else {
			this.renderDates();
			this.renderSlots();
		}
	};

	/**
	 * Opens the FULL picker in a lightbox (View All / Book Online / More).
	 * Choosing a slot closes the picker and opens the booking form modal.
	 */
	Widget.prototype.openFullModal = function () {
		var self = this;
		var overlay = el('div', 'vsps-overlay');
		var modal = el('div', 'vsps-modal vsps-modal-wide');
		overlay.appendChild(modal);
		try {
			var primary = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary');
			if (primary) { overlay.style.setProperty('--vsps-primary', primary.trim()); }
		} catch (e) { /* non-blocking */ }

		function onKeydown(e) { if (e.key === 'Escape') { close(); } }
		function close() {
			document.removeEventListener('keydown', onKeydown);
			overlay.remove();
		}
		document.addEventListener('keydown', onKeydown);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

		var closeBtn = el('button', 'vsps-modal-close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', I18N.close);
		closeBtn.addEventListener('click', close);
		modal.appendChild(closeBtn);

		var titleEl = this.root.querySelector('.vsps-title');
		var inner = el('div', 'vsps-widget vsps-embedded');
		// The .vsps-widget class defines a default --vsps-primary, which would
		// override the overlay's inherited value — set it inline like the
		// shortcode does so the brand color survives into the modal.
		try {
			var brandColor = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary');
			if (brandColor) { inner.style.setProperty('--vsps-primary', brandColor.trim()); }
		} catch (e) { /* non-blocking */ }
		var cfg = {};
		Object.keys(this.config).forEach(function (k) { cfg[k] = self.config[k]; });
		cfg.layout = 'full';
		cfg._embedded = true;
		inner.setAttribute('data-vsps-config', JSON.stringify(cfg));
		inner.innerHTML = '<h3 class="vsps-title"></h3><div class="vsps-body"><p class="vsps-loading"></p></div>';
		inner.querySelector('.vsps-title').textContent = titleEl ? titleEl.textContent : 'Book an Appointment';
		inner.querySelector('.vsps-loading').textContent = I18N.loading;
		modal.appendChild(inner);
		document.body.appendChild(overlay);

		var embedded = new Widget(inner);
		// When a slot is picked inside the lightbox, close it before the form opens.
		embedded.onBeforeForm = close;
	};

	Widget.prototype.day = function (iso) {
		return this.state.days.filter(function (d) { return d.date === iso; })[0];
	};

	Widget.prototype.slotButton = function (dayIso, slot, className) {
		var self = this;
		var btn = el('button', className || 'vsps-slot-btn', formatTime(slot.time));
		btn.type = 'button';
		if (slot.provider && slot.provider.name) { btn.title = slot.provider.name; }
		btn.addEventListener('click', function () {
			track('slot_selected', {
				location_id: self.config.locationId,
				appointment_type_id: self.state.typeId,
				date: dayIso,
				time: slot.time,
				layout: self.layout
			});
			if (self.config.mode === 'link' && self.config.linkUrl) {
				window.location.href = self.config.linkUrl;
				return;
			}
			self.openForm(dayIso, slot);
		});
		return btn;
	};

	/* ---------- layout: full ---------- */

	Widget.prototype.renderDates = function () {
		var self = this;
		var datesEl = el('div', 'vsps-dates');
		this.state.days.forEach(function (d) {
			var btn = el('button', 'vsps-date-btn', '');
			btn.type = 'button';
			btn.appendChild(el('span', 'vsps-date-label', formatDateLabel(d.date)));
			btn.appendChild(el('span', 'vsps-date-count', d.slots.length ? d.slots.length + ' ' + I18N.open : '—'));
			if (!d.slots.length) { btn.disabled = true; }
			if (d.date === self.state.selectedDate) { btn.classList.add('is-active'); }
			btn.addEventListener('click', function () {
				self.state.selectedDate = d.date;
				self.renderLayout();
			});
			datesEl.appendChild(btn);
		});
		this.contentEl.appendChild(datesEl);
	};

	Widget.prototype.renderSlots = function () {
		var self = this;
		var day = this.day(this.state.selectedDate);
		if (!day) { return; }
		var grid = el('div', 'vsps-slot-grid');
		day.slots.forEach(function (slot) {
			grid.appendChild(self.slotButton(day.date, slot));
		});
		this.contentEl.appendChild(grid);
	};

	/* ---------- layout: bar (Thrive-style) ---------- */

	Widget.prototype.renderBar = function () {
		var self = this;
		var day = this.day(this.state.selectedDate);
		var isToday = sameDay(dateFromIso(day.date), new Date());
		var bar = el('div', 'vsps-bar');

		var label = el('div', 'vsps-bar-label');
		this.barLabel = label;
		this.barIsToday = isToday;
		this.barDate = day.date;
		this.fillBarLabel(label);
		bar.appendChild(label);

		var chips = el('div', 'vsps-bar-chips');
		day.slots.slice(0, 5).forEach(function (slot) {
			chips.appendChild(self.slotButton(day.date, slot, 'vsps-bar-chip'));
		});
		var viewAll = el('button', 'vsps-bar-viewall', I18N.viewAll);
		viewAll.type = 'button';
		viewAll.addEventListener('click', function () { self.openFullModal(); });
		chips.appendChild(viewAll);
		bar.appendChild(chips);

		var cta = el('button', 'vsps-bar-cta', I18N.bookOnline);
		cta.type = 'button';
		cta.addEventListener('click', function () { self.openFullModal(); });
		bar.appendChild(cta);

		this.contentEl.appendChild(bar);
	};

	/* ---------- layout: calendar (Chewy-style) ---------- */

	Widget.prototype.renderCalendar = function () {
		var self = this;
		var selected = dateFromIso(this.state.selectedDate);
		if (null === this.state.calMonth) {
			this.state.calMonth = { y: selected.getFullYear(), m: selected.getMonth() };
		}
		var y = this.state.calMonth.y;
		var m = this.state.calMonth.m;

		var wrap = el('div', 'vsps-cal');

		// Header: month name + prev/next (bounded by the fetched date range).
		var head = el('div', 'vsps-cal-head');
		head.appendChild(el('span', 'vsps-cal-month', MONTHS[m] + ' ' + y));
		var nav = el('span', 'vsps-cal-nav');
		var months = {};
		this.state.days.forEach(function (d) {
			var dd = dateFromIso(d.date);
			months[dd.getFullYear() + '-' + dd.getMonth()] = true;
		});
		var prev = el('button', 'vsps-cal-arrow', '‹');
		var next = el('button', 'vsps-cal-arrow', '›');
		prev.type = 'button';
		next.type = 'button';
		prev.disabled = !months[(m === 0 ? (y - 1) + '-11' : y + '-' + (m - 1))];
		next.disabled = !months[(m === 11 ? (y + 1) + '-0' : y + '-' + (m + 1))];
		prev.addEventListener('click', function () {
			self.state.calMonth = m === 0 ? { y: y - 1, m: 11 } : { y: y, m: m - 1 };
			self.renderLayout();
		});
		next.addEventListener('click', function () {
			self.state.calMonth = m === 11 ? { y: y + 1, m: 0 } : { y: y, m: m + 1 };
			self.renderLayout();
		});
		nav.appendChild(prev);
		nav.appendChild(next);
		head.appendChild(nav);
		wrap.appendChild(head);

		// Grid.
		var grid = el('div', 'vsps-cal-grid');
		DOW.forEach(function (d) { grid.appendChild(el('span', 'vsps-cal-dow', d)); });
		var firstDow = new Date(y, m, 1).getDay();
		for (var i = 0; i < firstDow; i++) { grid.appendChild(el('span', 'vsps-cal-pad', '')); }
		var daysInMonth = new Date(y, m + 1, 0).getDate();
		var byDate = {};
		this.state.days.forEach(function (d) { byDate[d.date] = d; });
		for (var n = 1; n <= daysInMonth; n++) {
			var iso = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(n).padStart(2, '0');
			var entry = byDate[iso];
			var cell = el('button', 'vsps-cal-day', String(n));
			cell.type = 'button';
			if (!entry || !entry.slots.length) {
				cell.disabled = true;
			} else {
				if (iso === this.state.selectedDate) { cell.classList.add('is-active'); }
				(function (isoCopy) {
					cell.addEventListener('click', function () {
						self.state.selectedDate = isoCopy;
						self.renderLayout();
					});
				})(iso);
			}
			grid.appendChild(cell);
		}
		wrap.appendChild(grid);

		wrap.appendChild(el('p', 'vsps-cal-caption', I18N.showingTimesFor + ' ' + formatLongDate(this.state.selectedDate)));

		var day = this.day(this.state.selectedDate);
		var chips = el('div', 'vsps-cal-slots');
		if (day) {
			day.slots.forEach(function (slot) {
				chips.appendChild(self.slotButton(day.date, slot, 'vsps-cal-chip'));
			});
		}
		wrap.appendChild(chips);
		this.contentEl.appendChild(wrap);
	};

	/* ---------- layout: float (VCA-style) ---------- */

	Widget.prototype.renderFloat = function () {
		var self = this;
		var card = el('div', 'vsps-float');
		card.appendChild(el('h4', 'vsps-float-title', I18N.firstAvailable));

		var firsts = [];
		this.state.days.forEach(function (d) {
			d.slots.forEach(function (slot) {
				if (firsts.length < 3) { firsts.push({ date: d.date, slot: slot }); }
			});
		});

		var row = el('div', 'vsps-float-row');
		firsts.forEach(function (f) {
			var btn = el('button', 'vsps-float-slot', '');
			btn.type = 'button';
			btn.appendChild(el('span', 'vsps-float-time', formatTime(f.slot.time).toLowerCase()));
			btn.appendChild(el('span', 'vsps-float-date', formatShortDate(f.date)));
			btn.addEventListener('click', function () {
				track('slot_selected', {
					location_id: self.config.locationId,
					appointment_type_id: self.state.typeId,
					date: f.date,
					time: f.slot.time,
					layout: 'float'
				});
				if (self.config.mode === 'link' && self.config.linkUrl) {
					window.location.href = self.config.linkUrl;
					return;
				}
				self.openForm(f.date, f.slot);
			});
			row.appendChild(btn);
		});
		card.appendChild(row);

		var more = el('button', 'vsps-float-more', I18N.moreAppointments);
		more.type = 'button';
		more.addEventListener('click', function () { self.openFullModal(); });
		card.appendChild(more);

		this.contentEl.appendChild(card);
	};

	/* ---------- booking form ---------- */

	Widget.prototype.openForm = function (date, slot) {
		var self = this;
		if (this.onBeforeForm) { this.onBeforeForm(); }
		var type = this.currentType();
		var overlay = el('div', 'vsps-overlay');
		var modal = el('div', 'vsps-modal');
		overlay.appendChild(modal);
		try {
			var primary = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary');
			if (primary) { overlay.style.setProperty('--vsps-primary', primary.trim()); }
		} catch (e) { /* non-blocking */ }

		var heading = el('h4', 'vsps-modal-title', type.name);
		var sub = el('p', 'vsps-modal-sub', formatDateLabel(date) + ' ' + I18N.at + ' ' + formatTime(slot.time) +
			(slot.provider && slot.provider.name ? ' · ' + slot.provider.name : ''));
		modal.appendChild(heading);
		modal.appendChild(sub);

		var form = el('form', 'vsps-form');
		form.innerHTML =
			'<div class="vsps-row"><input required name="given_name" placeholder="__FIRST__" autocomplete="given-name">' +
			'<input required name="family_name" placeholder="__LAST__" autocomplete="family-name"></div>' +
			'<div class="vsps-row"><input required type="email" name="email" placeholder="__EMAIL__" autocomplete="email">' +
			'<input required type="tel" name="phone" placeholder="__PHONE__" autocomplete="tel"></div>' +
			'<div class="vsps-row"><input required name="pet_name" placeholder="__PET__">' +
			'<select name="species"><option value="Canine">__DOG__</option><option value="Feline">__CAT__</option><option value="Other">__OTHER__</option></select></div>' +
			(self.config.extendedPet ?
				'<div class="vsps-row"><input name="breed" placeholder="__BREED__">' +
				'<select name="sex"><option value="">__SEXLABEL__</option><option value="MALE">__MALE__</option><option value="FEMALE">__FEMALE__</option></select></div>' +
				'<div class="vsps-row"><input type="number" name="age" min="0" max="40" placeholder="__AGE__">' +
				'<select name="neutered"><option value="">__NEUTERED__</option><option value="yes">__YES__</option><option value="no">__NO__</option></select></div>'
			: '') +
			'<textarea name="notes" placeholder="__REASON__" rows="2"></textarea>' +
			'<input type="text" name="vsps_hp" tabindex="-1" autocomplete="nope-937" aria-hidden="true" style="position:absolute;left:-9999px;">' +
			'<p class="vsps-error" style="display:none;"></p>' +
			'<div class="vsps-actions">' +
			'<button type="button" class="vsps-btn-secondary">__CANCEL__</button>' +
			'<button type="submit" class="vsps-btn-primary">__CONFIRM__</button></div>';
		form.innerHTML = form.innerHTML
			.replace('__FIRST__', escAttr(I18N.firstName)).replace('__LAST__', escAttr(I18N.lastName))
			.replace('__EMAIL__', escAttr(I18N.email)).replace('__PHONE__', escAttr(I18N.phone))
			.replace('__PET__', escAttr(I18N.petName)).replace('__REASON__', escAttr(I18N.reason))
			.replace('__DOG__', escHtml(I18N.dog)).replace('__CAT__', escHtml(I18N.cat)).replace('__OTHER__', escHtml(I18N.other))
			.replace('__BREED__', escAttr(I18N.breed)).replace('__SEXLABEL__', escHtml(I18N.sexLabel))
			.replace('__MALE__', escHtml(I18N.male)).replace('__FEMALE__', escHtml(I18N.female))
			.replace('__AGE__', escAttr(I18N.ageYears)).replace('__NEUTERED__', escHtml(I18N.neuteredQ))
			.replace('__YES__', escHtml(I18N.yes)).replace('__NO__', escHtml(I18N.no))
			.replace('__CANCEL__', escHtml(I18N.cancel)).replace('__CONFIRM__', escHtml(I18N.confirm));
		modal.appendChild(form);

		function onKeydown(e) { if (e.key === 'Escape') { close(); } }
		function close() {
			document.removeEventListener('keydown', onKeydown);
			overlay.remove();
		}
		document.addEventListener('keydown', onKeydown);
		form.querySelector('.vsps-btn-secondary').addEventListener('click', close);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var errorEl = form.querySelector('.vsps-error');
			var submitBtn = form.querySelector('.vsps-btn-primary');
			errorEl.style.display = 'none';
			submitBtn.disabled = true;
			submitBtn.textContent = I18N.booking;

			var fd = new FormData(form);
			var payload = {
				location_id: self.config.locationId,
				appointment_type_id: parseInt(self.state.typeId, 10),
				date: date,
				time: slot.time,
				provider_id: slot.providerId || '',
				schedule_id: slot.scheduleId || '',
				duration: type.duration,
				notes: fd.get('notes') || '',
				vsps_hp: fd.get('vsps_hp') || '',
				client: {
					given_name: fd.get('given_name'),
					family_name: fd.get('family_name'),
					email: fd.get('email'),
					phone: fd.get('phone')
				},
				patient: {
					name: fd.get('pet_name'),
					species: fd.get('species'),
					breed: fd.get('breed') || '',
					sex: fd.get('sex') || '',
					age: fd.get('age') || '',
					neutered: fd.get('neutered') || ''
				}
			};

			track('booking_submitted', {
				location_id: self.config.locationId,
				appointment_type_id: self.state.typeId,
				date: date,
				time: slot.time
			});

			fetchJson(CFG.restUrl + '/book', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (data) {
				track('booking_completed', {
					location_id: self.config.locationId,
					appointment_type_id: self.state.typeId,
					appointment_id: data.appointment_id,
					date: date,
					time: slot.time
				});
				modal.innerHTML = '';
				modal.appendChild(el('h4', 'vsps-modal-title', I18N.booked));
				modal.appendChild(el('p', 'vsps-modal-sub', type.name + ' — ' + formatDateLabel(date) + ' ' + I18N.at + ' ' + formatTime(slot.time)));
				modal.appendChild(el('p', 'vsps-message', I18N.confirmationTo));
				var closeBtn = el('button', 'vsps-btn-primary', I18N.close);
				closeBtn.type = 'button';
				closeBtn.addEventListener('click', close);
				modal.appendChild(closeBtn);
				self.loadAvailability();
			}).catch(function (err) {
				track('booking_failed', {
					location_id: self.config.locationId,
					status: err.status || 0
				});
				errorEl.textContent = err.message || I18N.bookingFailed;
				errorEl.style.display = 'block';
				submitBtn.disabled = false;
				submitBtn.textContent = I18N.confirm;
			});
		});

		document.body.appendChild(overlay);
		form.querySelector('[name="given_name"]').focus();
	};

	/* ---------- boot ---------- */

	function boot() {
		var widgets = document.querySelectorAll('.vsps-widget[data-vsps-config]');
		Array.prototype.forEach.call(widgets, function (root) {
			if (root.getAttribute('data-vsps-noinit')) { return; }
			new Widget(root);
		});
	}

	// Exposed for the admin preview (re-init after layout/location change).
	window.vspsInitWidget = function (root) { return new Widget(root); };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
