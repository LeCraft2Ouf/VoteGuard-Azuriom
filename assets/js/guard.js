(function () {
    const cfg = window.VoteGuard || {};

    function showBlockedBanner() {
        var msg = cfg.blockedMessage || 'Tes votes sont bloqués.';
        if (document.getElementById('voteguard-blocked')) {
            return;
        }

        var bar = document.createElement('div');
        bar.id = 'voteguard-blocked';
        bar.setAttribute('role', 'alert');
        bar.style.cssText = 'position:relative;z-index:50;margin:0 0 1rem;padding:.9rem 1.1rem;border-radius:.5rem;background:#7f1d1d;color:#fff;font-weight:600;text-align:center';
        bar.textContent = msg;

        var host = document.getElementById('vote-card')
            || document.getElementById('status-message')
            || document.querySelector('[data-vote-step]')
            || document.querySelector('main')
            || document.body;
        host.insertBefore(bar, host.firstChild);

        var status = document.getElementById('status-message');
        if (status) {
            status.replaceChildren();
            var alert = document.createElement('div');
            alert.className = 'alert alert-danger';
            alert.setAttribute('role', 'alert');
            alert.textContent = msg;
            status.appendChild(alert);
        }

        document.querySelectorAll('[data-vote-url], [data-vote-id]').forEach(function (el) {
            el.classList.add('disabled');
            el.style.pointerEvents = 'none';
            el.setAttribute('aria-disabled', 'true');
        });
    }

    function rejectBlockedVote(url) {
        var href = String(url || '');
        if (href.indexOf('/vote/site/') === -1 || href.indexOf('/done') === -1) {
            return null;
        }

        return {
            response: {
                status: 403,
                data: { message: cfg.blockedMessage || 'Tes votes sont bloqués.' }
            }
        };
    }

    if (cfg.blocked) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showBlockedBanner);
        } else {
            showBlockedBanner();
        }

        if (window.axios && window.axios.interceptors) {
            window.axios.interceptors.request.use(function (config) {
                var blocked = rejectBlockedVote(config && config.url);
                if (blocked) {
                    return Promise.reject(blocked);
                }
                return config;
            });
        }
    }

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
            var url = String(config.url || '');

            if (url.indexOf('/vote/site/') === -1 || url.indexOf('/done') === -1) {
                return config;
            }

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

        if (cfg.blocked) {
            ev.preventDefault();
            ev.stopPropagation();
            showBlockedBanner();
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
