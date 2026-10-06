// The chat window, tested in Node: the pure helpers directly, and the window
// itself in a simulated browser (jsdom) with a fake endpoint.
//
//     npm test

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');

const chat = require('../../assets/chat.js');
const SOURCE = fs.readFileSync(path.join(__dirname, '../../assets/chat.js'), 'utf8');

// --- helpers -------------------------------------------------------------------

test('a link is split from the text around it', () => {
	assert.deepEqual(chat.splitLinks('Mirá https://iamagnus.com/precios.'), [
		{ text: 'Mirá ' },
		{ text: 'https://iamagnus.com/precios', href: 'https://iamagnus.com/precios' },
		{ text: '.' }
	]);
});

test('parentheses and closing punctuation stay outside the link', () => {
	assert.deepEqual(chat.splitLinks('(ver https://a.com/x?y=1),'), [
		{ text: '(ver ' },
		{ text: 'https://a.com/x?y=1', href: 'https://a.com/x?y=1' },
		{ text: '),' }
	]);
});

test('only http and https become links', () => {
	for (const text of ['javascript:alert(1)', 'data:text/html,<b>x</b>', 'ftp://a.com/x', 'xhttps://a.com']) {
		assert.ok(chat.splitLinks(text).every((part) => !part.href), text);
	}
});

test('a text without links comes back whole', () => {
	assert.deepEqual(chat.splitLinks('Hola, ¿qué tal?'), [{ text: 'Hola, ¿qué tal?' }]);
	assert.deepEqual(chat.splitLinks(''), []);
});

test('uuid4 makes version 4 UUIDs, and isUuid accepts only those', () => {
	for (let i = 0; i < 200; i++) {
		assert.ok(chat.isUuid(chat.uuid4()));
	}
	assert.equal(chat.isUuid('not-a-uuid'), false);
	assert.equal(chat.isUuid('6f9619ff-8b86-d011-b42d-00c04fc964ff'), false); // version 1
	assert.equal(chat.isUuid(null), false);
});

test('format fills the number', () => {
	assert.equal(chat.format('Up to %d characters.', 2000), 'Up to 2000 characters.');
});

// --- the window ------------------------------------------------------------------

const CONFIG = {
	endpoint: 'https://site.test/wp-json/iamagnus-chat/v1/message',
	title: 'Chat with us',
	welcome: 'Hi! How can I help you?',
	placeholder: 'Type your message…',
	color: '#123456',
	position: 'right',
	maxLength: 50,
	i18n: {
		open: 'Open the chat', close: 'Close the chat', send: 'Send', newConversation: 'New conversation',
		typing: 'Writing…', error: 'Generic error', offline: 'Offline', tooLong: 'Up to %d characters.',
		you: 'You', assistant: 'Assistant', retry: 'Retry'
	}
};

// A page with the given mounts, the configuration and the script, and a fetch
// that answers with `reply(request)`. The script runs once the document is
// parsed, as a deferred script does in a browser.
async function page(mounts, reply, config = CONFIG) {
	const dom = new JSDOM(`<!doctype html><body>${mounts}</body>`, {
		url: 'https://site.test/',
		runScripts: 'outside-only',
		pretendToBeVisual: true
	});
	const { window } = dom;
	const requests = [];
	window.fetch = (url, init) => {
		const body = JSON.parse(init.body);
		requests.push({ url, init, body });
		const answer = reply(body, requests.length);
		if (answer instanceof Error) {
			return Promise.reject(answer);
		}
		return Promise.resolve({
			ok: answer.status >= 200 && answer.status < 300,
			status: answer.status,
			json: () => Promise.resolve(answer.json)
		});
	};
	if (window.document.readyState === 'loading') {
		await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve));
	}
	window.iamagnusChat = config;
	window.eval(SOURCE);
	return { window, document: window.document, requests };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function type(doc, text) {
	const input = doc.querySelector('.iamagnus-chat__input');
	input.value = text;
	doc.querySelector('.iamagnus-chat__form').dispatchEvent(new doc.defaultView.Event('submit', { cancelable: true }));
	for (let i = 0; i < 5; i++) {
		await settle();
	}
}

// What a sighted visitor reads in each bubble: without the screen-reader label
// ("You: ") and without the label of a Retry button.
function bubbles(doc) {
	return [...doc.querySelectorAll('.iamagnus-chat__msg')].map((node) => {
		const copy = node.cloneNode(true);
		copy.querySelectorAll('.iamagnus-chat__sr, .iamagnus-chat__retry').forEach((n) => n.remove());
		return {
			role: node.className.replace(/.*iamagnus-chat__msg--(\w+).*/, '$1'),
			text: copy.textContent.replace(/\s+$/, '')
		};
	});
}

