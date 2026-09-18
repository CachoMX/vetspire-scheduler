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
		apptType: 'Select an Appointment Type',
		open: 'open',
		today: 'Today',
		tomorrow: 'Tomorrow',
		firstName: 'First name',
		lastName: 'Last name',
		email: 'Email',
		phone: 'Phone',
		petName: 'Pet name',
		species: 'Pet type',
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
		viewAll: 'View All',
		bookOnline: 'Book Online',
		firstAvailable: 'Book First Available Appointment',
		moreAppointments: 'More available appointments »',
		showingTimesFor: 'Showing available times for',
		back: '‹ Back',
		nextAvailable: 'Next Available Appointment',
		chooseAnother: 'Choose Another Time',
		slotGoneMessage: 'The appointment time you selected is no longer available. Please choose another.',
		earlierDates: 'Earlier dates',
		laterDates: 'Later dates',
		moreDates: 'More dates',
		searchingDates: 'Looking for open times %s…',
		hoursTitle: 'Hours',
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
		bookingFor: 'Booking for',
		addingPetTo: 'Adding a new pet to the account for',
		last4Label: 'Last 4 digits of the phone on file',
		cantVerify: "Can't verify? Book with the full form instead"
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
	// Client-facing short months ("Sept 15") — the abbreviations the clinics asked for.
	var SHORT_MONTHS = ['Jan', 'Feb', 'March', 'April', 'May', 'June', 'July', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];

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

	/**
	 * Wires standard popup accessibility onto an overlay already appended to
	 * the page: marks the dialog for assistive tech, traps Tab/Shift+Tab so
	 * keyboard focus can't wander into the hidden page behind it (every
	 * overlay already closes on Escape and on an outside click, so this
	 * never becomes a trap the visitor can't get OUT of, only one they can't
	 * accidentally tab out of), and moves focus onto the popup's own first
	 * control. Returns a function the caller's own close() should call so
	 * focus lands back on whatever opened the popup instead of <body>.
	 *
	 * `openerOverride`: pass this whenever the caller tears down (removes)
	 * ANOTHER overlay right before building this one -- e.g. picking a slot
	 * closes the lightbox picker before the booking form opens, and "Back"
	 * closes the form before the picker reopens. By the time this function's
	 * own `document.activeElement` read would run, that prior overlay (and
	 * whatever had focus inside it) is already gone from the DOM, so the
	 * default capture would silently restore focus to nothing useful. The
	 * caller captures the real opener BEFORE tearing the old overlay down
	 * and hands it in here instead.
	 */
	function makeAccessibleModal(overlay, dialogEl, openerOverride) {
		dialogEl.setAttribute('role', 'dialog');
		dialogEl.setAttribute('aria-modal', 'true');
		// So the "no focusable content yet" fallback below can actually focus
		// the dialog itself -- a plain <div>/<aside> isn't focusable otherwise.
		if (!dialogEl.hasAttribute('tabindex')) { dialogEl.setAttribute('tabindex', '-1'); }
		var opener = openerOverride || document.activeElement;

		function focusable() {
			var items = overlay.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]'
			);
			return Array.prototype.filter.call(items, function (node) {
				return (node.offsetWidth || node.offsetHeight) && '-1' !== node.getAttribute('tabindex');
			});
		}

		function onTrapKeydown(e) {
			if ('Tab' !== e.key) { return; }
			var items = focusable();
			if (!items.length) { return; }
			var first = items[0], last = items[items.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault(); last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault(); first.focus();
			}
		}
		overlay.addEventListener('keydown', onTrapKeydown);

		// A tick so the just-inserted content has real layout (offsetWidth
		// checks above) before anything tries to focus it.
		window.setTimeout(function () {
			var items = focusable();
			(items[0] || dialogEl).focus();
		}, 0);

		return function restoreFocus() {
			overlay.removeEventListener('keydown', onTrapKeydown);
			if (opener && 'function' === typeof opener.focus && document.body.contains(opener)) {
				opener.focus();
			}
		};
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
					err.code = (json && json.code) || '';
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

	function addDaysIso(iso, n) {
		var d = dateFromIso(iso);
		d.setDate(d.getDate() + n);
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	function formatDateLabel(iso) {
		var d = dateFromIso(iso);
		var today = new Date();
		var tomorrow = new Date(today.getTime() + 86400000);
		if (sameDay(d, today)) { return I18N.today; }
		if (sameDay(d, tomorrow)) { return I18N.tomorrow; }
		return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
	}

	/** "Sept 15" — used by the "Next Available Appointment" line. */
	function formatNextDate(iso) {
		var d = dateFromIso(iso);
		return SHORT_MONTHS[d.getMonth()] + ' ' + d.getDate();
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

	// Registry of live widget instances, for the #vsps-book external trigger below.
	var WIDGETS = [];

	function Widget(root) {
		this.root = root;
		try {
			this.config = JSON.parse(root.getAttribute('data-vsps-config'));
		} catch (e) {
			return;
		}
		this.layout = this.config.layout || 'full';
		// The bar keeps its own layout on phones (compact strip via CSS).
		this.body = root.querySelector('.vsps-body');
		this.state = { types: [], typeId: null, days: [], selectedDate: null, calMonth: null };
		this.root.classList.add('vsps-layout-' + this.layout);
		WIDGETS.push(this);
		this.init();
	}

	/** All widget events go through here so the A/B variant tags every one. */
	Widget.prototype.track = function (name, data) {
		data = data || {};
		if (this.config.variant) { data.variant = this.config.variant; }
		track(name, data);
	};

	Widget.prototype.init = function () {
		var self = this;
		if (!this.config._embedded) {
			this.track('widget_view', { location_id: this.config.locationId, layout: this.layout });
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

	/** First fetched day that still has open slots (null while loading / none). */
	Widget.prototype.firstAvailableDate = function () {
		var first = null;
		(this.state.days || []).forEach(function (d) {
			if (!first && d.slots && d.slots.length) { first = d.date; }
		});
		return first;
	};

	/** "Next Available Appointment · Sept 15" — shared by the bar and the drawer. */
	Widget.prototype.nextAvailableLine = function (className, iso) {
		var date = iso || this.firstAvailableDate();
		var line = el('div', className);
		line.appendChild(el('span', 'vsps-next-title', I18N.nextAvailable));
		if (date) { line.appendChild(el('span', 'vsps-next-date', formatNextDate(date))); }
		return line;
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
		this.locLine = line;
		this.body.insertBefore(line, this.body.firstChild ? this.body.firstChild.nextSibling : null);
	};

	Widget.prototype.fillBarLabel = function (label) {
		var self = this;
		label.innerHTML = '';
		// "Next Available Appointment · Sept 15" (the date of the quick-pick chips),
		// then the clinic name as the way into the details drawer.
		label.appendChild(this.nextAvailableLine('vsps-bar-next', this.barDate));
		if (this.locInfo) {
			var nameBtn = el('button', 'vsps-bar-name', '');
			nameBtn.type = 'button';
			nameBtn.appendChild(el('span', null, this.locInfo.name));
			nameBtn.appendChild(el('span', 'vsps-locline-arrow', '\u203a'));
			nameBtn.addEventListener('click', function () { self.openDrawer(); });
			label.appendChild(nameBtn);
		}
	};

	/** Slide-in drawer with map, contact info and hours. */
	Widget.prototype.openDrawer = function () {
		var self = this;
		var info = this.locInfo;
		if (!info) { return; }
		this.track('location_details_open', { location_id: this.config.locationId });

		var overlay = el('div', 'vsps-overlay vsps-drawer-overlay');
		var drawer = el('aside', 'vsps-drawer');
		overlay.appendChild(drawer);
		try {
			var primary = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary');
			if (primary) { overlay.style.setProperty('--vsps-primary', primary.trim()); }
		} catch (e) { /* non-blocking */ }

		var restoreFocus;
		function onKeydown(e) { if (e.key === 'Escape') { close(); } }
		function close() {
			document.removeEventListener('keydown', onKeydown);
			if (restoreFocus) { restoreFocus(); }
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
		drawer.appendChild(this.nextAvailableLine('vsps-drawer-next'));

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
			// Capture before close() removes the drawer (and this very
			// button) from the DOM -- otherwise openFullModal()'s own default
			// capture runs after the button is already gone (likely landing
			// on <body>, which isn't focusable, so restoreFocus() would
			// later silently do nothing).
			var opener = document.activeElement;
			close();
			self.openFullModal(null, '', opener);
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
		// No "Visit Website" link: the widget already lives on the clinic's site.
		if (info.googleLink) { addLink(info.googleLink, I18N.reviews); }
		if (links.childNodes.length) { drawer.appendChild(links); }

		document.body.appendChild(overlay);
		restoreFocus = makeAccessibleModal(overlay, drawer);
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
		if (this.config._notice) {
			// e.g. "That time is no longer available" when re-opened from the booking form.
			this.body.appendChild(el('p', 'vsps-notice', this.config._notice));
		}

		if (this.usesTypeSelect()) {
			var select = el('select', 'vsps-type-select');
			// The visible <label> below isn't wrapped around or `for`-linked to
			// this <select> (it can't be: `for` needs an id, and several widget
			// instances with the same layout can exist on one page) -- give it
			// an accessible name directly so screen readers announce what the
			// dropdown is, not just "combo box".
			select.setAttribute('aria-label', I18N.apptType);
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

	/** How many more days may still be fetched before the horizon (max_days). */
	Widget.prototype.horizonLeft = function () {
		return Math.max(0, (this.config.horizonDays || 30) - this.state.days.length);
	};

	Widget.prototype.pageSize = function () {
		var size = Math.max(1, this.config.days || 7);
		// The month picker fills the horizon in the background: bigger pages, fewer round-trips.
		return 'calendar' === this.layout ? Math.max(14, size) : size;
	};

	/** One page of availability (each day is one Vetspire query server-side). */
	Widget.prototype.fetchDays = function (startIso, count) {
		var url = CFG.restUrl + '/availability?location_id=' + this.config.locationId +
			'&appointment_type_id=' + this.state.typeId + '&days=' + count +
			(startIso ? '&start_date=' + startIso : '');
		return fetchJson(url).then(function (data) { return data.days || []; });
	};

	/** Appends a page, dropping any day already loaded (defensive: the server
	 * resets an out-of-range start_date to today). Returns the days actually added. */
	Widget.prototype.appendDays = function (days) {
		var last = this.lastLoadedDate();
		var fresh = last ? days.filter(function (d) { return d.date > last; }) : days;
		this.state.days = this.state.days.concat(fresh);
		return fresh;
	};

	Widget.prototype.lastLoadedDate = function () {
		var days = this.state.days;
		return days.length ? days[days.length - 1].date : null;
	};

	/**
	 * Loads availability in pages (config.days at a time) up to the horizon.
	 * Keeps paging while nothing bookable has shown up yet, or until the day
	 * the visitor was looking at (re-open from the booking form) is covered.
	 */
	Widget.prototype.loadAvailability = function () {
		var self = this;
		// Race guard: only the latest request may update state (type can be
		// switched while a slower fetch is still in flight).
		var requestId = (this.lastRequestId = (this.lastRequestId || 0) + 1);
		this.contentEl.innerHTML = '';
		this.contentEl.appendChild(el('p', 'vsps-loading', I18N.loading));
		this.state.days = [];
		this.state.loadingMore = false;
		this.state.exhausted = false;

		var page = this.pageSize();
		var wanted = this.config._initialDate || null;
		delete this.config._initialDate;

		function step() {
			var count = Math.min(page, self.horizonLeft());
			var last = self.lastLoadedDate();
			if (last) {
				// Still looking beyond the first page: say which week is being checked.
				var from = addDaysIso(last, 1);
				var label = formatNextDate(from) + ' \u2013 ' + formatNextDate(addDaysIso(from, count - 1));
				var loading = self.contentEl.querySelector('.vsps-loading');
				if (loading) { loading.textContent = I18N.searchingDates.replace('%s', label); }
			}
			return self.fetchDays(last ? addDaysIso(last, 1) : null, count).then(function (days) {
				if (requestId !== self.lastRequestId) { return; }
				days = self.appendDays(days);
				if (!days.length) { self.state.exhausted = true; }
				var first = self.firstAvailableDate();
				var wantedOk = wanted && self.state.days.some(function (d) { return d.date === wanted && d.slots.length; });
				var wantedAhead = wanted && !wantedOk && wanted > (self.lastLoadedDate() || '');
				var canLoad = days.length && self.horizonLeft() > 0;
				if (canLoad && (!first || wantedAhead)) { return step(); }
				if (!first) {
					self.contentEl.innerHTML = '';
					self.contentEl.appendChild(el('p', 'vsps-message', I18N.noTimes.replace('%d', String(self.state.days.length))));
					return;
				}
				self.state.selectedDate = wantedOk ? wanted : first;
				self.state.calMonth = null;
				self.renderLayout();
				// The month picker wants the whole horizon; fill it in the background.
				if ('calendar' === self.layout) { self.fillHorizon(); }
			});
		}
		step().catch(function () {
			if (requestId !== self.lastRequestId) { return; }
			self.contentEl.innerHTML = '';
			self.contentEl.appendChild(el('p', 'vsps-message', I18N.timesFailed));
		});
	};

	/** Appends the next page of days (called by the date strip when it runs out). */
	Widget.prototype.loadMoreDays = function () {
		var self = this;
		if (this.state.loadingMore || this.state.exhausted || this.horizonLeft() <= 0 || !this.state.days.length) {
			return Promise.resolve(false);
		}
		var requestId = this.lastRequestId;
		var count = Math.min(this.pageSize(), this.horizonLeft());
		this.state.loadingMore = true;
		// The placeholder render must not jump the strip back to the active day
		// (that would also overwrite datesScroll through the scroll listener).
		this.keepDatesScroll = true;
		this.renderLayout();
		return this.fetchDays(addDaysIso(this.lastLoadedDate(), 1), count).then(function (days) {
			if (requestId !== self.lastRequestId) { return false; }
			self.state.loadingMore = false;
			days = self.appendDays(days);
			if (!days.length) { self.state.exhausted = true; }
			self.keepDatesScroll = true;
			self.renderLayout();
			return days.length > 0;
		}).catch(function () {
			if (requestId !== self.lastRequestId) { return false; }
			self.state.loadingMore = false;
			self.keepDatesScroll = true;
			self.renderLayout();
			return false;
		});
	};

	/** Calendar: keep paging quietly until the horizon is covered. */
	Widget.prototype.fillHorizon = function () {
		var self = this;
		this.loadMoreDays().then(function (more) { if (more) { self.fillHorizon(); } });
	};

	Widget.prototype.renderLayout = function () {
		this.contentEl.innerHTML = '';
		if (this.inlineNotice) {
			this.contentEl.appendChild(el('p', 'vsps-notice', this.inlineNotice));
			this.inlineNotice = '';
		}
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
	 * Builds the full-picker lightbox (overlay + modal + a disposable inner
	 * widget) and appends it to <body>. Shared by Widget.prototype.openFullModal
	 * (sourced from an existing on-page widget) and the standalone version
	 * used when the #vsps-book trigger fires on a page with no widget/shortcode
	 * at all -- this file's own history is full of subtle bugs fixed in
	 * exactly this overlay/close/brand-color logic, so it's built once here
	 * instead of keeping two copies that could drift.
	 */
	function buildFullPickerLightbox(cfg, primaryColor, title, host, initialDate, notice, openerOverride) {
		// Captured once, up front, so both the trap setup below AND the
		// embedded widget's own _opener (openForm/backToPicker read this back
		// once THIS lightbox itself gets torn down) agree on the same stable
		// element -- see the _opener comment further down.
		var opener = openerOverride || document.activeElement;
		var overlay = el('div', 'vsps-overlay');
		var modal = el('div', 'vsps-modal vsps-modal-wide');
		overlay.appendChild(modal);
		if (primaryColor) { overlay.style.setProperty('--vsps-primary', primaryColor); }

		var restoreFocus;
		function onKeydown(e) { if (e.key === 'Escape') { close(); } }
		function close() {
			document.removeEventListener('keydown', onKeydown);
			if (restoreFocus) { restoreFocus(); }
			overlay.remove();
		}
		document.addEventListener('keydown', onKeydown);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

		var closeBtn = el('button', 'vsps-modal-close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', I18N.close);
		closeBtn.addEventListener('click', close);
		modal.appendChild(closeBtn);

		var inner = el('div', 'vsps-widget vsps-embedded');
		// The .vsps-widget class defines a default --vsps-primary, which would
		// override the overlay's inherited value — set it inline like the
		// shortcode does so the brand color survives into the modal.
		if (primaryColor) { inner.style.setProperty('--vsps-primary', primaryColor); }
		var innerCfg = {};
		Object.keys(cfg).forEach(function (k) { innerCfg[k] = cfg[k]; });
		innerCfg.layout = 'full';
		innerCfg._embedded = true;
		innerCfg._initialDate = initialDate || null;
		innerCfg._notice = notice || '';
		inner.setAttribute('data-vsps-config', JSON.stringify(innerCfg));
		inner.innerHTML = '<h3 class="vsps-title"></h3><div class="vsps-body"><p class="vsps-loading"></p></div>';
		inner.querySelector('.vsps-title').textContent = title || 'Book an Appointment';
		inner.querySelector('.vsps-loading').textContent = I18N.loading;
		modal.appendChild(inner);
		document.body.appendChild(overlay);
		restoreFocus = makeAccessibleModal(overlay, modal, opener);

		var embedded = new Widget(inner);
		// When a slot is picked inside the lightbox, close it before the form opens.
		embedded.onBeforeForm = close;
		embedded.host = host;
		// The slot button the visitor is about to click lives inside THIS
		// lightbox and will be destroyed the moment it closes (onBeforeForm),
		// so it can never be a valid focus-restore target for the form that
		// opens next -- openForm()/backToPicker() read this stable opener
		// back instead of capturing document.activeElement themselves
		// whenever config._embedded is true.
		embedded._opener = opener;
	}

	/**
	 * Opens the FULL picker in a lightbox (View All / Book Online / More).
	 * Choosing a slot closes the picker and opens the booking form modal.
	 *
	 * `openerOverride`: see makeAccessibleModal — backToPicker() passes this
	 * when it's reopening the picker right after closing the booking form,
	 * since by the time this function ran that form (and whatever inside it
	 * had focus, e.g. its own Back button) would already be gone from the DOM.
	 */
	Widget.prototype.openFullModal = function (initialDate, notice, openerOverride) {
		var primaryColor = '';
		try {
			primaryColor = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary').trim();
		} catch (e) { /* non-blocking */ }
		var titleEl = this.root.querySelector('.vsps-title');
		buildFullPickerLightbox(
			this.config, primaryColor, titleEl ? titleEl.textContent : 'Book an Appointment',
			this.host || this, initialDate, notice, openerOverride
		);
	};

	/**
	 * Close the booking form and get back to the time picker: the lightbox for
	 * bar/float (and for forms opened from a lightbox); in place for the full and
	 * calendar layouts, whose picker is already on the page.
	 */
	Widget.prototype.backToPicker = function (notice) {
		// The Back button about to be clicked lives inside the form, which
		// bk.close() below is about to remove -- reuse the same stable opener
		// openForm() resolved (and persisted onto this._opener) rather than
		// capturing document.activeElement here, which would just be that
		// soon-to-be-destroyed Back button itself.
		var opener = this._opener || document.activeElement;
		var bk = this._bk;
		if (bk) { bk.close(); }
		var inline = !this.host && ('full' === this.layout || 'calendar' === this.layout);
		if (!inline) {
			(this.host || this).openFullModal(bk ? bk.date : null, notice || '', opener);
			return;
		}
		if (notice) {
			this.inlineNotice = notice;
			this.config._initialDate = bk ? bk.date : null;
			this.loadAvailability();
		}
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
			self.track('slot_selected', {
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
		var wrap = el('div', 'vsps-dates-wrap');
		var datesEl = el('div', 'vsps-dates');
		var prev = el('button', 'vsps-dates-arrow vsps-dates-prev', '\u2039');
		var next = el('button', 'vsps-dates-arrow vsps-dates-next', '\u203a');
		prev.type = 'button';
		next.type = 'button';
		prev.setAttribute('aria-label', I18N.earlierDates);
		next.setAttribute('aria-label', I18N.laterDates);
		function nearEnd() { return datesEl.scrollLeft + datesEl.clientWidth >= datesEl.scrollWidth - 60; }
		prev.addEventListener('click', function () { datesEl.scrollBy({ left: -Math.max(120, datesEl.clientWidth * 0.8), behavior: 'smooth' }); });
		next.addEventListener('click', function () {
			datesEl.scrollBy({ left: Math.max(120, datesEl.clientWidth * 0.8), behavior: 'smooth' });
			// Reaching the end of what is loaded fetches the next page.
			window.setTimeout(function () { if (nearEnd()) { self.loadMoreDays(); } }, 400);
		});
		datesEl.addEventListener('scroll', function () {
			self.datesScroll = datesEl.scrollLeft;
			if (nearEnd()) { self.loadMoreDays(); }
		});
		var total = this.state.days.length;
		this.state.days.forEach(function (d, idx) {
			var btn = el('button', 'vsps-date-btn', '');
			btn.type = 'button';
			btn.appendChild(el('span', 'vsps-date-label', formatDateLabel(d.date)));
			btn.appendChild(el('span', 'vsps-date-count', d.slots.length ? d.slots.length + ' ' + I18N.open : '—'));
			if (!d.slots.length) { btn.disabled = true; }
			if (d.date === self.state.selectedDate) { btn.classList.add('is-active'); }
			btn.addEventListener('click', function () {
				self.state.selectedDate = d.date;
				self.keepDatesScroll = true;
				self.datesScroll = datesEl.scrollLeft;
				self.renderLayout();
				// Picking one of the last loaded days pulls in the following ones.
				if (idx >= total - 2) { self.loadMoreDays(); }
			});
			datesEl.appendChild(btn);
		});
		if (this.horizonLeft() > 0 && !this.state.exhausted) {
			var more = el('button', 'vsps-date-btn vsps-date-more', '');
			more.type = 'button';
			more.appendChild(el('span', 'vsps-date-label', this.state.loadingMore ? '\u2026' : '\u203a'));
			more.appendChild(el('span', 'vsps-date-count', I18N.moreDates));
			more.disabled = !!this.state.loadingMore;
			more.addEventListener('click', function () { self.loadMoreDays(); });
			datesEl.appendChild(more);
		}
		wrap.appendChild(prev);
		wrap.appendChild(datesEl);
		wrap.appendChild(next);
		this.contentEl.appendChild(wrap);
		// Keep the strip where the visitor left it after paging; otherwise bring
		// the selected day into view. Hide the arrows when everything fits.
		var active = datesEl.querySelector('.is-active');
		if (this.keepDatesScroll) {
			datesEl.scrollLeft = this.datesScroll || 0;
			this.keepDatesScroll = false;
		} else if (active) {
			datesEl.scrollLeft = Math.max(0, active.offsetLeft - 8);
		}
		if (datesEl.scrollWidth <= datesEl.clientWidth + 2) { wrap.classList.add('vsps-dates-fit'); }
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
		var bar = el('div', 'vsps-bar');

		var label = el('div', 'vsps-bar-label');
		this.barLabel = label;
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
				self.track('slot_selected', {
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
		// Capture the brand color BEFORE closing whatever opened this form:
		// closing a lightbox picker (onBeforeForm) detaches this.root, so
		// getComputedStyle() can no longer resolve custom properties after
		// that (silently fell back to the default green).
		//
		// The focus-restore target needs more care than just "whatever's
		// focused right now": if this widget is itself disposable (embedded
		// in a lightbox that onBeforeForm is about to remove), the clicked
		// slot button is about to be destroyed along with its container, so
		// it can never be a valid restore target -- inherit the STABLE
		// opener that lightbox itself was given instead (buildFullPickerLightbox
		// set this._opener when it created this widget). Otherwise (an
		// on-page full/calendar widget; nothing here gets removed) the
		// clicked slot button survives and IS the right target. Persisted
		// back onto _opener either way so backToPicker() can read the same
		// value back later without re-capturing a since-destroyed node.
		var opener = (this.config._embedded && this._opener) ? this._opener : document.activeElement;
		this._opener = opener;
		var primary = '';
		try {
			primary = window.getComputedStyle(this.root).getPropertyValue('--vsps-primary');
		} catch (e) { /* non-blocking */ }
		if (this.onBeforeForm) { this.onBeforeForm(); }
		var type = this.currentType();
		var overlay = el('div', 'vsps-overlay');
		var modal = el('div', 'vsps-modal');
		overlay.appendChild(modal);
		if (primary) { overlay.style.setProperty('--vsps-primary', primary.trim()); }

		var restoreFocus;
		function onKeydown(e) { if (e.key === 'Escape') { close(); } }
		function close() {
			document.removeEventListener('keydown', onKeydown);
			if (restoreFocus) { restoreFocus(); }
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
		restoreFocus = makeAccessibleModal(overlay, modal, opener);
		this.track('form_started', {
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
		// First step: Back returns to the time picker instead of forcing the ×.
		step.appendChild(this.backLink(function () { self.backToPicker(); }));
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
				self._bk.pets = data.pets || [];
				if (data.pets && data.pets.length) {
					self.renderPetStep(data.pets);
				} else {
					self.renderNewPetForm();
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
		this._bk.pets = pets;
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
		np.addEventListener('click', function () { self.renderNewPetForm(); });
		list.appendChild(np);
		step.appendChild(list);
		step.appendChild(this.backLink(function () { self.renderEmailStep(); }));
	};

	/** Back target after the lookup: the pet chips when the account has pets, else the email step. */
	Widget.prototype.renderPetChoice = function () {
		if (this._bk.pets && this._bk.pets.length) {
			this.renderPetStep(this._bk.pets);
		} else {
			this.renderEmailStep();
		}
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
		step.appendChild(this.backLink(function () { self.renderPetChoice(); }));
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
		var pf = this.config.petFields || {};
		var optional = [];
		if (pf.breed) { optional.push('<input name="breed" placeholder="__BREED__">'); }
		if (pf.sex) { optional.push('<select name="sex" aria-label="__SEXLABEL__"><option value="">__SEXLABEL__</option><option value="MALE">__MALE__</option><option value="FEMALE">__FEMALE__</option></select>'); }
		if (pf.age) { optional.push('<input type="number" name="age" min="0" max="40" placeholder="__AGE__">'); }
		if (pf.neutered) { optional.push('<select name="neutered" aria-label="__NEUTERED__"><option value="">__NEUTERED__</option><option value="yes">__YES__</option><option value="no">__NO__</option></select>'); }
		var rows = '';
		for (var i = 0; i < optional.length; i += 2) {
			rows += '<div class="vsps-row">' + optional[i] + (optional[i + 1] || '') + '</div>';
		}
		// aria-label (not a wrapped/`for`-linked <label>) so this still works
		// when the pet_name placeholder is the only visual cue and multiple
		// widget instances on one page can't share a single id.
		var html = '<div class="vsps-row"><input required name="pet_name" placeholder="__PET__" aria-label="__PET__">' +
			'<select name="species" aria-label="__SPECIES__"><option value="Canine">__DOG__</option><option value="Feline">__CAT__</option><option value="Other">__OTHER__</option></select></div>' +
			rows;
		// Tokens that now appear more than once (an aria-label alongside the
		// same text visible elsewhere) need a global replace, not the default
		// replace-first-occurrence-only behavior; escAttr()'s output (it
		// entity-escapes quotes on top of escHtml()) is safe to reuse in a
		// plain text node too, so one escaped value works in both spots.
		return html
			.replace(/__PET__/g, escAttr(I18N.petName))
			.replace(/__SPECIES__/g, escAttr(I18N.species))
			.replace('__DOG__', escHtml(I18N.dog)).replace('__CAT__', escHtml(I18N.cat)).replace('__OTHER__', escHtml(I18N.other))
			.replace('__BREED__', escAttr(I18N.breed)).replace(/__SEXLABEL__/g, escAttr(I18N.sexLabel))
			.replace('__MALE__', escHtml(I18N.male)).replace('__FEMALE__', escHtml(I18N.female))
			.replace('__AGE__', escAttr(I18N.ageYears)).replace(/__NEUTERED__/g, escAttr(I18N.neuteredQ))
			.replace('__YES__', escHtml(I18N.yes)).replace('__NO__', escHtml(I18N.no));
	};

	/**
	 * Existing client adding a pet: pet fields + last-4-of-phone ownership
	 * verification (checked server-side against the number Vetspire has on
	 * file). No owner data is ever shown or prefilled.
	 */
	Widget.prototype.renderNewPetForm = function () {
		var self = this;
		var step = this._bk.step;
		step.innerHTML = '';
		step.appendChild(el('p', 'vsps-step-q', I18N.addingPetTo + ' ' + this._bk.email));
		var form = el('form', 'vsps-form');
		form.innerHTML = this.petFieldsHtml() +
			'<div class="vsps-row"><input required name="phone_last4" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="__LAST4__"></div>' +
			'<textarea name="notes" placeholder="__REASON__" rows="2"></textarea>' +
			'<p class="vsps-error" style="display:none;"></p>' +
			'<div class="vsps-actions"><button type="submit" class="vsps-btn-primary">__CONFIRM__</button></div>';
		form.innerHTML = form.innerHTML
			.replace('__LAST4__', escAttr(I18N.last4Label))
			.replace('__REASON__', escAttr(I18N.reason))
			.replace('__CONFIRM__', escHtml(I18N.confirm));
		step.appendChild(form);
		var fallback = el('button', 'vsps-back', I18N.cantVerify);
		fallback.type = 'button';
		fallback.addEventListener('click', function () {
			self._bk.clientType = 'new';
			self.renderNewForm(self._bk.email, '');
		});
		step.appendChild(fallback);
		step.appendChild(this.backLink(function () { self.renderPetChoice(); }));
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var fd = new FormData(form);
			self.submitBooking(form, {
				client_type: 'existing',
				pet_is_new: true,
				phone_last4: fd.get('phone_last4') || '',
				client: { given_name: '', family_name: '', email: self._bk.email, phone: '' },
				patient: {
					name: fd.get('pet_name'), species: fd.get('species'),
					breed: fd.get('breed') || '', sex: fd.get('sex') || '',
					age: fd.get('age') || '', neutered: fd.get('neutered') || ''
				},
				notes: fd.get('notes') || ''
			});
		});
		form.querySelector('[name="pet_name"]').focus();
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
		// Source of the booking for the admin log (the lightbox reports the layout
		// of the on-page widget that opened it).
		payload.layout = (this.host || this).layout;
		payload.variant = this.config.variant || '';
		payload.page_url = (window.location.origin + window.location.pathname).slice(0, 255);
		if (!('vsps_hp' in payload)) { payload.vsps_hp = ''; }

		this.track('booking_submitted', {
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
			self.track('booking_completed', {
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
			self.track('booking_failed', {
				location_id: self.config.locationId,
				status: err.status || 0,
				client_type: payload.client_type
			});
			// The slot is gone: the visitor's next move is always "Choose Another
			// Time" — show that instead of the raw "no longer available" wording,
			// both on this form and on the notice atop the picker it reopens into.
			var slotGone = 'vsps_slot' === err.code;
			var message  = slotGone ? I18N.slotGoneMessage : ( err.message || I18N.bookingFailed );
			errorEl.textContent = message;
			errorEl.style.display = 'block';
			bk.step.querySelectorAll('.vsps-back').forEach(function (b) { b.disabled = false; b.style.opacity = ''; });
			if (slotGone) {
				// Retrying the same time can never succeed, so the primary action
				// becomes "Choose Another Time" (re-opens the picker on the same day).
				submitBtn.type = 'button';
				submitBtn.disabled = false;
				submitBtn.textContent = I18N.chooseAnother;
				submitBtn.addEventListener('click', function (e) {
					e.preventDefault();
					self.backToPicker(message);
				});
				return;
			}
			submitBtn.disabled = false;
			submitBtn.textContent = I18N.confirm;
		});
	};

	/* ---------- boot ---------- */

	function boot() {
		var widgets = document.querySelectorAll('.vsps-widget[data-vsps-config]');
		Array.prototype.forEach.call(widgets, function (root) {
			if (root.getAttribute('data-vsps-noinit')) { return; }
			new Widget(root);
		});
		scanForBookLinks();
		maybeOpenFromHash();
	}

	// Exposed for the admin preview (re-init after layout/location change).
	window.vspsInitWidget = function (root) { return new Widget(root); };

	/**
	 * External "Book Online" trigger: any link on the page whose URL ends in
	 * #vsps-book opens the widget's full picker instead of navigating away —
	 * install it on an existing button by pointing its link at "#vsps-book"
	 * (or "<page URL>#vsps-book"); no plugin config or per-site code needed,
	 * so the same convention works on every clinic site. Also honours a
	 * direct visit to a URL ending in #vsps-book (e.g. from an ad or email)
	 * by opening the picker on arrival.
	 */
	var BOOK_HASH_RE = /#vsps-book(?:[?&][^#]*)?$/i;

	function isBookHashHref(href) {
		return !!href && BOOK_HASH_RE.test(href);
	}

	/**
	 * A #vsps-book link is markup the SITE owns (a theme button, a menu item),
	 * not ours — on some host pages another fixed-position element (a cookie
	 * banner, a chat widget, a sticky bar) ends up layered on top of it,
	 * especially on narrow/mobile viewports, so a tap never reaches the link
	 * at all and nothing we listen for ever fires. Since the site owner
	 * pointed this specific link at us on purpose, keep it clickable
	 * regardless of what else is on the page: give it a stacking context
	 * (position must be non-static for z-index to apply) and a z-index far
	 * above anything a theme or plugin normally uses. This only affects
	 * hit-testing/stacking order, not layout, so it doesn't move the link.
	 * The value only needs to clear realistic theme/plugin chrome (cookie
	 * banners, chat widgets, sticky bars top out well under six digits) —
	 * deliberately NOT the highest possible z-index, so a page's own
	 * legitimate full-screen gate (an age check, a hard consent wall) still
	 * wins and isn't accidentally bypassable through this trigger. The
	 * plugin's own modal overlay (.vsps-overlay, scheduler.css) is set even
	 * higher so it always paints above this boosted link once it opens.
	 *
	 * Raising the LINK's own z-index only wins the fight against a sibling
	 * overlay if nothing between the link and <body> boxes it into a lower-
	 * ranked stacking context of its own — and that's common in practice:
	 * Elementor's flex "Container" layout gives every wrapping container
	 * `position:relative; z-index:0` (confirmed live on iowacolony.easyvet.com,
	 * where the button sits a few of those containers deep), which caps
	 * whatever z-index the link claims internally to that container's own
	 * z-index of 0 as far as anything OUTSIDE the container is concerned — a
	 * cookie banner at z-index 99999 still wins over the whole subtree. So
	 * boost every ancestor up to <body> that already establishes a stacking
	 * context (a non-static position with an explicit z-index, or a property
	 * that creates one implicitly) too, letting the escalation reach all the
	 * way out instead of being trapped one level up.
	 */
	function establishesStackingContext(node, cs) {
		// position:fixed/sticky always creates one, independent of z-index.
		if (cs.position === 'fixed' || cs.position === 'sticky') { return true; }
		if (cs.zIndex !== 'auto') {
			if (cs.position !== 'static') { return true; }
			// A flex/grid ITEM's z-index applies (and creates a context) even
			// while it stays position:static — no position change needed there.
			var parent = node.parentElement;
			if (parent && /flex|grid/.test(window.getComputedStyle(parent).display)) { return true; }
		}
		if (cs.isolation === 'isolate') { return true; }
		if (cs.contain && /layout|paint|strict|content/.test(cs.contain)) { return true; }
		if (cs.backdropFilter && cs.backdropFilter !== 'none') { return true; }
		// Deliberately NOT treating opacity<1 / transform / mix-blend-mode /
		// will-change as triggers here: those are exactly the properties CSS
		// entrance and scroll animations toggle transiently, and __vspsBoosted
		// below makes any match permanent — missing a rare animation-only
		// stacking context is a smaller risk than permanently pinning an
		// unrelated container's position/z-index mid-fade.
		return false;
	}

	function ensureBookLinkOnTop(a) {
		if (a.__vspsBoosted) { return; }
		a.__vspsBoosted = true;
		if (window.getComputedStyle(a).position === 'static') {
			a.style.position = 'relative';
		}
		a.style.zIndex = '999999';

		// Ancestor containers get the same treatment when THEY already box
		// their own children into a lower-ranked stacking context (see the
		// comment above) — this can, rarely, also change how such a
		// container stacks against its own unrelated siblings elsewhere on
		// the page, or make a previously-static one a new containing block
		// for its own absolutely-positioned descendants; an acceptable
		// trade-off for keeping this specific, site-owner-opted-in trigger
		// reliably clickable.
		var node = a.parentElement;
		while (node && node !== document.body && node !== document.documentElement) {
			if (!node.__vspsBoosted) {
				var cs = window.getComputedStyle(node);
				if (establishesStackingContext(node, cs)) {
					node.__vspsBoosted = true;
					if (cs.position === 'static') { node.style.position = 'relative'; }
					node.style.zIndex = '999999';
				}
			}
			node = node.parentElement;
		}
	}

	function scanForBookLinks() {
		var links = document.querySelectorAll('a[href]');
		for (var i = 0; i < links.length; i++) {
			if (isBookHashHref(links[i].getAttribute('href'))) { ensureBookLinkOnTop(links[i]); }
		}
	}

	// Menus built or duplicated by the theme's own JS (a mobile off-canvas
	// drawer, for example) can add a matching link after our initial scan —
	// watch for that and boost it too, debounced so a busy page's routine DOM
	// churn doesn't trigger a full rescan on every mutation.
	var scanPending = null;
	function scheduleScanForBookLinks() {
		if (scanPending) { return; }
		scanPending = window.setTimeout(function () { scanPending = null; scanForBookLinks(); }, 150);
	}
	if (window.MutationObserver) {
		new MutationObserver(scheduleScanForBookLinks).observe(document.documentElement, { childList: true, subtree: true });
	}

	/**
	 * Which on-page (non-lightbox) widget an external #vsps-book link should open:
	 * the one marked data-vsps-primary="1" (shortcode attribute primary="1") when a
	 * page has one, otherwise the first widget in the page's HTML.
	 */
	function primaryWidget() {
		var onPage = [];
		for (var i = 0; i < WIDGETS.length; i++) {
			if (!WIDGETS[i].config._embedded) { onPage.push(WIDGETS[i]); }
		}
		for (var j = 0; j < onPage.length; j++) {
			if (onPage[j].root.getAttribute('data-vsps-primary')) { return onPage[j]; }
		}
		return onPage.length ? onPage[0] : null;
	}

	/**
	 * Opens the full picker from scratch, with no existing widget/shortcode
	 * anywhere on the current page to borrow one from -- e.g. a "Book Online"
	 * nav link that shows on every page of the site while the shortcode
	 * itself only lives on the homepage. Built on the same shared
	 * buildFullPickerLightbox() as Widget.prototype.openFullModal, just
	 * sourced from a raw config object (CFG.defaultWidget, localized from
	 * the site's Settings) instead of an existing this/this.root.
	 */
	function openStandaloneBookingModal(rawConfig, initialDate, notice, openerOverride) {
		var host = {
			openFullModal: function (date, n, opener) {
				// "Back" from the booking form re-opens this same standalone
				// picker (there's no real on-page widget to hand it back to).
				openStandaloneBookingModal(rawConfig, date, n, opener);
			}
		};
		buildFullPickerLightbox(
			rawConfig, rawConfig.primaryColor || '', rawConfig.title,
			host, initialDate, notice, openerOverride
		);
	}

	function openBookHashTarget() {
		var w = primaryWidget();
		if (w && typeof w.openFullModal === 'function') {
			w.openFullModal();
			return;
		}
		// No shortcode/widget anywhere on this page -- still honor the
		// trigger using the site's default booking config, so a "Book
		// Online" link works from every page, not only the one or two
		// pages the shortcode happens to be embedded on.
		if (CFG.defaultWidget) { openStandaloneBookingModal(CFG.defaultWidget); }
	}

	document.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		if (a && isBookHashHref(a.getAttribute('href'))) {
			e.preventDefault();
			openBookHashTarget();
		}
	}, true);

	function maybeOpenFromHash() {
		if (!isBookHashHref(window.location.hash)) { return; }
		// Widgets fetch their types asynchronously; give the first one a moment
		// to exist before opening its lightbox.
		window.setTimeout(function () {
			openBookHashTarget();
			if (window.history && history.replaceState) {
				history.replaceState(null, '', window.location.pathname + window.location.search);
			}
		}, 300);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
