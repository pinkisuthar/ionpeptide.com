(function () {
	'use strict';

	function closestPoll(element) {
		return element.closest('[data-ionmd-poll]');
	}

	function setMessage(form, text, type) {
		var message = form.querySelector('[data-ionmd-poll-message]');
		if (!message) {
			return;
		}
		message.textContent = text || '';
		message.classList.remove('is-success', 'is-error');
		if (type) {
			message.classList.add(type === 'success' ? 'is-success' : 'is-error');
		}
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || !form.matches('[data-ionmd-poll-form]')) {
			return;
		}

		event.preventDefault();

		var checked = form.querySelector('input[name="option_id"]:checked');
		if (!checked) {
			setMessage(form, 'Please choose an organization before submitting your vote.', 'error');
			return;
		}

		var button = form.querySelector('button[type="submit"]');
		if (button) {
			button.disabled = true;
			button.textContent = 'Submitting...';
		}

		setMessage(form, '', null);

		var data = new FormData(form);
		data.append('token', IonMissionDonations.voteToken);

		fetch(IonMissionDonations.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				var poll = closestPoll(form);
				if (json && json.success && json.data && json.data.html && poll) {
					poll.outerHTML = json.data.html;
					return;
				}

				setMessage(form, json && json.data && json.data.message ? json.data.message : 'Vote could not be submitted. Please try again.', 'error');
			})
			.catch(function () {
				setMessage(form, 'Vote could not be submitted. Please try again.', 'error');
			})
			.finally(function () {
				if (button) {
					button.disabled = false;
					button.textContent = 'Submit Vote';
				}
			});
	});
})();

(function () {
	'use strict';

	function closest(node, selector) {
		if (!node) {
			return null;
		}

		if (node.closest) {
			return node.closest(selector);
		}

		while (node) {
			if (node.matches && node.matches(selector)) {
				return node;
			}
			node = node.parentNode;
		}

		return null;
	}

	function getModal() {
		return document.querySelector('[data-ionmd-receipt-modal]');
	}

	function openReceipt(link) {
		var modal = getModal();
		var url = link.getAttribute('href');
		var title = link.getAttribute('data-receipt-title') || 'Donation Receipt Letter';
		var frame;
		var direct;
		var titleNode;

		if (!modal || !url || url === '#') {
			return false;
		}

		frame = modal.querySelector('[data-ionmd-receipt-frame]');
		direct = modal.querySelector('[data-ionmd-receipt-direct]');
		titleNode = modal.querySelector('[data-ionmd-receipt-title]');

		if (titleNode) {
			titleNode.textContent = title;
		}

		if (direct) {
			direct.href = url;
		}

		if (frame) {
			frame.src = url;
		}

		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('ionmd-receipt-modal-open');

		return true;
	}

	function closeReceipt() {
		var modal = getModal();
		var frame;

		if (!modal) {
			return;
		}

		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('ionmd-receipt-modal-open');

		frame = modal.querySelector('[data-ionmd-receipt-frame]');
		if (frame) {
			frame.src = 'about:blank';
		}
	}

	document.addEventListener('click', function (event) {
		var openLink = closest(event.target, '[data-ionmd-receipt-open]');
		var closeButton;

		if (openLink) {
			if (openReceipt(openLink)) {
				event.preventDefault();
			}
			return;
		}

		closeButton = closest(event.target, '[data-ionmd-receipt-close]');
		if (closeButton) {
			event.preventDefault();
			closeReceipt();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeReceipt();
		}
	});
})();