test('the corner button opens and closes the window', async () => {
	const { document } = await page('<div class="iamagnus-chat-mount" data-mode="floating"></div>', () => ({}));
	const launcher = document.querySelector('.iamagnus-chat__launcher');
	const panel = document.querySelector('.iamagnus-chat__panel');
	assert.ok(launcher, 'there is a launcher');
	assert.equal(panel.hidden, true, 'closed at first');
	launcher.click();
	assert.equal(panel.hidden, false);
	assert.equal(launcher.getAttribute('aria-expanded'), 'true');
	document.querySelector('.iamagnus-chat__header button[aria-label="Close the chat"]').click();
	assert.equal(panel.hidden, true);
	assert.equal(document.activeElement, launcher, 'focus goes back to the button');
});

test('a chat inside the page wins over the corner button', async () => {
	const { document } = await page(
		'<div class="iamagnus-chat-mount" data-mode="inline"></div><div class="iamagnus-chat-mount" data-mode="floating"></div>',
		() => ({})
	);
	assert.equal(document.querySelectorAll('.iamagnus-chat').length, 1);
	assert.ok(document.querySelector('.iamagnus-chat--inline'));
	assert.equal(document.querySelector('.iamagnus-chat__launcher'), null);
});

test('the welcome message and the color come from the configuration', async () => {
	const { document } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({}));
	assert.deepEqual(bubbles(document), [{ role: 'bot', text: 'Hi! How can I help you?' }]);
	assert.equal(document.querySelector('.iamagnus-chat').style.getPropertyValue('--iamagnus-accent'), '#123456');
	assert.equal(document.querySelector('.iamagnus-chat__title').textContent, 'Chat with us');
});

test('a message goes to the endpoint and the reply is shown', async () => {
	const { document, requests } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 200,
		json: { reply: 'Hello! See https://iamagnus.com.', visitor: null }
	}));
	await type(document, '  Hi there  ');
	assert.equal(requests.length, 1);
	assert.equal(requests[0].url, CONFIG.endpoint);
	assert.equal(requests[0].init.method, 'POST');
	assert.equal(requests[0].init.credentials, 'omit', 'no cookies to the endpoint');
	assert.equal(requests[0].body.message, 'Hi there');
	assert.ok(chat.isUuid(requests[0].body.visitor));
	assert.ok(chat.isUuid(requests[0].body.turn));
	assert.deepEqual(bubbles(document).slice(1), [
		{ role: 'user', text: 'Hi there' },
		{ role: 'bot', text: 'Hello! See https://iamagnus.com.' }
	]);
	const link = document.querySelector('.iamagnus-chat__msg--bot:last-child a');
	assert.equal(link.getAttribute('href'), 'https://iamagnus.com');
	assert.equal(link.getAttribute('rel'), 'noopener noreferrer nofollow');
	assert.equal(document.querySelector('.iamagnus-chat__input').value, '', 'the box is emptied');
	assert.equal(document.querySelector('.iamagnus-chat__send').disabled, false, 'ready for the next one');
});

test('the agent\'s text is never parsed as HTML', async () => {
	const { document } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 200,
		json: { reply: '<img src=x onerror="window.pwned=1">Hi' }
	}));
	await type(document, 'Hi');
	assert.equal(document.querySelector('.iamagnus-chat__messages img'), null);
	assert.equal(document.defaultView.pwned, undefined);
	assert.match(bubbles(document).pop().text, /<img src=x/);
});

test('the same visitor id is sent on every turn, and kept across pages', async () => {
	const answer = () => ({ status: 200, json: { reply: 'ok' } });
	const first = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', answer);
	await type(first.document, 'one');
	await type(first.document, 'two');
	assert.equal(first.requests[0].body.visitor, first.requests[1].body.visitor);
	assert.notEqual(first.requests[0].body.turn, first.requests[1].body.turn, 'each turn has its own id');
	assert.equal(first.window.localStorage.getItem('iamagnusChat:visitor'), first.requests[0].body.visitor);
	assert.equal(JSON.parse(first.window.sessionStorage.getItem('iamagnusChat:log')).length, 4);
});

test('"New conversation" starts a new thread and clears the window', async () => {
	const { document, requests } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 200,
		json: { reply: 'ok' }
	}));
	await type(document, 'one');
	document.querySelector('.iamagnus-chat__header button[aria-label="New conversation"]').click();
	assert.deepEqual(bubbles(document), [{ role: 'bot', text: 'Hi! How can I help you?' }]);
	await type(document, 'two');
	assert.notEqual(requests[0].body.visitor, requests[1].body.visitor);
});

test('a refusal shows the server\'s message, not the agent\'s voice', async () => {
	const { document } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 429,
		json: { code: 'rate_limited', message: 'Too many questions right now.' }
	}));
	await type(document, 'Hi');
	assert.deepEqual(bubbles(document).pop(), { role: 'error', text: 'Too many questions right now.' });
});

test('a network failure says so, and errors are not kept in the log', async () => {
	const { document, window } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => new TypeError('Failed to fetch'));
	await type(document, 'Hi');
	assert.deepEqual(bubbles(document).pop(), { role: 'error', text: 'Offline' });
	assert.deepEqual(JSON.parse(window.sessionStorage.getItem('iamagnusChat:log')), [{ role: 'user', text: 'Hi' }]);
});

