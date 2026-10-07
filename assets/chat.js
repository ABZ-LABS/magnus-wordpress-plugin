/*!
 * Magnus Chat: the chat window. No build step and no dependencies.
 * Copyright 2026 Gonzalo Garategui. GPL-2.0-or-later.
 *
 * It talks only to this site (window.iamagnusChat.endpoint). The site adds the
 * Magnus key on the server; this file never sees it.
 */
(function (root, factory) {
	'use strict';
	var api = factory(root);
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else if (root && root.document) {
		api.start(root.iamagnusChat || null);
	}
})(typeof window !== 'undefined' ? window : this, function (win) {
	'use strict';

	var URL_RE = /\bhttps?:\/\/[^\s<>"'`]+/gi;
	var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
	// Punctuation that ends a sentence, not the link it follows.
	var TRAILING = /[).,;:!?\]}'"»]+$/;
	var KEY_VISITOR = 'iamagnusChat:visitor';
	var KEY_LOG = 'iamagnusChat:log';
	var KEY_OPEN = 'iamagnusChat:open';
	// While a person from the team has the conversation: the last reply seen.
	var KEY_HANDOFF = 'iamagnusChat:handoff';
	var MAX_LOG = 60;
	var TIMEOUT_MS = 60000;
	// How often to ask for the team's replies, and how long to back off when
	// the site says to slow down.
	var POLL_MS = 5000;
	var POLL_BACKOFF_MS = 30000;

	var SVG_ATTRS = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
	var ICONS = {
		chat: '<svg width="26" height="26" ' + SVG_ATTRS + '><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
		close: '<svg width="20" height="20" ' + SVG_ATTRS + '><path d="M18 6 6 18M6 6l12 12"/></svg>',
		restart: '<svg width="18" height="18" ' + SVG_ATTRS + '><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
		send: '<svg width="20" height="20" ' + SVG_ATTRS + '><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>'
	};

	/**
	 * Splits a text into plain parts and links, so a link can be clickable
	 * without ever turning the agent's text into HTML.
	 */
	function splitLinks(text) {
		var parts = [];
		var last = 0;
		var match;
		text = String(text);
		URL_RE.lastIndex = 0;
		while ((match = URL_RE.exec(text)) !== null) {
			var url = match[0].replace(TRAILING, '');
			if (match.index > last) {
				parts.push({ text: text.slice(last, match.index) });
			}
			if (/^https?:\/\/[^/?#\s]+/i.test(url) && url.replace(/^https?:\/\//i, '').length > 0) {
				parts.push({ text: url, href: url });
			} else {
				parts.push({ text: url });
			}
			last = match.index + url.length;
			URL_RE.lastIndex = last;
		}
		if (last < text.length) {
			parts.push({ text: text.slice(last) });
		}
		return parts;
	}

	function isUuid(value) {
		return typeof value === 'string' && UUID_RE.test(value);
	}

	function uuid4() {
		var c = (win && win.crypto) || (typeof crypto !== 'undefined' ? crypto : null);
		if (c && typeof c.randomUUID === 'function') {
			return c.randomUUID();
		}
		var b = new Uint8Array(16);
		if (c && typeof c.getRandomValues === 'function') {
			c.getRandomValues(b);
		} else {
			for (var i = 0; i < 16; i++) {
				b[i] = Math.floor(Math.random() * 256);
			}
		}
		b[6] = (b[6] & 0x0f) | 0x40;
		b[8] = (b[8] & 0x3f) | 0x80;
		var h = [];
		for (var j = 0; j < 16; j++) {
			h.push((b[j] + 0x100).toString(16).slice(1));
		}
		return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') + '-' +
			h.slice(8, 10).join('') + '-' + h.slice(10).join('');
	}

	function format(template, n) {
		return String(template || '').replace('%d', String(n));
	}

	// Storage can be missing or throw (private windows, blocked cookies): the
	// chat must still work, it only forgets more.
	function storage(kind) {
		try {
			var s = win[kind];
			s.setItem('__iamagnusChat', '1');
			s.removeItem('__iamagnusChat');
			return s;
		} catch (e) {
			return null;
		}
	}

	function readJSON(s, key, fallback) {
		if (!s) {
			return fallback;
		}
		try {
			var value = JSON.parse(s.getItem(key));
			return value === null || value === undefined ? fallback : value;
		} catch (e) {
			return fallback;
		}
	}

	function writeJSON(s, key, value) {
		if (!s) {
			return;
		}
		try {
			s.setItem(key, JSON.stringify(value));
		} catch (e) { /* full or blocked: keep going */ }
	}

	function el(tag, className, attrs) {
		var node = win.document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (attrs) {
			for (var name in attrs) {
				if (Object.prototype.hasOwnProperty.call(attrs, name) && attrs[name] !== null && attrs[name] !== undefined) {
					node.setAttribute(name, attrs[name]);
				}
			}
		}
		return node;
	}

	function iconButton(className, label, icon) {
		var button = el('button', className, { type: 'button', 'aria-label': label, title: label });
		button.innerHTML = icon; // a constant from ICONS, never user text
		return button;
	}

	function Chat(mount, config, mode) {
		this.mount = mount;
		this.config = config;
		this.mode = mode;
		this.i18n = config.i18n || {};
		this.local = storage('localStorage');
		this.session = storage('sessionStorage');
		this.visitor = this.local ? this.local.getItem(KEY_VISITOR) : null;
		if (!isUuid(this.visitor)) {
			this.visitor = uuid4();
			this.saveVisitor();
		}
		var log = readJSON(this.session, KEY_LOG, []);
		this.log = Array.isArray(log) ? log : [];
		this.busy = false;
		this.typing = null;
		// Bumped by "New conversation", so a reply that arrives later is dropped.
		this.generation = 0;
		this.pending = null;
		this.following = false;
		this.pollTimer = null;
		var handoff = readJSON(this.session, KEY_HANDOFF, null);
		this.after = handoff && typeof handoff.after === 'string' ? handoff.after : null;
		this.build();
		this.renderLog();
		if (mode === 'floating') {
			this.setOpen(readJSON(this.session, KEY_OPEN, false) === true, false);
		}
		// A reload in the middle of a handoff keeps listening for the team.
		if (handoff && handoff.active === true) {
			this.follow();
		}
	}

	Chat.prototype.saveVisitor = function () {
		if (this.local) {
			try {
				this.local.setItem(KEY_VISITOR, this.visitor);
			} catch (e) { /* keep the id for this page only */ }
		}
	};

	Chat.prototype.build = function () {
		var self = this;
		var config = this.config;
		var side = config.position === 'left' ? 'left' : 'right';
		var root = el('div', 'iamagnus-chat iamagnus-chat--' + this.mode + ' iamagnus-chat--' + side);
		if (config.color) {
			root.style.setProperty('--iamagnus-accent', config.color);
		}

		var panelId = 'iamagnus-chat-panel-' + uuid4().slice(0, 8);
		var panel = el('div', 'iamagnus-chat__panel', {
			id: panelId,
			role: this.mode === 'floating' ? 'dialog' : 'region',
			'aria-label': config.title || ''
		});

		var header = el('div', 'iamagnus-chat__header');
		var title = el('h2', 'iamagnus-chat__title');
		title.textContent = config.title || '';
		header.appendChild(title);
		var restart = iconButton('iamagnus-chat__icon', this.i18n.newConversation || '', ICONS.restart);
		restart.addEventListener('click', function () {
			self.restart();
		});
		header.appendChild(restart);
		if (this.mode === 'floating') {
			var close = iconButton('iamagnus-chat__icon', this.i18n.close || '', ICONS.close);
			close.addEventListener('click', function () {
				self.setOpen(false, true);
			});
			header.appendChild(close);
		}

		var messages = el('div', 'iamagnus-chat__messages', { role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });

		var form = el('form', 'iamagnus-chat__form');
		var input = el('textarea', 'iamagnus-chat__input', {
			rows: '1',
			placeholder: config.placeholder || '',
			'aria-label': config.placeholder || this.i18n.send || '',
			maxlength: String(config.maxLength || 2000)
		});
		var send = el('button', 'iamagnus-chat__send', { type: 'submit', 'aria-label': this.i18n.send || '', title: this.i18n.send || '' });
		send.innerHTML = ICONS.send;
		form.appendChild(input);
		form.appendChild(send);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			self.send();
		});
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
				event.preventDefault();
				self.send();
			}
		});
		input.addEventListener('input', function () {
			self.resize();
		});

		panel.appendChild(header);
		panel.appendChild(messages);
		panel.appendChild(form);

		if (this.mode === 'floating') {
			var launcher = iconButton('iamagnus-chat__launcher', this.i18n.open || '', ICONS.chat);
			launcher.setAttribute('aria-expanded', 'false');
			launcher.setAttribute('aria-controls', panelId);
			launcher.addEventListener('click', function () {
				self.setOpen(panel.hidden, true);
			});
			panel.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					self.setOpen(false, true);
				}
			});
			root.appendChild(panel);
			root.appendChild(launcher);
			this.launcher = launcher;
		} else {
			root.appendChild(panel);
		}

		this.root = root;
		this.panel = panel;
		this.messages = messages;
		this.input = input;
		this.sendButton = send;
		this.mount.appendChild(root);
	};

	Chat.prototype.setOpen = function (open, focus) {
		this.panel.hidden = !open;
		this.root.classList.toggle('is-open', !!open);
		if (this.launcher) {
			this.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
		writeJSON(this.session, KEY_OPEN, !!open);
		if (focus) {
			if (open) {
				this.input.focus();
				this.scroll();
			} else if (this.launcher) {
				this.launcher.focus();
			}
		}
	};

	Chat.prototype.resize = function () {
		this.input.style.height = 'auto';
		this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
	};

	Chat.prototype.scroll = function () {
		this.messages.scrollTop = this.messages.scrollHeight;
	};

	Chat.prototype.bubble = function (role, text, retry) {
		var node = el('div', 'iamagnus-chat__msg iamagnus-chat__msg--' + role);
		if (role !== 'error') {
			var who = el('span', 'iamagnus-chat__sr');
			who.textContent = (role === 'user' ? this.i18n.you : role === 'team' ? this.i18n.team : this.i18n.assistant) + ': ';
			node.appendChild(who);
		}
		if (role === 'team') {
			// Seen, not read twice: the screen-reader label above already says it.
			var label = el('span', 'iamagnus-chat__who', { 'aria-hidden': 'true' });
			label.textContent = this.i18n.team || '';
			node.appendChild(label);
		}
		if (role === 'bot' || role === 'team') {
			var parts = splitLinks(text);
			for (var i = 0; i < parts.length; i++) {
				if (parts[i].href) {
					var a = el('a', null, { href: parts[i].href, target: '_blank', rel: 'noopener noreferrer nofollow' });
					a.textContent = parts[i].text;
					node.appendChild(a);
				} else {
					node.appendChild(win.document.createTextNode(parts[i].text));
				}
			}
		} else {
			node.appendChild(win.document.createTextNode(text));
		}
		if (retry) {
			var again = el('button', 'iamagnus-chat__retry', { type: 'button' });
			again.textContent = this.i18n.retry || 'Retry';
			again.addEventListener('click', function () {
				if (node.parentNode) {
					node.parentNode.removeChild(node);
				}
				retry();
			});
			node.appendChild(win.document.createTextNode(' '));
			node.appendChild(again);
		}
		this.messages.appendChild(node);
		this.scroll();
		return node;
	};

	Chat.prototype.renderLog = function () {
		this.messages.textContent = '';
		if (this.config.welcome) {
			this.bubble('bot', this.config.welcome);
		}
		for (var i = 0; i < this.log.length; i++) {
			var item = this.log[i];
			if (item && (item.role === 'user' || item.role === 'bot' || item.role === 'team') && typeof item.text === 'string') {
				this.bubble(item.role, item.text);
			}
		}
	};

	// Errors are shown but not kept: reloading the page should not replay them.
	Chat.prototype.add = function (role, text, retry) {
		this.bubble(role, text, retry);
		if (role === 'user' || role === 'bot' || role === 'team') {
			this.log.push({ role: role, text: text });
			if (this.log.length > MAX_LOG) {
				this.log = this.log.slice(-MAX_LOG);
			}
			writeJSON(this.session, KEY_LOG, this.log);
		}
	};

	// aria-disabled rather than disabled: disabling the focused button would
	// drop keyboard and screen-reader focus to the page. send() ignores clicks
	// while busy.
	Chat.prototype.setBusy = function (busy) {
		this.busy = busy;
		this.sendButton.setAttribute('aria-disabled', busy ? 'true' : 'false');
		if (busy && !this.typing) {
			this.typing = this.bubble('bot', this.i18n.typing || '…');
			this.typing.classList.add('iamagnus-chat__typing');
		} else if (!busy && this.typing) {
			if (this.typing.parentNode) {
				this.typing.parentNode.removeChild(this.typing);
			}
			this.typing = null;
		}
	};

	// A new visitor id is a new thread in Magnus: the agent starts over. A
	// reply still on its way belongs to the old thread and is dropped.
	Chat.prototype.restart = function () {
		this.generation += 1;
		if (this.pending) {
			this.pending.abort();
			this.pending = null;
		}
		this.stopFollowing();
		this.after = null;
		this.saveHandoff();
		this.setBusy(false);
		this.visitor = uuid4();
		this.saveVisitor();
		this.log = [];
		writeJSON(this.session, KEY_LOG, this.log);
		this.renderLog();
		this.input.focus();
	};

	Chat.prototype.send = function () {
		if (this.busy) {
			return;
		}
		var text = this.input.value.trim();
		if (!text) {
			return;
		}
		var max = this.config.maxLength || 2000;
		if (text.length > max) {
			this.add('error', format(this.i18n.tooLong, max));
			return;
		}
		this.input.value = '';
		this.resize();
		this.add('user', text);
		this.deliver(text, uuid4());
	};

	// One attempt at a turn. A retry passes the same turn id, so if the first
	// attempt did reach Magnus, Magnus replays its answer instead of running
	// the turn (and its tools) a second time.
	Chat.prototype.deliver = function (text, turn) {
		var self = this;
		var generation = this.generation;
		var visitor = this.visitor;
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timedOut = false;
		var timer = controller ? setTimeout(function () {
			timedOut = true;
			controller.abort();
		}, TIMEOUT_MS) : null;
		var retry = function () {
			self.deliver(text, turn);
		};
		this.pending = controller;
		this.setBusy(true);

		win.fetch(this.config.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ message: text, visitor: visitor, turn: turn }),
			credentials: 'omit',
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				return { ok: response.ok, status: response.status, data: data || {} };
			});
		}).then(function (result) {
			if (generation !== self.generation) {
				return;
			}
			var data = result.data;
			if (isUuid(data.visitor) && data.visitor !== self.visitor && visitor === self.visitor) {
				self.visitor = data.visitor;
				self.saveVisitor();
			}
			if (result.ok && typeof data.reply === 'string' && data.reply) {
				self.add('bot', data.reply);
				if (data.handoff === true) {
					self.follow();
				}
				return;
			}
			var retryable = result.status === 429 || (result.status >= 500 && data.code !== 'not_configured');
			self.add('error', (typeof data.message === 'string' && data.message) || self.i18n.error, retryable ? retry : null);
		}).catch(function (error) {
			if (generation !== self.generation) {
				return;
			}
			var aborted = error && error.name === 'AbortError';
			self.add('error', aborted && timedOut ? self.i18n.error : self.i18n.offline, retry);
		}).then(function () {
			if (timer) {
				clearTimeout(timer);
			}
			if (generation === self.generation) {
				self.pending = null;
				self.setBusy(false);
			}
		});
	};

	// A person from the team has the conversation: their replies are not the
	// answer to any message, so the window asks for them until the agent is back.
	Chat.prototype.follow = function () {
		if (this.following || !this.config.updates) {
			return;
		}
		this.following = true;
		this.saveHandoff();
		this.poll();
	};

	Chat.prototype.stopFollowing = function () {
		this.following = false;
		if (this.pollTimer) {
			clearTimeout(this.pollTimer);
			this.pollTimer = null;
		}
		this.saveHandoff();
	};

	Chat.prototype.saveHandoff = function () {
		writeJSON(this.session, KEY_HANDOFF, { active: this.following, after: this.after });
	};

	Chat.prototype.poll = function () {
		var self = this;
		var generation = this.generation;
		var base = this.config.updates;
		var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 'visitor=' + encodeURIComponent(this.visitor) +
			(this.after ? '&after=' + encodeURIComponent(this.after) : '');
		var again = function (ms) {
			if (generation === self.generation && self.following) {
				self.pollTimer = setTimeout(function () {
					self.pollTimer = null;
					self.poll();
				}, ms);
			}
		};
		win.fetch(url, {
			method: 'GET',
			headers: { Accept: 'application/json' },
			credentials: 'omit'
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				return { ok: response.ok, status: response.status, data: data || {} };
			});
		}).then(function (result) {
			if (generation !== self.generation || !self.following) {
				return;
			}
			if (!result.ok) {
				again(result.status === 429 ? POLL_BACKOFF_MS : self.pollMs());
				return;
			}
			var messages = Array.isArray(result.data.messages) ? result.data.messages : [];
			for (var i = 0; i < messages.length; i++) {
				var m = messages[i];
				if (m && typeof m.content === 'string' && m.content) {
					self.add('team', m.content);
				}
				if (m && typeof m.id === 'string') {
					self.after = m.id;
				}
			}
			if (result.data.handoff === true) {
				self.saveHandoff();
				again(self.pollMs());
			} else {
				self.stopFollowing();
			}
		}).catch(function () {
			again(self.pollMs());
		});
	};

	Chat.prototype.pollMs = function () {
		var ms = Number(this.config.pollMs);
		return ms > 0 ? ms : POLL_MS;
	};

	// One chat per page: a chat inside the page wins over the corner button.
	function start(config) {
		if (!config || !config.endpoint || !win || !win.document) {
			return;
		}
		var doc = win.document;
		var go = function () {
			var mounts = doc.querySelectorAll('.iamagnus-chat-mount');
			var inline = null;
			var floating = null;
			for (var i = 0; i < mounts.length; i++) {
				var mode = mounts[i].getAttribute('data-mode');
				if (mode === 'inline' && !inline) {
					inline = mounts[i];
				} else if (mode === 'floating' && !floating) {
					floating = mounts[i];
				}
			}
			if (inline) {
				return new Chat(inline, config, 'inline');
			}
			if (floating) {
				return new Chat(floating, config, 'floating');
			}
			return null;
		};
		if (doc.readyState === 'loading') {
			doc.addEventListener('DOMContentLoaded', go);
		} else {
			go();
		}
	}

	return { splitLinks: splitLinks, isUuid: isUuid, uuid4: uuid4, format: format, start: start };
});
