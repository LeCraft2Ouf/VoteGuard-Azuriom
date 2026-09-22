(function () {
    var cfg = window.__px || {};
    if (!cfg.o || !cfg.e) {
        return;
    }

    var state = {
        token: null,
        openedAt: 0,
        pointer: 0,
        start: Date.now()
    };

    function form(data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (key) {
            var value = data[key];
            body.append(key, value === true ? '1' : (value === false ? '0' : String(value)));
        });
        body.append('_token', cfg.c || '');
        return body;
    }

    function post(url, data, beacon) {
        if (beacon && navigator.sendBeacon) {
            try {
                if (navigator.sendBeacon(url, form(data))) {
                    return Promise.resolve(null);
                }
            } catch (e) {
                //
            }
        }

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: !!beacon,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': cfg.c || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: form(data)
        }).then(function (res) {
            return res.ok ? res.json() : null;
        });
    }

    function open() {
        return post(cfg.o, {webdriver: !!navigator.webdriver}, false).then(function (res) {
            if (res && res.token) {
                state.token = res.token;
                state.openedAt = Date.now();
            }
        }).catch(function () {
            //
        });
    }

    function hookAxios() {
        if (!window.axios || !window.axios.interceptors || hookAxios.done) {
            return;
        }

        hookAxios.done = true;
        window.axios.interceptors.request.use(function (config) {
            var url = String(config.url || '');

            if (!state.token || url.indexOf('/vote/site/') === -1 || url.indexOf('/done') === -1) {
                return config;
            }

            config.headers = config.headers || {};
            config.headers['X-Request-Ref'] = state.token;

            if (config.data && typeof config.data === 'object' && !(config.data instanceof FormData)) {
                config.data._ref = state.token;
            }

            return config;
        });
    }

    function siteOf(link) {
        var id = link.getAttribute('data-vote-id');
        if (id) {
            return id;
        }

        var match = String(link.getAttribute('data-vote-url') || link.getAttribute('href') || '').match(/\/vote\/site\/(\d+)/);
        return match ? match[1] : '';
    }

    function onClick(ev) {
        if (ev.type === 'auxclick' && ev.button !== 1) {
            return;
        }

        var link = ev.target && ev.target.closest ? ev.target.closest('[data-vote-url], [data-vote-id]') : null;
        if (!link || !state.token) {
            return;
        }

        post(cfg.e, {
            token: state.token,
            site: siteOf(link),
            pointer: state.pointer,
            page_ms: Date.now() - state.start,
            webdriver: !!navigator.webdriver
        }, true).catch(function () {
            //
        });
    }

    function interact() {
        state.pointer += 1;
    }

    ['pointermove', 'pointerdown', 'touchstart', 'keydown'].forEach(function (type) {
        document.addEventListener(type, interact, {passive: true});
    });

    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', onClick, true);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && state.openedAt && Date.now() - state.openedAt > 3 * 3600 * 1000) {
            open();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hookAxios);
    } else {
        hookAxios();
    }
    window.addEventListener('load', hookAxios);

    open();
})();
