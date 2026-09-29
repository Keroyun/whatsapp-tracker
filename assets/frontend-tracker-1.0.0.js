(function () {
	'use strict';
	// Release-specific filename: remains cache-safe when query strings are removed.
	// Shortcode documents isolate form-provider assets from sitewide widgets.

	var configNode = document.getElementById('awm-tracker-config');
	var cfg = {};
	if (configNode) {
		try { cfg = JSON.parse(configNode.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
	}
	var trackingEnabled = false; // Wait for fresh settings before emitting analytics.
	var emergencyRules = Array.isArray(cfg.emergencyRules) ? cfg.emergencyRules : [];
	var popupEnabled = {
		whatsapp: !cfg.popupEnabled || cfg.popupEnabled.whatsapp !== false,
		telephone: !cfg.popupEnabled || cfg.popupEnabled.telephone !== false
	};
	var shortlinkMap = cfg.shortlinkMap && typeof cfg.shortlinkMap === 'object' ? cfg.shortlinkMap : {};
	var managedRoutes = cfg.managedRoutes && typeof cfg.managedRoutes === 'object' ? cfg.managedRoutes : {};

	function parseTelephone(url) {
		var match = /^tel:(\+?[0-9(). \-]+)(?:;ext=([0-9]{1,10}))?$/i.exec(String(url || '').trim());
		if (!match) return null;
		var number = match[1].replace(/[(). \-]/g, '');
		var digits = number.replace(/^\+/, '');
		// DO NOT REMOVE: never intercept short emergency/service numbers.
		if (digits.length < 7 || digits.length > 15) return null;
		var identity = 'tel:' + number + (match[2] ? ';ext=' + match[2] : '');
		return { channel: 'telephone', host: 'telephone', destination: identity, href: identity };
	}

	function parseWhatsApp(url) {
		try {
			var u = new URL(url, window.location.href);
			if (u.protocol !== 'https:' && u.protocol !== 'http:') return null;
			var host = (u.hostname || '').toLowerCase();
			var destination = '';
			var managedSlug = '';
			Object.keys(managedRoutes).some(function (slug) {
				var route = managedRoutes[slug] || {};
				try {
					var routeUrl = new URL(route.url, window.location.href);
					if (routeUrl.hostname.toLowerCase() === host && cleanPath(routeUrl.pathname) === cleanPath(u.pathname)) {
						managedSlug = slug;
						return true;
					}
				} catch (e) {}
				return false;
			});

			if (managedSlug) {
				var managed = managedRoutes[managedSlug] || {};
				return {
					channel: 'whatsapp',
					host: 'managed-route',
					destination: 'route/' + managedSlug,
					resolvedDestination: managed.destination || '',
					source: managed.source || '',
					routeEnabled: managed.enabled === true,
					href: u.href
				};
			}

			if (host === 'wa.me') {
				destination = (u.pathname.split('/').filter(Boolean)[0] || '').replace(/\D/g, '');
			} else if (host === 'api.whatsapp.com' || host === 'web.whatsapp.com') {
				destination = (u.searchParams.get('phone') || '').replace(/\D/g, '');
			} else if (host === 'wa.link') {
				destination = 'wa.link/' + (u.pathname.split('/').filter(Boolean)[0] || '');
			} else {
				return null;
			}

			return {
				channel: 'whatsapp',
				host: host,
				destination: destination,
				resolvedDestination: shortlinkMap[destination] || '',
				href: u.href
			};
		} catch (e) {
			return null;
		}
	}

	function normaliseHref(url) {
		try {
			var telephone = parseTelephone(url);
			if (telephone) return telephone.href;
			var parsed = new URL(url, window.location.href);
			parsed.hash = '';
			return parsed.href;
		} catch (e) {
			return '';
		}
	}

	function cleanPath(path) {
		path = String(path || '/').split('?')[0].split('#')[0];
		if (path.charAt(0) !== '/') path = '/' + path;
		if (path.length > 1) path = path.replace(/\/+$/, '');
		return path || '/';
	}

	function pageMatchScore(pattern, currentPath) {
		pattern = String(pattern || '').trim();
		if (!pattern) return 0;
		if (pattern === '*') return 1;
		if (pattern.slice(-1) === '*') {
			var prefix = pattern.slice(0, -1);
			return prefix && String(currentPath || '/').indexOf(prefix) === 0 ? 50 : 0;
		}
		return cleanPath(pattern) === cleanPath(currentPath) ? 100 : 0;
	}

	function ruleMatch(rule, parsed) {
		if (popupEnabled[parsed.channel] !== true) return null;
		var channel = rule.channel || 'whatsapp';
		if (channel !== 'both' && channel !== parsed.channel) return null;
		if (rule.language && rule.language !== cfg.language) return null;
		var groups = 0;
		var matched = 0;
		var score = 0;
		var currentPath = window.location.pathname || '/';
		var paths = Array.isArray(rule.pagePaths) ? rule.pagePaths : [];
		var destinations = Array.isArray(rule.destinations) ? rule.destinations : [];
		var links = Array.isArray(rule.exactLinks) ? rule.exactLinks : [];

		if (paths.length) {
			groups++;
			var pageScore = 0;
			paths.forEach(function (pattern) {
				pageScore = Math.max(pageScore, pageMatchScore(pattern, currentPath));
			});
			if (pageScore) {
				matched++;
				score += pageScore;
			}
		}

		if (destinations.length) {
			groups++;
			var destinationMatched = destinations.some(function (destination) {
				if (destination === parsed.destination || (parsed.resolvedDestination && destination === parsed.resolvedDestination)) return true;
				if (parsed.channel !== 'telephone') return false;
				// Compare the same digits with or without +; never infer or remove a country code.
				var telephoneTarget = parseTelephone(destination);
				if (!telephoneTarget && channel === 'both' && /^[0-9]{7,15}$/.test(destination)) {
					telephoneTarget = parseTelephone('tel:' + destination);
				}
				return telephoneTarget && telephoneTarget.destination.replace(/^tel:\+/, 'tel:') === parsed.destination.replace(/^tel:\+/, 'tel:');
			});
			if (destinationMatched) {
				matched++;
				score += 200;
			}
		}

		if (links.length) {
			groups++;
			var currentHref = normaliseHref(parsed.href);
			var linkMatched = links.some(function (link) {
				return normaliseHref(link) === currentHref;
			});
			if (linkMatched) {
				matched++;
				score += 400;
			}
		}

		if (!groups) return null;
		if (rule.matchMode === 'all') {
			if (matched !== groups) return null;
			if (groups > 1) score += 1000;
		} else if (!matched) {
			return null;
		}

		return {
			rule: rule,
			languageSpecific: rule.language ? 1 : 0,
			score: score,
			priority: Number(rule.priority || 0)
		};
	}

	function findEmergencyRule(parsed) {
		var best = null;
		emergencyRules.forEach(function (rule) {
			var candidate = ruleMatch(rule, parsed);
			if (!candidate) return;
			if (!best || candidate.languageSpecific > best.languageSpecific || (candidate.languageSpecific === best.languageSpecific && (candidate.score > best.score || (candidate.score === best.score && candidate.priority > best.priority)))) {
				best = candidate;
			}
		});
		return best ? best.rule : null;
	}

	function pushDataLayer(payload) {
		if (trackingEnabled && Array.isArray(window.dataLayer)) window.dataLayer.push(payload);
	}

	// Remove the native dial destination only while a telephone notice matches.
	// Keep the original URL so disabling/removing a rule restores normal calling.
	function syncTelephoneLinks() {
		if (!document.querySelectorAll) return;
		document.querySelectorAll('a[href], a[data-awm-original-tel]').forEach(function (link) {
			if (link.closest('.awm-emergency-overlay')) return;
			var original = link.getAttribute('data-awm-original-tel');
			var href = original || link.getAttribute('href');
			var parsed = parseTelephone(href);
			if (!parsed) return;
			if (link.getAttribute('data-awm-emergency-exempt') !== '1' && findEmergencyRule(parsed)) {
				if (!original) link.setAttribute('data-awm-original-tel', href);
				link.setAttribute('href', '#awm-telephone-notice');
			} else if (original) {
				link.setAttribute('href', original);
				link.removeAttribute('data-awm-original-tel');
			}
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncTelephoneLinks);
	else syncTelephoneLinks();
	var configInFlight = false;
	var lastRefresh = 0;
	function refreshSettings() {
		if (!cfg.configEndpoint || !window.fetch || configInFlight || document.hidden) return;
		if (Date.now() - lastRefresh < 5000) return;
		lastRefresh = Date.now();
		configInFlight = true;
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timeout = window.setTimeout(function () { if (controller) controller.abort(); }, 8000);
		var endpoint = new URL(cfg.configEndpoint, window.location.href);
		endpoint.searchParams.set('_awm', String(Date.now()));
		fetch(endpoint.href, { credentials: 'same-origin', cache: 'no-store', signal: controller ? controller.signal : undefined })
			.then(function (response) { if (!response.ok) throw new Error('Settings unavailable'); return response.json(); })
			.then(function (settings) {
				var refreshedVisibility = settings.popupEnabled || { whatsapp: true, telephone: true };
				if (!Array.isArray(settings.emergencyRules) || typeof settings.trackingEnabled !== 'boolean' || typeof refreshedVisibility.whatsapp !== 'boolean' || typeof refreshedVisibility.telephone !== 'boolean') throw new Error('Invalid settings');
				emergencyRules = settings.emergencyRules;
				trackingEnabled = settings.trackingEnabled;
				popupEnabled = refreshedVisibility;
				if ( typeof settings.liveChatProvider === 'string' ) cfg.liveChatProvider = settings.liveChatProvider;
				syncTelephoneLinks();
				if (modal && !modal.hidden && activeParsed) {
					var updatedRule = findEmergencyRule(activeParsed);
					if (updatedRule) openEmergencyModal(updatedRule, lastFocused, activeParsed, false);
					else closeEmergencyModal();
				}
			})
			.catch(function () { trackingEnabled = false; })
			.then(function () { configInFlight = false; window.clearTimeout(timeout); });
	}
	refreshSettings();
	window.setInterval(refreshSettings, 60000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshSettings(); });
	window.addEventListener('pageshow', function (event) { if (event.persisted) { trackingEnabled = false; lastRefresh = 0; refreshSettings(); } });

	var modal = null;
	var activeRule = null;
	var activeParsed = null;
	var lastFocused = null;

	function createEmergencyModal() {
		if (modal) return modal;


		modal = document.createElement('div');
		modal.className = 'awm-emergency-overlay';
		modal.hidden = true;
		modal.innerHTML = '<div class="awm-emergency-dialog" role="dialog" aria-modal="true" aria-labelledby="awm-emergency-title" aria-describedby="awm-emergency-message" tabindex="-1"><button type="button" class="awm-emergency-close-x" aria-label="Close">&times;</button><p class="awm-emergency-kicker">Service notice</p><h2 class="awm-emergency-title" id="awm-emergency-title"></h2><p class="awm-emergency-message" id="awm-emergency-message"></p><div class="awm-emergency-actions"><button type="button" class="awm-emergency-button awm-emergency-primary"></button><button type="button" class="awm-emergency-button awm-emergency-secondary"></button><button type="button" class="awm-emergency-button awm-emergency-dismiss"></button></div></div>';
		document.body.appendChild(modal);

		modal.querySelector('.awm-emergency-close-x').addEventListener('click', closeEmergencyModal);
		// Reuse the former third-button slot, but closing remains on the X.
		var formPanel = document.createElement('div');
		formPanel.className = 'awm-emergency-form-panel';
		formPanel.hidden = true;
		formPanel.innerHTML = '<button type="button" class="awm-emergency-button awm-emergency-back"></button><iframe class="awm-emergency-form" title="Contact form" referrerpolicy="same-origin"></iframe>';
		modal.querySelector('.awm-emergency-dialog').appendChild(formPanel);
		modal.querySelector('.awm-emergency-back').addEventListener('click', function () { showNoticeContent(); modal.querySelector('.awm-emergency-dismiss').focus(); });
		modal.querySelector('.awm-emergency-dismiss').addEventListener('click', function () {
			if (!activeRule || activeRule.thirdEnabled !== true) return;
			if (activeRule.thirdAction === 'shortcode' && activeRule.thirdFormUrl) {
				var frame = modal.querySelector('.awm-emergency-form');
				frame.setAttribute('title', activeRule.thirdLabel || 'Contact form');
				// Only same-origin saved form documents may be loaded.
				var formUrl = new URL(activeRule.thirdFormUrl, window.location.href);
				if (formUrl.origin !== new URL(window.location.href).origin) return;
				if (cfg.language) formUrl.searchParams.set('lang', cfg.language);
				frame.setAttribute('src', formUrl.href);
				modal.querySelector('.awm-emergency-actions').hidden = true;
				modal.querySelector('.awm-emergency-message').hidden = true;
				formPanel.hidden = false;
				modal.querySelector('.awm-emergency-back').focus();
			} else if (activeRule.thirdAction === 'url' && activeRule.thirdUrl) {
				var url = activeRule.thirdUrl;
				closeEmergencyModal();
				window.location.assign(url);
			}
		});
		modal.querySelector('.awm-emergency-form').addEventListener('load', function () {
			try {
				var frameDocument = this.contentDocument;
				if (frameDocument) frameDocument.addEventListener('keydown', function (event) {
					if (event.key === 'Escape') { event.preventDefault(); closeEmergencyModal(); }
					if (event.key !== 'Tab') return;
					var controls = Array.prototype.slice.call(frameDocument.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')).filter(function (node) { return !node.disabled && node.tabIndex >= 0 && node.getClientRects().length; });
					if (!controls.length || (!event.shiftKey && frameDocument.activeElement === controls[controls.length - 1])) {
						event.preventDefault(); modal.querySelector('.awm-emergency-close-x').focus();
					} else if (event.shiftKey && frameDocument.activeElement === controls[0]) {
						event.preventDefault(); modal.querySelector('.awm-emergency-back').focus();
					}
				});
			} catch (e) { /* A form may redirect to another origin after submission. */ }
		});
		modal.addEventListener('click', function (event) {
			if (event.target === modal) closeEmergencyModal();
		});
		modal.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				closeEmergencyModal();
				return;
			}
			if (event.key !== 'Tab') return;
			var focusable = Array.prototype.slice.call(modal.querySelectorAll('button:not([hidden]), iframe')).filter(function (node) { return !node.closest('[hidden]'); });
			if (!focusable.length) return;
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (event.shiftKey && (document.activeElement === first || focusable.indexOf(document.activeElement) === -1)) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last && last.tagName !== 'IFRAME') {
				event.preventDefault();
				first.focus();
			}
		});

		modal.querySelector('.awm-emergency-primary').addEventListener('click', function () {
			if (!activeRule) return;
			pushDataLayer({
				event: activeParsed.channel === 'telephone' ? 'telephone_alternative_channel_click' : 'whatsapp_alternative_channel_click',
				contact_channel: activeParsed.channel,
				emergency_rule: activeRule.analyticsLabel || activeRule.id,
				alternative_channel: activeRule.primaryAction === 'live_chat' ? (cfg.liveChatProvider || 'live_chat') : 'primary_url',
				page_path: window.location.pathname
			});
			if (activeRule.primaryAction === 'live_chat') {
				try {
					if (cfg.liveChatProvider === 'tawk' && window.Tawk_API && typeof window.Tawk_API.maximize === 'function') {
						window.Tawk_API.maximize();
						closeEmergencyModal();
						return;
					}
					if (cfg.liveChatProvider === 'tawk' && window.Tawk_API && typeof window.Tawk_API.toggle === 'function') {
						window.Tawk_API.toggle();
						closeEmergencyModal();
						return;
					}
				} catch (e) {}
			}
			if (activeRule.primaryUrl) {
				var primaryUrl = activeRule.primaryUrl;
				closeEmergencyModal();
				window.location.assign(primaryUrl);
			}
		});

		modal.querySelector('.awm-emergency-secondary').addEventListener('click', function () {
			if (!activeRule || !activeRule.secondaryUrl) return;
			pushDataLayer({
				event: activeParsed.channel === 'telephone' ? 'telephone_alternative_channel_click' : 'whatsapp_alternative_channel_click',
				contact_channel: activeParsed.channel,
				emergency_rule: activeRule.analyticsLabel || activeRule.id,
				alternative_channel: 'secondary_url',
				page_path: window.location.pathname
			});
			var secondaryUrl = activeRule.secondaryUrl;
			closeEmergencyModal();
			window.location.assign(secondaryUrl);
		});

		return modal;
	}

	function closeEmergencyModal() {
		if (!modal || modal.hidden) return;
		showNoticeContent();
		modal.hidden = true;
		document.body.classList.remove('awm-emergency-open');
		activeRule = null;
		activeParsed = null;
		if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
	}

	function showNoticeContent() {
		if (!modal) return;
		modal.querySelector('.awm-emergency-form-panel').hidden = true;
		modal.querySelector('.awm-emergency-form').removeAttribute('src');
		modal.querySelector('.awm-emergency-actions').hidden = false;
		modal.querySelector('.awm-emergency-message').hidden = false;
	}

	function openEmergencyModal(rule, trigger, parsed, recordEvent) {
		var root = createEmergencyModal();
		root.querySelector('.awm-emergency-dialog').setAttribute('dir', cfg.direction === 'rtl' ? 'rtl' : 'ltr');
		var primary = root.querySelector('.awm-emergency-primary');
		var secondary = root.querySelector('.awm-emergency-secondary');
		var dismiss = root.querySelector('.awm-emergency-dismiss');
		// Do not reset an in-progress form on the periodic settings refresh.
		if (recordEvent !== false || (activeRule && (activeRule.id !== rule.id || rule.thirdEnabled !== true || activeRule.thirdFormUrl !== rule.thirdFormUrl || rule.thirdAction !== 'shortcode'))) showNoticeContent();
		activeRule = rule;
		activeParsed = parsed;
		lastFocused = trigger;
		root.querySelector('.awm-emergency-kicker').textContent = rule.kicker || 'Service notice';
		root.querySelector('.awm-emergency-title').textContent = rule.title || (parsed.channel === 'telephone' ? 'Telephone service temporarily unavailable' : 'WhatsApp is temporarily unavailable');
		root.querySelector('.awm-emergency-message').textContent = rule.message || '';
		primary.textContent = rule.primaryLabel || 'Go to Homepage';
		primary.hidden = !rule.primaryLabel || (rule.primaryAction !== 'tawk' && !rule.primaryUrl);
		secondary.textContent = rule.secondaryLabel || '';
		secondary.hidden = !rule.secondaryLabel || !rule.secondaryUrl;
		dismiss.textContent = rule.thirdLabel || '';
		dismiss.hidden = rule.thirdEnabled !== true || !rule.thirdLabel || !(rule.thirdAction === 'shortcode' ? rule.thirdFormUrl : rule.thirdUrl);
		root.querySelector('.awm-emergency-back').textContent = rule.backLabel || 'Back';
		root.querySelector('.awm-emergency-close-x').setAttribute('aria-label', rule.closeLabel || 'Close');
		document.body.classList.add('awm-emergency-open');
		root.hidden = false;
		if (recordEvent !== false) root.querySelector('.awm-emergency-close-x').focus();
		if (recordEvent === false) {
			if (document.activeElement && document.activeElement.hidden) root.querySelector('.awm-emergency-close-x').focus();
			return;
		}
		var popupPayload = {
			event: parsed.channel === 'telephone' ? 'telephone_emergency_popup' : 'whatsapp_emergency_popup',
			contact_channel: parsed.channel,
			emergency_rule: rule.analyticsLabel || rule.id,
			page_path: window.location.pathname
		};
		popupPayload[parsed.channel === 'telephone' ? 'telephone_destination' : 'whatsapp_destination'] = parsed.destination || 'unknown';
		pushDataLayer(popupPayload);
	}

	function send(payload) {
		if (!trackingEnabled || !cfg.endpoint) return;
		var body = JSON.stringify(payload);

		if (navigator.sendBeacon) {
			try {
				var blob = new Blob([body], { type: 'application/json' });
				if (navigator.sendBeacon(cfg.endpoint, blob)) return;
			} catch (e) {}
		}

		if (window.fetch) {
			fetch(cfg.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				keepalive: true,
				body: body
			}).catch(function () {});
		}
	}

	var lastTouchInterception = { link: null, time: 0 };
	function handleContactActivation(event) {
		var target = event.target;
		if (!target || !target.closest) return;

		var link = target.closest('a[href]');
		if (!link || link.getAttribute('data-awm-emergency-exempt') === '1') return;

		var parsed = parseTelephone(link.getAttribute('data-awm-original-tel') || link.getAttribute('href')) || parseWhatsApp(link.href);
		if (!parsed) return;

		var text = (link.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 180);
		var source = (link.getAttribute('data-awm-source') || link.getAttribute('data-whatsapp-source') || parsed.source || '').replace(/\s+/g, ' ').trim().slice(0, 191);
		var emergencyRule = findEmergencyRule(parsed);

		if (emergencyRule) {
			if (typeof event.preventDefault === 'function') event.preventDefault();
			event.stopImmediatePropagation();
			if (event.type === 'touchstart') {
				lastTouchInterception = { link: link, time: Date.now() };
				openEmergencyModal(emergencyRule, link, parsed);
				return;
			}
			if (lastTouchInterception.link === link && Date.now() - lastTouchInterception.time < 3000) return;
			if (event.type === 'touchend') lastTouchInterception = { link: link, time: Date.now() };
			openEmergencyModal(emergencyRule, link, parsed);
			return;
		}
		// A prevented touchend should not produce a synthetic click, but explicitly
		// cancel it if a mobile browser or another script dispatches one anyway.
		if (event.type === 'click' && lastTouchInterception.link === link && Date.now() - lastTouchInterception.time < 3000) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}
		// Normal links are tracked on click only, never on touchend, to avoid duplicates.
		if (event.type === 'touchstart' || event.type === 'touchend') return;
		if (parsed.host === 'managed-route' && !parsed.routeEnabled) {
			pushDataLayer({
				event: 'whatsapp_route_unavailable',
				whatsapp_destination: parsed.destination || 'unknown',
				whatsapp_source: source || 'unassigned',
				page_path: window.location.pathname
			});
			return;
		}

		if (parsed.channel === 'telephone') {
			pushDataLayer({ event: 'telephone_click', telephone_destination: parsed.destination, contact_channel: 'telephone', contact_source: source || 'unassigned', page_path: window.location.pathname });
			return;
		}

		if (trackingEnabled && Array.isArray(window.dataLayer)) {
			window.dataLayer.push({
				event: 'whatsapp_click',
				whatsapp_type: parsed.host,
				whatsapp_destination: parsed.destination || 'unknown',
				whatsapp_source: source || 'unassigned',
				page_path: window.location.pathname
			});
		}

		send({
			href: parsed.href,
			path: window.location.pathname,
			text: text,
			source: source
		});
	}
	document.addEventListener('touchstart', handleContactActivation, { capture: true, passive: false });
	document.addEventListener('touchend', handleContactActivation, { capture: true, passive: false });
	document.addEventListener('click', handleContactActivation, true);
})();
