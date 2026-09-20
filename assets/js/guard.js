(function () {
    const cfg = window.VoteGuard || {};
    if (!cfg.sessionUrl) {
        return;
    }

    const state = {
        token: null,
        pointer: 0,
        start: Date.now()
    };

    function post(url, data) {
        if (window.axios) {
            return window.axios.post(url, data).then(function (res) {
                return res.data;
            });
        }

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': cfg.csrf || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        }).then(function (res) {
            return res.json();
        });
    }

    function hookAxios() {
        if (!state.token || !window.axios || !window.axios.interceptors) {
            return;
        }

        window.axios.interceptors.request.use(function (config) {
            config.headers = config.headers || {};
            config.headers['X-VoteGuard-Token'] = state.token;

            if (config.data && typeof config.data === 'object' && !(config.data instanceof FormData)) {
                config.data.voteguard_token = state.token;
            }

            return config;
        });
    }

    post(cfg.sessionUrl, {
        webdriver: !!(navigator && navigator.webdriver)
    }).then(function (res) {
        if (!res || !res.token) {
            return;
        }

        state.token = res.token;
        hookAxios();
    }).catch(function () {
        //
    });

    document.addEventListener('pointermove', function () {
        state.pointer += 1;
    }, {passive: true});

    document.addEventListener('click', function (ev) {
        const link = ev.target.closest('[data-vote-url], [data-vote-id]');

        if (!link || !state.token) {
            return;
        }

        post(cfg.clickUrl, {
            token: state.token,
            site: link.getAttribute('data-vote-id'),
            pointer: state.pointer,
            page_ms: Date.now() - state.start,
            webdriver: !!(navigator && navigator.webdriver)
        }).catch(function () {
            //
        });
    }, true);
})();
