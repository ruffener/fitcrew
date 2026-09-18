'use strict';
// Executable DOM-behavior harness; this is not a rendered-browser visual proof.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/auth-email.js'), 'utf8');
for (const native of [true, false]) {
    const handlers = {};
    let focused = 0;
    const calls = [];
    const dialog = {
        addEventListener(name, fn) { handlers[name] = fn; },
        querySelector(selector) { assert.equal(selector, 'button[type="submit"]'); return {focus() { focused++; }}; },
    };
    const shade = {hidden:false};
    if (native) Object.assign(dialog, {close() { calls.push('close'); }, showModal() { calls.push('showModal'); }});
    vm.runInNewContext(source, {document:{getElementById(id) { assert.equal(id,'fitcrew-email-request-ack');return dialog; }, querySelector() { return shade; }}});
    assert.equal(focused, 1);
    let prevented = false;
    handlers.cancel({preventDefault() { prevented = true; }});
    assert.equal(prevented, true, 'Escape dismissed acknowledgement');
    assert.deepEqual(calls, native ? ['close','showModal'] : []);
    assert.equal(shade.hidden, native);
    assert.equal(handlers.click, undefined, 'Backdrop dismissal added');
}
vm.runInNewContext(source, {document:{getElementById() {return null;}}});
console.log('EMAIL acknowledgement DOM behavior: PASS — modal activation, focus, Escape prevention and fallback.');
