/* Settings → Timber AVIF */
(function () {
	'use strict';

	var cfg = window.timberAvif || {};
	var t = cfg.i18n || {};

	// Quality sliders show their value.
	document.querySelectorAll('[data-tavif-range]').forEach(function (input) {
		var out = input.parentNode.querySelector('.range-val');
		input.addEventListener('input', function () { if (out) out.textContent = input.value; });
	});

	// A response that is not JSON — a PHP fatal error, a server timeout — rejects with its HTTP status.
	function post(action, extra) {
		var body = new URLSearchParams(Object.assign({ action: action, nonce: cfg.nonce }, extra || {}));
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json().catch(function () { throw new Error('HTTP ' + r.status); });
		});
	}

	var button = document.getElementById('tavif-work');
	var remaining = document.getElementById('tavif-remaining');
	var running = false;
	// The count as a number: the page prints it formatted for the admin's language.
	var left = button ? parseInt(button.getAttribute('data-total'), 10) || 0 : 0;

	// The Library and Queue cards follow the queue while the worker goes through it, in the
	// background or from this page, without a reload.
	var cards = document.querySelector('.tavif-cards');
	var polling = cards && parseInt(cards.getAttribute('data-pending'), 10) > 0;

	function poll() {
		if (!polling) return;
		if (document.hidden) return setTimeout(poll, 5000);
		post('timber_avif_status').then(function (r) {
			if (!r.success) return;
			Object.keys(r.data.cards).forEach(function (key) {
				var value = cards.querySelector('[data-tavif-card="' + key + '"] .value');
				if (value) value.innerHTML = r.data.cards[key];
			});
			if (!running) {
				left = r.data.pending;
				if (remaining) remaining.textContent = left;
				if (button) button.disabled = left === 0;
			}
			polling = r.data.pending > 0;
			if (polling) setTimeout(poll, 5000);
		}).catch(function () { setTimeout(poll, 15000); });
	}
	if (polling) setTimeout(poll, 5000);

	// Tools → Optimize: one step per request, the page reloads to show what is next.
	var optimize = document.getElementById('tavif-optimize');
	if (optimize) {
		var oWrap = document.getElementById('tavif-optimize-progress');
		var oBar = oWrap.querySelector('.tavif-progress-bar');
		var oStatus = oWrap.querySelector('.tavif-progress-status');

		var step = function (name, extra) {
			post('timber_avif_optimize', Object.assign({ step: name }, extra || {}))
				.then(function (r) {
					if (!r.success) {
						oStatus.textContent = r.data || t.failed;
						optimize.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
						return;
					}
					oBar.style.width = Math.round(r.data.progress * 100) + '%';
					if (r.data.done) return window.location.reload();
					var next = name === 'apply' ? 'apply' : 'analyse';
					if (r.data.busy) {
						oStatus.textContent = t.waiting;
						return setTimeout(function () { step(next); }, 3000);
					}
					step(next);
				})
				.catch(function (e) {
					oStatus.textContent = t.failed + ' ' + e.message;
					optimize.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
				});
		};

		optimize.querySelectorAll('[data-tavif-optimize]').forEach(function (b) {
			b.addEventListener('click', function () {
				var action = b.getAttribute('data-tavif-optimize');
				if (action === 'apply' && !window.confirm(t.confirm)) return;
				optimize.querySelectorAll('button').forEach(function (other) { other.disabled = true; });
				oWrap.hidden = false;
				oStatus.textContent = action === 'apply' ? t.deleting : t.reading;
				var timber = document.getElementById('tavif-optimize-timber');
				if (action === 'start') step('start', { timber: timber && timber.checked ? 1 : 0 });
				else step(action === 'apply' ? 'apply' : 'analyse');
			});
		});
	}

	// Tools → Queue → Process now: one worker pass per request until the queue is empty.
	if (!button) return;

	var wrap = document.getElementById('tavif-work-progress');
	var bar = wrap.querySelector('.tavif-progress-bar');
	var status = wrap.querySelector('.tavif-progress-status');
	var total = left;

	function show(count) {
		left = count;
		var pct = total > 0 ? Math.min(100, Math.round((total - left) / total * 100)) : 100;
		bar.style.width = pct + '%';
		remaining.textContent = left;
		status.textContent = (t.remaining || '%d remaining').replace('%d', left);
	}

	function stop(message) {
		running = false;
		button.disabled = false;
		button.textContent = t.start || 'Process now';
		status.textContent = message;
	}

	function pass() {
		post('timber_avif_work')
			.then(function (r) {
				if (!r.success) return stop(r.data || t.failed);
				var d = r.data;
				if (d.busy) {
					// Another pass holds the lock, and lets go at its end: the count still moves.
					show(d.remaining);
					status.textContent = t.waiting;
					return setTimeout(pass, 3000);
				}
				show(d.remaining);
				if (d.remaining > 0 && d.processed > 0) return pass();
				bar.style.width = '100%';
				stop(d.remaining > 0 ? (t.remaining || '%d remaining').replace('%d', d.remaining) : t.done);
				button.disabled = d.remaining === 0;
			})
			.catch(function () { stop(t.failed); });
	}

	button.addEventListener('click', function () {
		if (running) return;
		running = true;
		button.disabled = true;
		button.textContent = t.processing;
		wrap.hidden = false;
		total = Math.max(total, left);
		show(left);
		pass();
	});
})();
