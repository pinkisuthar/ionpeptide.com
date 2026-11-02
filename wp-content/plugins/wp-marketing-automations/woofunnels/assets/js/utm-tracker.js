/*global wffnUtm */
var wffnUtm_terms = wffnUtm.cookieKeys, wffnCookieManage = {
    setCookie: function (e, o, t) {
        var r = new Date();
        r.setTime(r.getTime() + 24 * t * 60 * 60 * 1e3);
        var c = "expires=" + r.toUTCString();
        var basehost = (typeof wffnUtm !== 'undefined' && wffnUtm.cookie_domain) ? (';domain=' + wffnUtm.cookie_domain) : (';domain=.' + wffnGetHost(document.location.hostname));

         document.cookie = e + "=" + o + ";" + c + basehost + ";path=/";
    }, getCookie: function (e) {
        for (var o = e + "=", t = document.cookie.split(";"), r = 0; r < t.length; r++) {
            for (var c = t[r]; " " == c.charAt(0);) c = c.substring(1);
            if (0 == c.indexOf(o)) return c.substring(o.length, c.length);
        }
        return "";
    }, remove: function (e) {
        var o = new Date();
        o.setTime(o.getTime() - 864e5);
        var t = "expires=" + o.toUTCString();
        var basehost = (typeof wffnUtm !== 'undefined' && wffnUtm.cookie_domain) ? (';domain=' + wffnUtm.cookie_domain) : (';domain=.' + wffnGetHost(document.location.hostname));
        // Cookies are set with domain=.<host>; deletion must use the same domain to match,
        // otherwise the scoped cookie survives. Also clear any host-only legacy cookie.
        document.cookie = e + "=;" + t + basehost + ";path=/";
        document.cookie = e + "=;" + t + ";path=/";
    }, commons: {
        inArray: function (e, o) {
            return -1 === o.indexOf(e);
        }
    }
};


function wffnGetHost(url) {
    var o = {
            strictMode: false,
            key: ['source', 'protocol', 'authority', 'userInfo', 'user', 'password', 'host', 'port', 'relative', 'path', 'directory', 'file', 'query', 'anchor'],
            q: {
                name: 'queryKey',
                parser: /(?:^|&)([^&=]*)=?([^&]*)/g
            },
            parser: {
                strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
                loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
            }
        },
        m = o.parser[o.strictMode ? 'strict' : 'loose'].exec(url),
        uri = {},
        i = 14;

    while (i--) {
        uri[o.key[i]] = m[i] || '';
    }

    uri[o.q.name] = {};
    uri[o.key[12]].replace(o.q.parser, function ($0, $1, $2) {
        if ($1) {
            uri[o.q.name][$1] = $2;
        }
    });

    return uri.host.replace('www.', '');
}

function wffnGetQueryVars() {

    try {

        var result = {}, tmp = [];

        window.location.search
            .substr(1)
            .split("&")
            .forEach(function (item) {

                tmp = item.split('=');

                if (tmp.length > 1) {
                    result[tmp[0]] = tmp[1];
                }

            });

        return wffnDefaultEvent(result);

    } catch (e) {
        console.log(e);
    }

}

/** Add default parameter utm event **/
function wffnDefaultEvent(result) {
    if (typeof Intl === "object" && typeof Intl.DateTimeFormat() === "object") {
        let resolved = Intl.DateTimeFormat().resolvedOptions();
        if (resolved.hasOwnProperty('timeZone')) {
            result.timezone = resolved.timeZone;
        }
    }

    result.flt = wffnGetAdminTime();

    /**
     * save referrer when manually pass by url
     */
    if (result.hasOwnProperty('fkreferrer') && result.fkreferrer !== '') {
        result.referrer = result.fkreferrer;
        delete result.fkreferrer;
    } else {
        const WffnfkRef = document.referrer;
        const getDomain = url => new URL(url).hostname;

        result.referrer = (WffnfkRef && !wffnUtm.excludeDomain.some(domain => getDomain(WffnfkRef).endsWith(domain)) && !WffnfkRef.includes(window.location.hostname)) ? WffnfkRef : '';

    }

    result.fl_url = (typeof window.location.pathname !== "undefined") ? window.location.pathname : '/';

    let getDevice = wffnDetectDevice();
    if (typeof getDevice !== "undefined" && getDevice !== "") {
        if (typeof getDevice.browser.name !== "undefined") {
            result.browser = getDevice.browser.name;
        }
        if (typeof getDevice.is_mobile !== "undefined") {
            result.is_mobile = getDevice.is_mobile;
        }
    }
    return result;
}


