'use strict';

// Execute the shipped script with a simulated browser clock, DOM, and HTTP.
// No Google interaction, credentials, database, or network is used by this proof.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/google-auth.js'), 'utf8');
const epoch = Date.parse('2026-09-17T00:00:00Z');
const expiry = seconds => new Date(epoch + seconds * 1000).toISOString().replace('T', ' ').replace('Z', '');
const refreshed = seconds => ({ok: true, google: {
    transaction_id: 'new-transaction', state: 'new-state', nonce: 'new-nonce', expires_at: expiry(seconds),
}});

function browser(seconds, responses) {
    let now = epoch, serial = 0, initializer;
    const timers = new Map(), events = {}, requests = [], buttons = [], redirects = [];
    const root = {dataset: {
        clientId: 'fixture', transactionId: 'old-transaction', state: 'old-state', nonce: 'old-nonce',
        expiresAt: expiry(seconds), csrfToken: 'fixture', endpoint: '/credential', refreshEndpoint: '/refresh',
    }, getBoundingClientRect: () => ({width: 320})};
    const message = {textContent: '', classList: {toggle() {}}};
    const elements = {'fitcrew-google-auth': root, 'fitcrew-google-button': {replaceChildren() {}}, 'fitcrew-google-message': message};
    const document = {hidden: false, getElementById: id => elements[id], addEventListener: (name, fn) => { events[name] = fn; }};
    class Clock extends Date { static now() { return now; } }
    const window = {
        google: {accounts: {id: {initialize: options => buttons.push(options), renderButton() {}}}},
        setTimeout(fn, delay) { const id = ++serial; timers.set(id, {fn, at: now + delay}); return id; },
        clearTimeout: id => timers.delete(id), setInterval: fn => { initializer = fn; return ++serial; },
        clearInterval() {}, location: {assign: url => redirects.push(url)},
    };
    vm.runInNewContext(source, {window, document, Date: Clock, FormData, fetch: async (url, options) => {
        requests.push({url, at: now, body: Object.fromEntries(options.body.entries())});
        const response = responses.shift();
        if (response instanceof Error) throw response;
        assert.ok(response, 'Unexpected extra request');
        return {ok: response.ok !== false, json: async () => response};
    }}, {filename: 'assets/js/google-auth.js'});
    initializer();
    return {
        requests, buttons, redirects, message, timers,
        nextAt: () => Math.min(...[...timers.values()].map(t => t.at)),
        async advance(secondsFromStart) {
            now = epoch + secondsFromStart * 1000;
            for (let guard = 0; guard < 10; guard++) {
                const due = [...timers.entries()].find(([, timer]) => timer.at <= now);
                if (!due) return;
                timers.delete(due[0]); await due[1].fn();
            }
            throw new Error('Repeated immediate timer execution');
        },
        async visible() { events.visibilitychange(); await new Promise(resolve => setImmediate(resolve)); },
        async click(credential = 'fresh-user-click') {
            await buttons.at(-1).callback({credential, state: buttons.length > 1 ? 'new-state' : 'old-state'});
        },
    };
}

(async () => {
    const capped = browser(600, [refreshed(585), {ok: false, message: 'Invitation expired.'}]);
    assert.equal(capped.nextAt(), epoch + 540000);
    await capped.advance(540);
    assert.equal(capped.requests.length, 1);
    assert.equal(capped.nextAt(), epoch + 600000, 'Short refreshed lifetime must not produce a zero-delay loop');
    assert.equal(capped.buttons.at(-1).nonce, 'new-nonce');
    assert.equal(capped.requests[0].body.transaction_id, 'old-transaction');
    assert.equal(capped.redirects.length, 0);
    for (let i = 0; i < 10; i++) await capped.visible();
    await capped.advance(599);
    assert.equal(capped.requests.length, 1, 'Visibility and timers must share the cooldown');
    await capped.advance(600);
    assert.equal(capped.requests.length, 2);
    assert.equal(capped.requests[1].body.transaction_id, 'new-transaction');
    assert.equal(capped.timers.size, 0, 'A rejected refresh must not create a retry loop');
    assert.equal(capped.message.textContent, 'Invitation expired.');

    const short = browser(45, [refreshed(45)]);
    await short.advance(0);
    assert.equal(short.requests.length, 1);
    assert.equal(short.nextAt(), epoch + 60000);

    const failed = browser(45, [new Error('Offline')]);
    await failed.advance(0);
    for (let i = 0; i < 10; i++) await failed.visible();
    assert.equal(failed.requests.length, 1, 'Network failures must also be rate bounded');

    const clicked = browser(600, [{...refreshed(45), refresh_required: true}, {ok: true, redirect: '/app.php'}]);
    await clicked.click('expired-transaction-credential');
    assert.equal(clicked.requests.length, 1, 'A replacement must wait for a NEW Google click');
    assert.equal(clicked.nextAt(), epoch + 60000);
    assert.equal(clicked.redirects.length, 0);
    await clicked.click();
    assert.equal(clicked.requests[1].body.transaction_id, 'new-transaction');
    assert.equal(clicked.requests[1].body.state, 'new-state');
    assert.equal(clicked.requests[1].body.credential, 'fresh-user-click');
    assert.deepEqual(clicked.redirects, ['/app.php']);
    console.log('Google Auth browser-script behavior proof: PASS');
    console.log('- normal scheduling / short-expiry cooldown / visibility and failure bounds: PASS');
    console.log('- fresh nonce and state / new explicit click / server response authority: PASS');
})().catch(error => { console.error('[FAIL]', error.message); process.exitCode = 1; });
