const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Exercise the actual browser transport without uploading client documents to Gemini.
const source = fs.readFileSync('public/js/payment-slip-editor.js', 'utf8');
const transport = source.slice(source.indexOf('    function uploadForExtraction('), source.indexOf('    function setField('));
function setup() {
    let request;
    class Request {
        constructor() { request = this; this.upload = {}; this.responseURL = '/extract/export'; }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader() {}
        send(body) { this.body = body; }
    }
    const upload = vm.runInNewContext(`${transport}; uploadForExtraction`, { XMLHttpRequest: Request, root: { dataset: { csrf: 'test' } } });
    const progress = { value: 0, removeAttribute() { delete this.value; } };
    const messages = [];
    const pending = upload('/extract/export', 'body', progress, (...message) => messages.push(message));
    return { request, progress, messages, pending };
}

test('upload percentage switches to indeterminate while Gemini processes', async () => {
    const state = setup();
    state.request.upload.onprogress({ lengthComputable: true, loaded: 25, total: 100 });
    assert.equal(state.progress.value, 25);
    state.request.upload.onload();
    assert.equal(state.progress.value, undefined);
    assert.match(state.messages.at(-1)[0], /Gemini/);
    state.request.status = 200;
    state.request.responseText = '{"invoices":[{"invoice_number":"TEST"}]}';
    state.request.onload();
    assert.equal((await state.pending).invoices[0].invoice_number, 'TEST');
});

test('failed validation, expired session, invalid response and network error reject clearly', async () => {
    for (const scenario of ['validation', 'session', 'invalid', 'network']) {
        const state = setup();
        const assertion = assert.rejects(state.pending);
        state.request.status = scenario === 'session' ? 419 : scenario === 'validation' ? 422 : 200;
        state.request.responseText = scenario === 'invalid' ? '<html>' : '{"message":"PDF tidak valid"}';
        if (scenario === 'network') state.request.onerror();
        else state.request.onload();
        await assertion;
    }
});