/** get wp admin current time*/
function wffnGetAdminTime(getEpochTime = false, isObject = false) {
    var getTime = new Date();
    var getIsoString = getTime.toISOString();

    // Convert the ISO string to a Date object
    var dateFromIso = new Date(getIsoString);

    // Set Admin offset to get user time according admin
    dateFromIso.setMinutes(dateFromIso.getMinutes() + parseInt(wffnUtm.utc_offset));
    getIsoString = dateFromIso.toISOString();
    const [datePart, timePart] = getIsoString.split("T");

    // Extract year, month, day
    const [getYear, getMonth, getDay] = datePart.split("-").map(Number);
    // Extract hours, minutes, seconds
    const [getHours, getMinutes, secondsWithMillis] = timePart.split(":");
    const getSeconds = secondsWithMillis.split(".")[0];
    if (true === getEpochTime) {
        /** get time in seconds **/
        dateFromIso = new Date(getYear + '-' + (getMonth) + '-' + getDay + ' ' + getHours + ':' + getMinutes + ':' + getSeconds);
        return Math.round(dateFromIso.getTime() / 1000);
    }

    if (true === isObject) {
        return new Date(getYear + '-' + (getMonth) + '-' + getDay + ' ' + getHours + ':' + getMinutes + ':' + getSeconds);

    }

    return getYear + '-' + (getMonth) + '-' + getDay + ' ' + getHours + ':' + getMinutes + ':' + getSeconds;
}

function wffnGetTrafficSource() {
    try {

        var referrer = document.referrer.toString();

        var direct = referrer.length === 0;
        //noinspection JSUnresolvedVariable
        var internal = direct ? false : referrer.indexOf(wffnUtm.site_url) === 0;
        var external = !(direct || internal);
        var cookie = wffnCookieManage.getCookie('wffn_traffic_source') === '' ? false : wffnCookieManage.getCookie('wffn_traffic_source');

        if (external === false) {
            return cookie ? cookie : 'direct';
        } else {
            return cookie && cookie === referrer ? cookie : referrer;
        }

    } catch (e) {

        return '';

    }


}

/**
 * Capture the first-touch UTM/referrer into the *_last cookies the first time a value
 * is seen. Written once and never overwritten, so they preserve the original campaign
 * that acquired the visitor. Persisted server-side into the existing *_last columns.
 */
function wffnSetUTMLastForFirstTime() {
    try {
        const utmKeys = ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "referrer"];
        const lastKeys = ["utm_source_last", "utm_medium_last", "utm_campaign_last", "utm_term_last", "utm_content_last", "referrer_last"];

        for (let i = 0; i < utmKeys.length; i++) {
            const currentValue = wffnCookieManage.getCookie('wffn_' + utmKeys[i]);
            const lastValue = wffnCookieManage.getCookie('wffn_' + lastKeys[i]);

            if (currentValue && !lastValue) {
                wffnCookieManage.setCookie('wffn_' + lastKeys[i], currentValue, 2);
            }
        }
    } catch (e) {
        console.log(e, 'WFFN UTM First-Touch');
    }
}

