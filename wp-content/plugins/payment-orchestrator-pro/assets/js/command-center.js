/* Payment Orchestrator Pro \u2014 Command Center: chart breakdowns on hover, tap or keyboard focus. */
(function () {
	function show(chart, g, evt) {
		var tip = chart.querySelector('.po-cc-tip'); if (!tip) { return; }
		var d; try { d = JSON.parse(g.getAttribute('data-tip')); } catch (e) { return; }
		tip.textContent = '';
		var h = document.createElement('strong'); h.textContent = d.t + ' \u00B7 ' + d.s; tip.appendChild(h);
		(d.r || []).forEach(function (r) {
			var row = document.createElement('div'); row.className = 'r';
			var dot = document.createElement('i'); dot.style.background = r.c;
			var n = document.createElement('span'); n.textContent = r.n;
			var v = document.createElement('b'); v.textContent = r.v;
			var p = document.createElement('em'); p.textContent = r.p;
			row.appendChild(dot); row.appendChild(n); row.appendChild(v); row.appendChild(p); tip.appendChild(row);
		});
		chart.querySelectorAll('.po-cc-hov.is-on').forEach(function (x) { x.classList.remove('is-on'); });
		g.classList.add('is-on'); chart.classList.add('is-hover'); tip.hidden = false;
		var cr = chart.getBoundingClientRect(), gr = g.getBoundingClientRect();
		var left = gr.left - cr.left + gr.width / 2 - tip.offsetWidth / 2;
		left = Math.max(0, Math.min(left, cr.width - tip.offsetWidth));
		tip.style.left = left + 'px'; tip.style.top = Math.max(0, gr.top - cr.top - tip.offsetHeight + 24) + 'px';
	}
	function hide(chart) {
		var tip = chart.querySelector('.po-cc-tip'); if (tip) { tip.hidden = true; }
		chart.classList.remove('is-hover');
		chart.querySelectorAll('.po-cc-hov.is-on').forEach(function (x) { x.classList.remove('is-on'); });
	}
	document.querySelectorAll('.po-cc-chart').forEach(function (chart) {
		chart.querySelectorAll('.po-cc-hov').forEach(function (g) {
			g.addEventListener('mouseenter', function (e) { show(chart, g, e); });
			g.addEventListener('focus', function (e) { show(chart, g, e); });
			g.addEventListener('blur', function () { hide(chart); });
			g.addEventListener('click', function (e) { if (g.classList.contains('is-on')) { hide(chart); } else { show(chart, g, e); } });
			g.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(chart); g.blur(); } });
		});
		chart.addEventListener('mouseleave', function () { hide(chart); });
	});
	document.addEventListener('click', function (e) {
		document.querySelectorAll('.po-cc-chart.is-hover').forEach(function (c) { if (!c.contains(e.target)) { hide(c); } });
	});
})();
