<script>
    var SESSION_TOKEN_REFRESH_INTERVAL = {{ config('shopify-app.session_token_refresh_interval') }};
    var LOAD_EVENT = 'DOMContentLoaded'

    document.addEventListener(LOAD_EVENT, () => {
        retrieveToken();
        keepRetrievingToken();
    });

    async function retrieveToken() {
        window.sessionToken = await shopify.idToken();

        Array.from(document.getElementsByClassName('session-token')).forEach((el) => {
            if (el.hasAttribute('value')) {
                el.value = window.sessionToken;
                el.setAttribute('value', el.value);
            } else {
                el.dataset.value = window.sessionToken;
            }
        });

        const bearer = `Bearer ${window.sessionToken}`;

        if (window.jQuery) {
            if (window.jQuery.ajaxSettings.headers) {
                window.jQuery.ajaxSettings.headers['Authorization'] = bearer;
            } else {
                window.jQuery.ajaxSettings.headers = { 'Authorization': bearer };
            }
        }

        if (window.Livewire) {
            window.Livewire.hook('request', ({options}) => {
                options.headers['Authorization'] = `Bearer ${window.sessionToken}`;
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-Requested-With'] = 'XMLHttpRequest';
            });
        }

        if (window.axios) {
            window.axios.defaults.headers.common['Authorization'] = bearer;
        }
    }

    function keepRetrievingToken() {
        setInterval(() => {
            retrieveToken();
        }, SESSION_TOKEN_REFRESH_INTERVAL);
    }
</script>