function wffnManageCookies() {
    if (true === window.wffnUtmCookiesInitialized) {
        return;
    }

    try {
        var source = wffnGetTrafficSource();
        if (source !== 'direct') {
            wffnCookieManage.setCookie('wffn_traffic_source', source, 2);
        } else {
            wffnCookieManage.remove('wffn_traffic_source');
        }

        var queryVars = wffnGetQueryVars();


        for (var k in wffnUtm_terms) {
            if (Object.prototype.hasOwnProperty.call(queryVars, wffnUtm_terms[k])) {
                /**
                 * restricted override cookies for user journey
                 */
                if (['flt', 'fl_url', 'referrer'].indexOf(wffnUtm_terms[k]) !== -1) {
                    if ('undefined' !== typeof wffnCookieManage && '' === wffnCookieManage.getCookie('wffn_' + wffnUtm_terms[k])) {
                        wffnCookieManage.setCookie('wffn_' + wffnUtm_terms[k], queryVars[wffnUtm_terms[k]], 2);
                    }
                } else {
                    wffnCookieManage.setCookie('wffn_' + wffnUtm_terms[k], queryVars[wffnUtm_terms[k]], 2);
                }
            }
        }

        /**
         * Capture first-touch UTM/referrer into the *_last cookies once set.
         */
        wffnSetUTMLastForFirstTime();

        /**
         * Build the page-by-page customer journey. On the thank-you page we drop the
         * cookie (the server has already persisted the journey onto the order), so a
         * fresh visit starts a clean journey.
         */
        if ('1' == wffnUtm.is_thankyou_page) {
            wffnCookieManage.remove('wffn_journey');
        } else {
            wffnJourney();
        }

        window.wffnUtmCookiesInitialized = true;
    } catch (e) {
        console.log(e);
    }


}

/**
 * Normalize any decoded journey value into the v2 {j, s} shape. Legacy flat
 * journeys ({ts:{u,t,i}}) are wrapped as {j: <legacy>, s: {}} so their entries
 * still render (they simply lack an `s` field).
 */
function wffnJourneyNormalize(data) {
    if (data && typeof data === 'object' && data.j && typeof data.j === 'object') {
        if (typeof data.s !== 'object' || data.s === null) {
            data.s = {};
        }
        return data;
    }
    return { j: (data && typeof data === 'object') ? data : {}, s: {} };
}

/**
 * Return the index of `origin` in store.s, assigning the next integer (from 1)
 * if absent. Indexes are shared across sub-sites via the shared cookie.
 */
function wffnJourneySiteIndex(store, origin) {
    var keys = Object.keys(store.s);
    for (var i = 0; i < keys.length; i++) {
        if (store.s[keys[i]] === origin) {
            return parseInt(keys[i], 10);
        }
    }
    var next = 1;
    for (var k = 0; k < keys.length; k++) {
        var n = parseInt(keys[k], 10);
        if (n >= next) { next = n + 1; }
    }
    store.s[next] = origin;
    return next;
}

/**
 * Append the current page to the journey cookie.
 *
 * Each entry is keyed by epoch second and stores { u: url, t: title, i: page_id }.
 * Consecutive duplicate URLs are skipped and the cookie is capped to keep it under
 * the browser cookie size budget (oldest entries are dropped first).
 */
function wffnJourney() {
    if (wffnUtm.journeyControl === 'disable') {
        return;
    }

    let raw = wffnCookieManage.getCookie('wffn_journey');
    let parsed = {};
    if ('' !== raw) { try { parsed = JSON.parse(raw); } catch (e) { wffnCookieManage.remove('wffn_journey'); parsed = {}; } }
    let store = wffnJourneyNormalize(parsed);
    let basePath = (typeof window.location.pathname !== "undefined") ? window.location.pathname : '/';

    const queryVars = wffnGetQueryVars();
    const utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    let utmQueryString = '';
    let hasUtm = false;
    for (let i = 0; i < utmKeys.length; i++) {
        const key = utmKeys[i];
        if (Object.prototype.hasOwnProperty.call(queryVars, key)) {
            utmQueryString += (hasUtm ? '&' : '?') + key + '=' + queryVars[key];
            hasUtm = true;
        }
    }

    // Relative path; the host is recorded once in store.s via the site index.
    let fullPath = basePath + utmQueryString;
    let origin = (typeof window.location.origin !== "undefined") ? window.location.origin : '';
    let siteIdx = wffnJourneySiteIndex(store, origin);

    let pageData = {};
    pageData.u = encodeURIComponent(wffnAddSlashes(fullPath));
    pageData.t = encodeURIComponent(document.title);
    pageData.i = wffnUtm.page_id;
    pageData.s = siteIdx;

    // Dedup on the (path, site) pair so the same path on two sites is not collapsed.
    if (wffnGetLastEntry(store.j, 'u') === pageData.u && wffnGetLastEntry(store.j, 's') === pageData.s) {
        return;
    }

    let wffnTime = Math.round(Date.now() / 1000);
    store.j[wffnTime] = pageData;

    store = wffn_MaxCookieLength(store, 3872);
    wffnCookieManage.setCookie('wffn_journey', JSON.stringify(store), 2);
}

