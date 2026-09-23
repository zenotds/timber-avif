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

	// Tools → Queue → Process now: one worker pass per request until the queue is empty.
	var button = document.getElementById('tavif-work');
	if (!button) return;

	var wrap = document.getElementById('tavif-work-progress');
	var bar = wrap.querySelector('.tavif-progress-bar');
	var status = wrap.querySelector('.tavif-progress-status');
	var remaining = document.getElementById('tavif-remaining');
	var total = parseInt(button.getAttribute('data-total'), 10) || 0;
	var running = false;

	function show(left) {
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
		var body = new URLSearchParams({ action: 'timber_avif_work', nonce: cfg.nonce });
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (r) {
				if (!r.success) return stop(r.data || t.failed);
				var d = r.data;
				if (d.busy) {
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
		show(total);
		pass();
	});
})();
