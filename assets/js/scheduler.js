/**
 * Vetspire Scheduler front-end widget.
 * Vanilla JS, no dependencies. Talks only to the WP REST proxy
 * (never to the Vetspire API directly).
 *
 * Layouts: full (default), bar (horizontal strip), calendar (month
 * picker), float (compact "first available" card). All layouts share
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
		no: 'No',
		haveVisited: 'Have you visited us before?',
		returningClient: "Yes \u2014 I'm a returning client",
		newClient: "No \u2014 I'm a new client",
		emailAtClinic: 'Email you use at the clinic',
		continueBtn: 'Continue',
		notFoundEmail: "We couldn't find that email \u2014 let's book you as a new client.",
		lookupFailed: 'Lookup is unavailable right now \u2014 you can continue as a new client.',
		whosVisit: 'Who is this visit for?',
		aNewPet: '+ A new pet',
		bookingFor: 'Booking for'
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
		// The horizontal bar doesn't work on small screens: fall back to the
		// compact floating card below tablet width (decided at load time).
		if ('bar' === this.layout && window.innerWidth < 640) {
			this.layout = 'float';
		}
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

	/** Slide-in drawer with map, contact info and hours. */
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

	/* ---------- layout: bar (horizontal strip) ---------- */

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

	/* ---------- layout: calendar (month picker) ---------- */

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

	/* ---------- layout: float (compact card) ---------- */

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

	/* ---------- booking modal (new vs existing client) ---------- */

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
		closeBtn.addEventListener('click', function () { close(); });
		modal.appendChild(closeBtn);
		modal.appendChild(el('h4', 'vsps-modal-title', type.name));
		modal.appendChild(el('p', 'vsps-modal-sub', formatDateLabel(date) + ' ' + I18N.at + ' ' + formatTime(slot.time) +
			(slot.provider && slot.provider.name ? ' \u00b7 ' + slot.provider.name : '')));
		var step = el('div', 'vsps-step');
		modal.appendChild(step);

		this._bk = { date: date, slot: slot, type: type, modal: modal, step: step, close: close, clientType: 'new' };
		document.body.appendChild(overlay);
		track('form_started', {
			location_id: this.config.locationId,
			appointment_type_id: this.state.typeId,
			date: date,
			time: slot.time,
			layout: this.layout
		});
		this.renderChoiceStep();
	};

	Widget.prototype.renderChoiceStep = function () {
		var self = this;
		var step = this._bk.step;
		step.innerHTML = '';
		step.appendChild(el('p', 'vsps-step-q', I18N.haveVisited));
		var ret = el('button', 'vsps-btn-primary vsps-btn-block', I18N.returningClient);
		ret.type = 'button';
		ret.addEventListener('click', function () {
			self._bk.clientType = 'existing';
			self.renderEmailStep();
		});
		var fresh = el('button', 'vsps-btn-secondary vsps-btn-block', I18N.newClient);
		fresh.type = 'button';
		fresh.addEventListener('click', function () {
			self._bk.clientType = 'new';
			self.renderNewForm('');
		});
		step.appendChild(ret);
		step.appendChild(fresh);
	};

	Widget.prototype.backLink = function (handler) {
		var a = el('button', 'vsps-back', I18N.back);
		a.type = 'button';
		a.addEventListener('click', handler);
		return a;
	};

	Widget.prototype.renderEmailStep = function () {
		var self = this;
		var step = this._bk.step;
		step.innerHTML = '';
		var form = el('form', 'vsps-form');
		form.innerHTML = '<input required type="email" name="lookup_email" placeholder="__EMAIL__" autocomplete="email">' +
			'<p class="vsps-error" style="display:none;"></p>' +
			'<div class="vsps-actions"><button type="submit" class="vsps-btn-primary">__CONTINUE__</button></div>';
		form.innerHTML = form.innerHTML
			.replace('__EMAIL__', escAttr(I18N.emailAtClinic))
			.replace('__CONTINUE__', escHtml(I18N.continueBtn));
		step.appendChild(form);
		step.appendChild(this.backLink(function () { self.renderChoiceStep(); }));
		if (this._bk.email) { form.querySelector('[name="lookup_email"]').value = this._bk.email; }

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var email = form.querySelector('[name="lookup_email"]').value.trim();
			var errorEl = form.querySelector('.vsps-error');
			var btn = form.querySelector('.vsps-btn-primary');
			errorEl.style.display = 'none';
			btn.disabled = true;
			btn.textContent = I18N.loading;
			fetchJson(CFG.restUrl + '/lookup', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ location_id: self.config.locationId, email: email, vsps_hp: '' })
			}).then(function (data) {
				if (!data.found) {
					self._bk.clientType = 'new';
					self.renderNewForm(email, I18N.notFoundEmail);
					return;
				}
				self._bk.email = email;
				if (data.pets && data.pets.length) {
					self.renderPetStep(data.pets);
				} else {
					// Returning client with no pets on file: use the full form
					// (its dedupe reuses the account; no email-only record writes).
					self._bk.clientType = 'new';
					self.renderNewForm(email, '');
				}
			}).catch(function (err) {
				errorEl.textContent = err.message || I18N.lookupFailed;
				errorEl.style.display = 'block';
				btn.disabled = false;
				btn.textContent = I18N.continueBtn;
			});
		});
		form.querySelector('[name="lookup_email"]').focus();
	};

	Widget.prototype.renderPetStep = function (pets) {
		var self = this;
		var step = this._bk.step;
		step.innerHTML = '';
		step.appendChild(el('p', 'vsps-step-q', I18N.whosVisit));
		var list = el('div', 'vsps-pet-list');
		pets.forEach(function (name) {
			var chip = el('button', 'vsps-pet-chip', '\ud83d\udc3e ' + name);
			chip.type = 'button';
			chip.addEventListener('click', function () { self.renderConfirmStep(name); });
			list.appendChild(chip);
		});
		var np = el('button', 'vsps-pet-chip vsps-pet-new', I18N.aNewPet);
		np.type = 'button';
		np.addEventListener('click', function () {
			// New pet on an existing account goes through the full form (with
			// contact details + honeypot) — pending sign-off for a lighter path.
			self._bk.clientType = 'new';
			self.renderNewForm(self._bk.email, '');
		});
		list.appendChild(np);
		step.appendChild(list);
		step.appendChild(this.backLink(function () { self.renderEmailStep(); }));
	};

	Widget.prototype.renderConfirmStep = function (petName) {
		var self = this;
		var step = this._bk.step;
		step.innerHTML = '';
		step.appendChild(el('p', 'vsps-step-q', I18N.bookingFor + ': \ud83d\udc3e ' + petName));
		var form = el('form', 'vsps-form');
		form.innerHTML = '<textarea name="notes" placeholder="__REASON__" rows="2"></textarea>' +
			'<p class="vsps-error" style="display:none;"></p>' +
			'<div class="vsps-actions"><button type="submit" class="vsps-btn-primary">__CONFIRM__</button></div>';
		form.innerHTML = form.innerHTML
			.replace('__REASON__', escAttr(I18N.reason))
			.replace('__CONFIRM__', escHtml(I18N.confirm));
		step.appendChild(form);
		step.appendChild(this.backLink(function () {
			self.renderEmailStep();
		}));
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.submitBooking(form, {
				client_type: 'existing',
				pet_is_new: false,
				client: { given_name: '', family_name: '', email: self._bk.email, phone: '' },
				patient: { name: petName, species: '', breed: '', sex: '', age: '', neutered: '' },
				notes: form.querySelector('[name="notes"]').value || ''
			});
		});
	};

	Widget.prototype.petFieldsHtml = function () {
		var html = '<div class="vsps-row"><input required name="pet_name" placeholder="__PET__">' +
			'<select name="species"><option value="Canine">__DOG__</option><option value="Feline">__CAT__</option><option value="Other">__OTHER__</option></select></div>' +
			(this.config.extendedPet ?
				'<div class="vsps-row"><input name="breed" placeholder="__BREED__">' +
				'<select name="sex"><option value="">__SEXLABEL__</option><option value="MALE">__MALE__</option><option value="FEMALE">__FEMALE__</option></select></div>' +
				'<div class="vsps-row"><input type="number" name="age" min="0" max="40" placeholder="__AGE__">' +
				'<select name="neutered"><option value="">__NEUTERED__</option><option value="yes">__YES__</option><option value="no">__NO__</option></select></div>'
			: '');
		return html
			.replace('__PET__', escAttr(I18N.petName))
			.replace('__DOG__', escHtml(I18N.dog)).replace('__CAT__', escHtml(I18N.cat)).replace('__OTHER__', escHtml(I18N.other))
			.replace('__BREED__', escAttr(I18N.breed)).replace('__SEXLABEL__', escHtml(I18N.sexLabel))
			.replace('__MALE__', escHtml(I18N.male)).replace('__FEMALE__', escHtml(I18N.female))
			.replace('__AGE__', escAttr(I18N.ageYears)).replace('__NEUTERED__', escHtml(I18N.neuteredQ))
			.replace('__YES__', escHtml(I18N.yes)).replace('__NO__', escHtml(I18N.no));
	};

	Widget.prototype.renderNewForm = function (prefillEmail, notice) {
		var self = this;
		var step = this._bk.step;
		step.innerHTML = '';
		if (notice) {
			step.appendChild(el('p', 'vsps-message', notice));
		}
		var form = el('form', 'vsps-form');
		form.innerHTML =
			'<div class="vsps-row"><input required name="given_name" placeholder="__FIRST__" autocomplete="given-name">' +
			'<input required name="family_name" placeholder="__LAST__" autocomplete="family-name"></div>' +
			'<div class="vsps-row"><input required type="email" name="email" placeholder="__EMAILP__" autocomplete="email">' +
			'<input required type="tel" name="phone" placeholder="__PHONE__" autocomplete="tel"></div>' +
			this.petFieldsHtml() +
			'<textarea name="notes" placeholder="__REASON__" rows="2"></textarea>' +
			'<input type="text" name="vsps_hp" tabindex="-1" autocomplete="nope-937" aria-hidden="true" style="position:absolute;left:-9999px;">' +
			'<p class="vsps-error" style="display:none;"></p>' +
			'<div class="vsps-actions">' +
			'<button type="button" class="vsps-btn-secondary">__CANCEL__</button>' +
			'<button type="submit" class="vsps-btn-primary">__CONFIRM__</button></div>';
		form.innerHTML = form.innerHTML
			.replace('__FIRST__', escAttr(I18N.firstName)).replace('__LAST__', escAttr(I18N.lastName))
			.replace('__EMAILP__', escAttr(I18N.email)).replace('__PHONE__', escAttr(I18N.phone))
			.replace('__REASON__', escAttr(I18N.reason))
			.replace('__CANCEL__', escHtml(I18N.cancel)).replace('__CONFIRM__', escHtml(I18N.confirm));
		step.appendChild(form);
		step.appendChild(this.backLink(function () { self.renderChoiceStep(); }));
		if (prefillEmail) { form.querySelector('[name="email"]').value = prefillEmail; }
		form.querySelector('.vsps-btn-secondary').addEventListener('click', this._bk.close);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var fd = new FormData(form);
			self.submitBooking(form, {
				client_type: 'new',
				pet_is_new: true,
				vsps_hp: fd.get('vsps_hp') || '',
				client: {
					given_name: fd.get('given_name'), family_name: fd.get('family_name'),
					email: fd.get('email'), phone: fd.get('phone')
				},
				patient: {
					name: fd.get('pet_name'), species: fd.get('species'),
					breed: fd.get('breed') || '', sex: fd.get('sex') || '',
					age: fd.get('age') || '', neutered: fd.get('neutered') || ''
				},
				notes: fd.get('notes') || ''
			});
		});
		form.querySelector('[name="given_name"]').focus();
	};

	Widget.prototype.submitBooking = function (form, payload) {
		var self = this;
		var bk = this._bk;
		var errorEl = form.querySelector('.vsps-error');
		var submitBtn = form.querySelector('.vsps-btn-primary');
		errorEl.style.display = 'none';
		submitBtn.disabled = true;
		submitBtn.textContent = I18N.booking;
		// One submission at a time: freeze back-navigation and tag the request
		// so a stale response can never overwrite a newer screen.
		var reqToken = (bk.reqToken = (bk.reqToken || 0) + 1);
		bk.step.querySelectorAll('.vsps-back').forEach(function (b) { b.disabled = true; b.style.opacity = '0.4'; });

		payload.location_id = this.config.locationId;
		payload.appointment_type_id = parseInt(this.state.typeId, 10);
		payload.date = bk.date;
		payload.time = bk.slot.time;
		payload.provider_id = bk.slot.providerId || '';
		payload.schedule_id = bk.slot.scheduleId || '';
		if (!('vsps_hp' in payload)) { payload.vsps_hp = ''; }

		track('booking_submitted', {
			location_id: this.config.locationId,
			appointment_type_id: this.state.typeId,
			date: bk.date,
			time: bk.slot.time,
			client_type: payload.client_type
		});

		fetchJson(CFG.restUrl + '/book', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (data) {
			if (reqToken !== bk.reqToken) { return; }
			track('booking_completed', {
				location_id: self.config.locationId,
				appointment_type_id: self.state.typeId,
				appointment_id: data.appointment_id,
				date: bk.date,
				time: bk.slot.time,
				client_type: payload.client_type,
				after_hours: data.after_hours,
				booked_at: data.booked_at
			});
			bk.modal.innerHTML = '';
			bk.modal.appendChild(el('h4', 'vsps-modal-title', I18N.booked));
			bk.modal.appendChild(el('p', 'vsps-modal-sub', bk.type.name + ' \u2014 ' + formatDateLabel(bk.date) + ' ' + I18N.at + ' ' + formatTime(bk.slot.time)));
			bk.modal.appendChild(el('p', 'vsps-message', I18N.confirmationTo));
			var closeBtn = el('button', 'vsps-btn-primary', I18N.close);
			closeBtn.type = 'button';
			closeBtn.addEventListener('click', bk.close);
			bk.modal.appendChild(closeBtn);
			self.loadAvailability();
		}).catch(function (err) {
			if (reqToken !== bk.reqToken) { return; }
			track('booking_failed', {
				location_id: self.config.locationId,
				status: err.status || 0,
				client_type: payload.client_type
			});
			errorEl.textContent = err.message || I18N.bookingFailed;
			errorEl.style.display = 'block';
			submitBtn.disabled = false;
			submitBtn.textContent = I18N.confirm;
			bk.step.querySelectorAll('.vsps-back').forEach(function (b) { b.disabled = false; b.style.opacity = ''; });
		});
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