/**
 * Add slashes to the string.
 *
 * @param str
 * @returns {string}
 */
function wffnAddSlashes(str) {
    return (str + '').replace(/[\\"']/g, '\\$&');
}

/**
 * Value of `field` on the most recently inserted journey entry (for dedup).
 */
function wffnGetLastEntry(jmap, field) {
    var keys = Object.keys(jmap);
    var lastKey = keys[keys.length - 1];
    if (typeof jmap[lastKey] !== "undefined" && typeof jmap[lastKey][field] !== "undefined") {
        return jmap[lastKey][field];
    }
    return '';
}

/**
 * Trim oldest journey entries when the cookie would exceed maxSize bytes.
 * Operates on store.j (the entry map); store.s is pruned of orphans.
 */
/**
 * Trim oldest journey entries when the cookie would exceed maxSize bytes.
 * Operates on store.j (v2 entry map); tolerates a flat legacy store (no .j)
 * by trimming its own keys directly.
 */
function wffn_MaxCookieLength(store, maxSize) {
    var totalCookieSize = document.cookie.length;
    if (totalCookieSize + JSON.stringify(store).length > maxSize) {
        while (JSON.stringify(store).length > maxSize / 2 && Object.keys(store.j ? store.j : store).length > 0) {
            store = wffnDeleteFirstEl(store);
        }
    }
    return store;
}

/**
 * Delete the earliest journey entry. For a v2 store also prune any site
 * index no longer used; for a flat legacy store delete its first key.
 */
function wffnDeleteFirstEl(store) {
    var map = store.j ? store.j : store;
    var keys = Object.keys(map);
    if (keys.length === 0) {
        return store;
    }
    delete map[keys[0]];

    if (store.j && store.s) {
        var used = {};
        Object.keys(store.j).forEach(function (k) {
            if (typeof store.j[k].s !== "undefined") { used[store.j[k].s] = true; }
        });
        Object.keys(store.s).forEach(function (sIdx) {
            if (!used[sIdx]) { delete store.s[sIdx]; }
        });
    }
    return store;
}

/**
 * Return UTM terms from request query variables or from cookies.
 */
function wffnGetUTMs() {
    try {
        var terms = {};
        var queryVars = wffnGetQueryVars();
        /** exclude parameter for utm event **/
        var excludeArray = ["flt", "timezone", "is_mobile", "browser", "fbclid", "gclid", "referrer", "fl_url"];
        for (var k in wffnUtm_terms) {

            if (wffnCookieManage.getCookie('wffn_' + wffnUtm_terms[k]) === '' && Object.prototype.hasOwnProperty.call(queryVars, wffnUtm_terms[k])) {
                terms[wffnUtm_terms[k]] = wffnCookieManage.getCookie('wffn_' + wffnUtm_terms[k]);

            }
        }
        return terms;

    } catch (e) {
        return {};
    }

}

/* eslint-disable no-unused-vars */
function wffnAddTrafficParamsToEvent(params) {

    try {
        var get_generic_params = wffnUtm.genericParamEvents;
        var json_get_generic_params = JSON.parse(get_generic_params);

        for (var k in json_get_generic_params) {
            params[k] = json_get_generic_params[k];
        }


        /**
         * getting current day and time to send with this event
         */
        var e = wffnGetAdminTime(false, true);
        var a = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"][e.getDay()],
            b = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"][e.getMonth()],
            c = ["00-01", "01-02", "02-03", "03-04", "04-05", "05-06", "06-07", "07-08", "08-09", "09-10", "10-11", "11-12", "12-13", "13-14", "14-15", "15-16", "16-17", "17-18", "18-19", "19-20", "20-21", "21-22", "22-23", "23-24"][e.getHours()];

        params.event_month = b;
        params.event_day = a;
        params.event_hour = c;

        params.traffic_source = wffnGetTrafficSource();

        var getUTMs = wffnGetUTMs();

        for (var ki in getUTMs) {

            params[ki] = getUTMs[ki];

        }
        return params;

    } catch (eeX) {

        return params;

    }
}

/** return device and browser info **/
function wffnDetectDevice() {
    let header = [navigator.userAgent, navigator.vendor, window.opera];
    let is_mobile = false;
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        is_mobile = true;
    }

    /** check device for facebook application **/
    if (false === is_mobile) {
        let fbAgent = navigator.userAgent || navigator.vendor || window.opera;
        if ((fbAgent.indexOf("FBAN") > -1) || (fbAgent.indexOf("FBAV") > -1)) {
            is_mobile = true;
        }
    }

    let databrowser = [
        {name: 'Chrome', value: 'Chrome', version: 'Chrome'},
        {name: 'Firefox', value: 'Firefox', version: 'Firefox'},
        {name: 'Safari', value: 'Safari', version: 'Version'},
        {name: 'Internet Explorer', value: 'MSIE', version: 'MSIE'},
        {name: 'Opera', value: 'Opera', version: 'Opera'},
        {name: 'BlackBerry', value: 'CLDC', version: 'CLDC'},
        {name: 'Mozilla', value: 'Mozilla', version: 'Mozilla'}
    ];
    var agent = header.join(' '),
        browser = wffnDetectBrowser(agent, databrowser);
    return {is_mobile: is_mobile, browser: browser};
}

function wffnDetectBrowser(string, data) {
    var i = 0,
        j = 0,
        regex,
        regexv,
        match,
        matches,
        version;

    for (i = 0; i < data.length; i += 1) {
        regex = new RegExp(data[i].value, 'i');
        match = regex.test(string);
        if (match) {
            regexv = new RegExp(data[i].version + '[- /:;]([\\d._]+)', 'i');
            matches = string.match(regexv);
            version = '';
            if (matches) {
                if (matches[1]) {
                    matches = matches[1];
                }
            }
            if (matches) {
                matches = matches.split(/[._]+/);
                for (j = 0; j < matches.length; j += 1) {
                    if (j === 0) {
                        version += matches[j] + '.';
                    } else {
                        version += matches[j];
                    }
                }
            } else {
                version = '0';
            }
            return {
                name: data[i].name,
                version: parseFloat(version)
            };
        }
    }
    return {name: 'unknown', version: 0};
}

/**
 * Complianz: `cmplz_known_script_tags` (PHP) blocks this script until marketing consent.
 * When the script runs, either Complianz is inactive or consent allows it — run once here.
 * `cmplz_enable_category` is a jQuery event (not a window function); do not gate on it.
 */
wffnManageCookies();

try {
    if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.on) {
        jQuery(document).on("cmplz_enable_category", function(event) {
            try {
                if (!event || !event.originalEvent || !event.originalEvent.detail) {
                    return;
                }

                var category = event.originalEvent.detail.category;
                if (!category) {
                    return;
                }

                if (category === 'marketing') {
                    window.wffnUtmCookiesInitialized = false;
                    wffnManageCookies();
                }
            } catch (error) {
                console.log(error, 'WFFN UTM Consent Handler');
            }
        });
    }
} catch (error) {
    console.log(error, 'WFFN UTM Consent Setup');
}
