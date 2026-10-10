'use strict';

// Protocol tests for the reference widget client, with a fake fetch.
const test = require('node:test');
const assert = require('node:assert/strict');
const TicketingWidget = require('../../resources/js/ticketing-widget.js');

function memoryStorage() {
    const data = new Map();

    return {
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        removeItem: (key) => data.delete(key),
    };
}

function fakeFetch(routes) {
    const calls = [];

    const fetch = async (url, init) => {
        calls.push({ url, init });
        const route = routes.shift();
        const status = route.status || 200;

        return {
            ok: status >= 200 && status < 300,
            status,
            json: async () => route.body,
        };
    };

    return { fetch, calls };
}

test('starts a conversation, sends with the bearer token and stores the session', async () => {
    const storage = memoryStorage();
    const { fetch, calls } = fakeFetch([
        { status: 201, body: { data: { id: 'conv1', token: 'tok1', status: 'open', expires_at: null } } },
        { status: 201, body: { data: { id: 'conv1', status: 'open', messages: [{ role: 'visitor', body: 'Hi', at: 't1' }] } } },
    ]);

    const widget = new TicketingWidget({ endpoint: 'https://app.example/ticketing/widget/v1/', key: 'tk_pub_x', fetch, storage });
    await widget.start({ identity: { email: 'a@b.c', signature: 'sig' }, locale: 'it' });
    await widget.send('Hi');

    assert.equal(calls[0].url, 'https://app.example/ticketing/widget/v1/conversations');
    assert.equal(calls[0].init.headers['X-Ticketing-Key'], 'tk_pub_x');
    assert.equal(calls[0].init.credentials, 'omit');
    assert.deepEqual(JSON.parse(calls[0].init.body), { identity: { email: 'a@b.c', signature: 'sig' }, locale: 'it' });
    assert.equal(calls[1].url, 'https://app.example/ticketing/widget/v1/conversations/conv1/messages');
    assert.equal(calls[1].init.headers.Authorization, 'Bearer tok1');
    assert.deepEqual(JSON.parse(storage.getItem('ticketing:tk_pub_x')), { id: 'conv1', token: 'tok1', expiresAt: null });

    // A new client on the same storage resumes the conversation.
    const resumed = new TicketingWidget({ endpoint: 'https://app.example', key: 'tk_pub_x', fetch, storage });
    assert.equal((await resumed.start()).id, 'conv1');
});

test('raises typed errors and forgets a conversation the server no longer knows', async () => {
    const storage = memoryStorage();
    storage.setItem('ticketing:tk_pub_x', JSON.stringify({ id: 'gone', token: 't' }));
    const { fetch } = fakeFetch([
        { status: 422, body: { message: 'Invalid', errors: { email: ['Required'] } } },
        { status: 404, body: { message: 'Conversation not found.' } },
    ]);

    const widget = new TicketingWidget({ endpoint: 'https://app.example', key: 'tk_pub_x', fetch, storage });

    await assert.rejects(widget.escalate({}), (error) => error instanceof TicketingWidget.Error && error.status === 422 && error.errors.email[0] === 'Required');
    await assert.rejects(widget.messages(), (error) => error.status === 404);
    assert.equal(storage.getItem('ticketing:tk_pub_x'), null);
    assert.throws(() => widget.path('/messages'), /call start\(\) first/);
});

test('ignores an expired stored session and needs endpoint, key and fetch', () => {
    const storage = memoryStorage();
    storage.setItem('ticketing:k', JSON.stringify({ id: 'c', token: 't', expiresAt: '2000-01-01T00:00:00Z' }));

    const widget = new TicketingWidget({ endpoint: 'https://app.example', key: 'k', fetch: async () => ({}), storage });

    assert.equal(widget.session, null);
    assert.throws(() => new TicketingWidget({ key: 'k' }), TypeError);
});

test('de-duplicates polled messages by id and stops on demand', async () => {
    const message = { id: 'a1', role: 'assistant', body: 'Hello', at: 't1' };
    const { fetch } = fakeFetch([
        { body: { data: { status: 'open', messages: [message] } } },
        { body: { data: { status: 'open', messages: [message, { id: 'a2', role: 'assistant', body: 'Hello', at: 't1' }] } } },
    ]);

    const widget = new TicketingWidget({ endpoint: 'https://app.example', key: 'k', fetch, storage: null });
    widget.session = { id: 'c', token: 't' };

    const batches = [];
    const stop = widget.poll((fresh) => batches.push(fresh.map((m) => m.body)), { interval: 1000 });

    await new Promise((resolve) => setTimeout(resolve, 1100));
    stop();

    // Same role, body and second: still two messages, told apart by id.
    assert.deepEqual(batches, [['Hello'], ['Hello']]);
});

test('searches, reads and votes on articles with the conversation attached', async () => {
    const { fetch, calls } = fakeFetch([
        { body: { data: [{ slug: 'reset' }] } },
        { body: { data: { slug: 'reset' } } },
        { status: 201, body: { data: { recorded: true } } },
        { body: { data: { status: 'escalated' } } },
        { body: { data: { status: 'closed' } } },
    ]);

    const widget = new TicketingWidget({ endpoint: 'https://app.example', key: 'k', fetch, storage: null });
    widget.session = { id: 'c', token: 't' };

    await widget.searchArticles('reset pwd', { locale: 'it', limit: 3 });
    await widget.article('reset', { locale: 'it' });
    await widget.feedback('reset', true, { comment: 'Great' });

    // An escalated conversation keeps its session (agents may still reply)…
    await widget.close();
    assert.deepEqual(widget.session, { id: 'c', token: 't' });

    // …a closed one is forgotten.
    await widget.close();

    assert.equal(calls[0].url, 'https://app.example/kb/search?q=reset%20pwd&locale=it&limit=3');
    assert.equal(calls[0].init.headers['X-Ticketing-Conversation'], 'c');
    assert.equal(calls[1].url, 'https://app.example/kb/articles/reset?locale=it');
    assert.deepEqual(JSON.parse(calls[2].init.body), { helpful: true, comment: 'Great' });
    assert.equal(widget.session, null);
});

test('keeps working in memory when the browser denies storage', async () => {
    // A sandboxed iframe: even reading sessionStorage throws.
    Object.defineProperty(globalThis, 'sessionStorage', {
        configurable: true,
        get() {
            throw new Error('SecurityError: access denied');
        },
    });

    try {
        const { fetch } = fakeFetch([
            { status: 201, body: { data: { id: 'conv9', token: 'tok9', status: 'open', expires_at: null } } },
        ]);
        const widget = new TicketingWidget({ endpoint: 'https://app.test/w/v1', key: 'tk_pub_x', fetch });

        assert.equal(widget.storage, null);
        assert.equal((await widget.start()).id, 'conv9');
        assert.equal(widget.session.token, 'tok9');
        widget.reset();
        assert.equal(widget.session, null);
    } finally {
        delete globalThis.sessionStorage;
    }

    // A storage whose writes fail (quota) does not break a started conversation.
    const failing = { getItem: () => null, setItem: () => { throw new Error('QuotaExceededError'); }, removeItem: () => { throw new Error('denied'); } };
    const { fetch } = fakeFetch([
        { status: 201, body: { data: { id: 'conv10', token: 'tok10', status: 'open', expires_at: null } } },
    ]);
    const widget = new TicketingWidget({ endpoint: 'https://app.test/w/v1', key: 'tk_pub_x', fetch, storage: failing });

    assert.equal((await widget.start()).id, 'conv10');
    widget.reset();
});