test('a message over the limit is refused before it is sent', async () => {
	const { document, requests } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({}));
	await type(document, 'x'.repeat(51));
	assert.equal(requests.length, 0);
	assert.deepEqual(bubbles(document).pop(), { role: 'error', text: 'Up to 50 characters.' });
});

test('Enter sends and Shift+Enter does not', async () => {
	const { document, requests } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 200,
		json: { reply: 'ok' }
	}));
	const input = document.querySelector('.iamagnus-chat__input');
	const KeyboardEvent = document.defaultView.KeyboardEvent;
	input.value = 'line';
	input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true }));
	await settle();
	assert.equal(requests.length, 0);
	input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
	for (let i = 0; i < 5; i++) {
		await settle();
	}
	assert.equal(requests.length, 1);
});

test('without a configuration nothing is drawn', async () => {
	const { document } = await page('<div class="iamagnus-chat-mount" data-mode="floating"></div>', () => ({}), null);
	assert.equal(document.querySelector('.iamagnus-chat'), null);
});

test('"New conversation" while a reply is on its way drops that reply', async () => {
	let release;
	const late = new Promise((resolve) => {
		release = resolve;
	});
	const { document, window, requests } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', (body, n) =>
		n === 1 ? late : { status: 200, json: { reply: 'fresh' } }
	);
	// The fake fetch resolves with whatever reply() returns; a pending promise keeps it waiting.
	window.fetch = ((original) => (url, init) => {
		const body = JSON.parse(init.body);
		requests.push({ url, init, body });
		if (requests.length === 1) {
			return late.then((answer) => ({ ok: true, status: 200, json: () => Promise.resolve(answer) }));
		}
		return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ reply: 'fresh' }) });
	})(window.fetch);
	await type(document, 'one');
	const oldVisitor = requests[0].body.visitor;
	document.querySelector('.iamagnus-chat__header button[aria-label="New conversation"]').click();
	release({ reply: 'late answer to the old thread', visitor: oldVisitor });
	for (let i = 0; i < 5; i++) {
		await settle();
	}
	assert.deepEqual(bubbles(document), [{ role: 'bot', text: 'Hi! How can I help you?' }], 'the late reply is not shown');
	assert.equal(document.querySelector('.iamagnus-chat__typing'), null, 'no typing indicator left behind');
	assert.notEqual(window.localStorage.getItem('iamagnusChat:visitor'), oldVisitor, 'the old visitor id is not adopted back');
	await type(document, 'two');
	assert.notEqual(requests[1].body.visitor, oldVisitor);
	assert.deepEqual(bubbles(document).pop(), { role: 'bot', text: 'fresh' });
});

test('a failed turn can be retried with the same turn id, without a second bubble', async () => {
	const { document, requests } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', (body, n) =>
		n === 1 ? new TypeError('Failed to fetch') : { status: 200, json: { reply: 'made it' } }
	);
	await type(document, 'Hi');
	const retry = document.querySelector('.iamagnus-chat__retry');
	assert.ok(retry, 'the error offers a retry');
	retry.click();
	for (let i = 0; i < 5; i++) {
		await settle();
	}
	assert.equal(requests.length, 2);
	assert.equal(requests[1].body.turn, requests[0].body.turn, 'the same turn id, so Magnus replays instead of running twice');
	assert.equal(requests[1].body.message, 'Hi');
	assert.deepEqual(bubbles(document).slice(1), [
		{ role: 'user', text: 'Hi' },
		{ role: 'bot', text: 'made it' }
	]);
});

test('only failures worth retrying offer a retry', async () => {
	const busy = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 429,
		json: { code: 'rate_limited', message: 'Busy.' }
	}));
	await type(busy.document, 'Hi');
	assert.ok(busy.document.querySelector('.iamagnus-chat__retry'), 'a 429 can be retried');
	const refused = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({
		status: 400,
		json: { code: 'reserved', message: 'Not like that.' }
	}));
	await type(refused.document, '/bot');
	assert.equal(refused.document.querySelector('.iamagnus-chat__retry'), null, 'a refusal cannot');
});

test('while waiting, the send button stays focusable', async () => {
	let release;
	const { document, window } = await page('<div class="iamagnus-chat-mount" data-mode="inline"></div>', () => ({}));
	window.fetch = () => new Promise((resolve) => {
		release = () => resolve({ ok: true, status: 200, json: () => Promise.resolve({ reply: 'ok' }) });
	});
	const send = document.querySelector('.iamagnus-chat__send');
	send.focus();
	await type(document, 'Hi');
	assert.equal(send.getAttribute('aria-disabled'), 'true');
	assert.equal(send.disabled, false, 'not disabled: that would drop focus to the page');
	assert.equal(document.activeElement, send);
	release();
	for (let i = 0; i < 5; i++) {
		await settle();
	}
	assert.equal(send.getAttribute('aria-disabled'), 'false');
});
