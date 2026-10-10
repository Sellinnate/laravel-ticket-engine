/*!
 * selli/ticketing — reference widget client (protocol only, no UI). MIT.
 *
 * Talks to the package's public widget API: start a conversation, send and
 * poll messages, ask for a human, close, search the help center and vote.
 * It renders nothing: build your own UI (or none) on top of it.
 *
 *   const chat = new TicketingWidget({ endpoint: 'https://app.example/ticketing/widget/v1', key: 'tk_pub_…' });
 *   await chat.start({ identity: { email, name, signature } }); // signature: HMAC computed by YOUR server
 *   await chat.send('How do I reset my password?');
 *   const stop = chat.poll(messages => render(messages));
 *
 * The conversation id and bearer token live in sessionStorage by default
 * (per tab, gone when the tab closes); pass `storage: null` to keep them in
 * memory only. Internal ids never reach the browser.
 */
(function (global) {
    'use strict';

    class TicketingWidgetError extends Error {
        constructor(message, status, errors) {
            super(message);
            this.name = 'TicketingWidgetError';
            this.status = status;
            this.errors = errors || {};
        }
    }

    class TicketingWidget {
        constructor(options) {
            if (!options || !options.endpoint || !options.key) {
                throw new TypeError('TicketingWidget needs an endpoint and a public key.');
            }

            this.endpoint = String(options.endpoint).replace(/\/+$/, '');
            this.key = String(options.key);
            this.fetch = options.fetch || (typeof fetch === 'function' ? fetch.bind(global) : null);
            this.storage = options.storage === undefined
                ? (typeof sessionStorage !== 'undefined' ? sessionStorage : null)
                : options.storage;
            this.storageKey = 'ticketing:' + this.key;
            this.session = this.restore();
            this.seen = new Set();

            if (!this.fetch) {
                throw new TypeError('TicketingWidget needs a fetch implementation.');
            }
        }

        /** Widget settings: name, greeting, locale, whether an assistant answers. */
        async config() {
            return (await this.request('GET', '/config')).data;
        }

        /** Start (or resume) a conversation. Returns { id, status, … }. */
        async start(options) {
            options = options || {};

            if (this.session && !options.force) {
                return this.session;
            }

            const body = {};
            if (options.identity) body.identity = options.identity;
            if (options.locale) body.locale = options.locale;
            if (options.captchaToken) body.captcha_token = options.captchaToken;
            if (options.honeypot) body.website = options.honeypot;

            const data = (await this.request('POST', '/conversations', body)).data;
            this.session = { id: data.id, token: data.token, expiresAt: data.expires_at || null };
            this.persist();
            this.seen.clear();

            return data;
        }

        /** Post a visitor message; resolves with the conversation and its messages. */
        async send(text, options) {
            options = options || {};
            const body = { body: String(text) };
            if (options.honeypot) body.website = options.honeypot;

            return (await this.request('POST', this.path('/messages'), body, true)).data;
        }

        /** Messages (optionally only those at or after an ISO timestamp). */
        async messages(options) {
            options = options || {};
            const query = options.since ? '?since=' + encodeURIComponent(options.since) : '';

            return (await this.request('GET', this.path('/messages') + query, undefined, true)).data;
        }

        /** Hand the conversation to a human; anonymous visitors pass name and email. */
        async escalate(contact) {
            contact = contact || {};
            const body = {};
            if (contact.name) body.name = contact.name;
            if (contact.email) body.email = contact.email;
            if (contact.captchaToken) body.captcha_token = contact.captchaToken;

            return (await this.request('POST', this.path('/escalate'), body, true)).data;
        }

        /** End the conversation and forget it. */
        async close() {
            const data = (await this.request('POST', this.path('/close'), {}, true)).data;
            this.reset();

            return data;
        }

        /** Help-center search for deflection (public articles of this widget). */
        async searchArticles(query, options) {
            options = options || {};
            let url = '/kb/search?q=' + encodeURIComponent(query);
            if (options.locale) url += '&locale=' + encodeURIComponent(options.locale);
            if (options.limit) url += '&limit=' + encodeURIComponent(options.limit);

            return (await this.request('GET', url, undefined, false, true)).data;
        }

        /** One article by slug. */
        async article(slug, options) {
            options = options || {};
            const query = options.locale ? '?locale=' + encodeURIComponent(options.locale) : '';

            return (await this.request('GET', '/kb/articles/' + encodeURIComponent(slug) + query, undefined, false, true)).data;
        }

        /** "Was this helpful?" — one vote per conversation (or per visitor). */
        async feedback(slug, helpful, options) {
            options = options || {};
            const body = { helpful: Boolean(helpful) };
            if (options.comment) body.comment = options.comment;
            if (options.locale) body.locale = options.locale;

            return (await this.request('POST', '/kb/articles/' + encodeURIComponent(slug) + '/feedback', body, false, true)).data;
        }

        /**
         * Poll for messages every `interval` ms and call back with the ones not
         * seen yet. Returns a function that stops polling.
         */
        poll(callback, options) {
            options = options || {};
            const interval = Math.max(1000, options.interval || 4000);
            let stopped = false;
            let timer = null;

            const tick = async () => {
                if (stopped) return;

                try {
                    const data = await this.messages();
                    const fresh = this.unseen(data.messages || []);
                    if (fresh.length) callback(fresh, data);
                } catch (error) {
                    if (options.onError) options.onError(error);
                }

                if (!stopped) timer = setTimeout(tick, interval);
            };

            tick();

            return () => {
                stopped = true;
                if (timer) clearTimeout(timer);
            };
        }

        /** Forget the stored conversation (a new start() opens a new one). */
        reset() {
            this.session = null;
            this.seen.clear();
            if (this.storage) this.storage.removeItem(this.storageKey);
        }

        unseen(messages) {
            const fresh = [];

            for (const message of messages) {
                const id = message.id || (message.at + '|' + message.role + '|' + message.body);
                if (!this.seen.has(id)) {
                    this.seen.add(id);
                    fresh.push(message);
                }
            }

            return fresh;
        }

        path(suffix) {
            if (!this.session) {
                throw new TicketingWidgetError('No conversation: call start() first.', 0);
            }

            return '/conversations/' + encodeURIComponent(this.session.id) + suffix;
        }

        async request(method, url, body, authenticated, withConversation) {
            const headers = { Accept: 'application/json', 'X-Ticketing-Key': this.key };

            if (body !== undefined) headers['Content-Type'] = 'application/json';

            if ((authenticated || withConversation) && this.session) {
                headers.Authorization = 'Bearer ' + this.session.token;
                if (withConversation) headers['X-Ticketing-Conversation'] = this.session.id;
            }

            const response = await this.fetch(this.endpoint + url, {
                method,
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
                credentials: 'omit',
                mode: 'cors',
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (response.status === 404 && authenticated) this.reset();
                throw new TicketingWidgetError(payload.message || 'Request failed', response.status, payload.errors);
            }

            return payload;
        }

        restore() {
            if (!this.storage) return null;

            try {
                const stored = JSON.parse(this.storage.getItem(this.storageKey) || 'null');
                if (stored && stored.expiresAt && Date.parse(stored.expiresAt) < Date.now()) return null;

                return stored && stored.id && stored.token ? stored : null;
            } catch (error) {
                return null;
            }
        }

        persist() {
            if (this.storage && this.session) {
                this.storage.setItem(this.storageKey, JSON.stringify(this.session));
            }
        }
    }

    TicketingWidget.Error = TicketingWidgetError;
    global.TicketingWidget = TicketingWidget;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = TicketingWidget;
    }
})(typeof window !== 'undefined' ? window : globalThis);
