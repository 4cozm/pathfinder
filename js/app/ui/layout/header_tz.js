/**
 * TZ activity strip (map header)
 * -> shows which EVE timezone (region) is most active right now
 *    (global, based on current UTC time)
 *
 * two independent axes:
 *  - region split (segment width / ranking): fixed prime-time curves
 *  - overall intensity (brightness + absolute counts): live ESI server players
 */

define([
    'jquery',
    'app/util'
], ($, Util) => {
    'use strict';

    let config = {
        toggleClass: 'pf-head-tz-toggle',                                       // class for "population weight" toggle button
        stripClass: 'pf-head-tz-strip',                                         // class for strip wrapper
        segClass: 'pf-head-tz-seg',                                             // class for a single TZ segment
        tipClass: 'pf-head-tz-tip',                                             // class for custom hover tooltip
        storeKey: 'tzStrip.usePop',                                             // LocalStore key for "population weight" setting

        // ESI server status (public, no auth) -> live total players online
        esiStatusUrl: 'https://esi.evetech.net/latest/status/?datasource=tranquility',
        esiPollMs: 120 * 1000,                                                  // ESI poll interval (status is cached ~30s server side)
        renderMs: 60 * 1000,                                                    // strip re-render interval (UTC minute advances)

        // rough Tranquility PCU range for intensity mapping (tunable, not exact)
        pcuLow: 12000,                                                          // ~ quiet hours
        pcuHigh: 32000                                                          // ~ peak / weekend
    };

    // timezone (region) definitions
    //  peak    : UTC hour of prime time, spread: curve width (hours)
    //  pop     : relative player base (EU = 1.0)
    //  NOTE: "pop" values are placeholder estimates -> region SPLIT stays an assumption.
    //        CCP does not publish per-country player counts; ESI only gives a global total.
    let TZ = [
        {key: 'AU',   name: 'AUTZ',  ko: '호주',     color: '#2dd4bf', peak: 10, spread: 3.0, pop: 0.15},
        {key: 'ASIA', name: 'CN/KR', ko: '동아시아', color: '#a78bfa', peak: 13, spread: 2.6, pop: 0.40},
        {key: 'RU',   name: 'RUTZ',  ko: '러시아',   color: '#f472b6', peak: 17, spread: 3.0, pop: 0.45},
        {key: 'EU',   name: 'EUTZ',  ko: '유럽',     color: '#60a5fa', peak: 19, spread: 3.2, pop: 1.00},
        {key: 'US',   name: 'USTZ',  ko: '미국',     color: '#fb923c', peak: 2,  spread: 3.4, pop: 0.70}
    ];

    let usePop = true;                                                          // population weight on/off (persisted)
    let serverPlayers = null;                                                   // live ESI total players online (null = unknown)
    let stripElRef = null;                                                      // strip wrapper DOM ref (for timer/fetch callbacks)
    let toggleElRef = null;                                                     // toggle button DOM ref
    let tipEl = null;                                                           // custom tooltip DOM ref (appended to body)
    let renderInterval = null;                                                  // strip re-render timer
    let esiInterval = null;                                                     // ESI poll timer

    /**
     * gaussian (24h wrap-around) -> fraction online at given hour [0..1]
     * @param tz
     * @param hour
     * @returns {number}
     */
    let activityAt = (tz, hour) => {
        let d = Math.abs(hour - tz.peak);
        d = Math.min(d, 24 - d);                                                // wrap-around (e.g. 23h <-> 01h)
        return Math.exp(-(d * d) / (2 * tz.spread * tz.spread));
    };

    /**
     * weighted activity = online fraction * player base (~ relative share)
     * @param tz
     * @param hour
     * @returns {number}
     */
    let weightedAt = (tz, hour) => activityAt(tz, hour) * (usePop ? tz.pop : 1);

    /**
     * snapshot for current UTC time -> each TZ with normalized share
     * @returns {Array}
     */
    let snapshot = () => {
        let now = new Date();
        let h = now.getUTCHours() + now.getUTCMinutes() / 60;
        let raw = TZ.map(tz => Object.assign({}, tz, {v: weightedAt(tz, h)}));
        let sum = raw.reduce((acc, r) => acc + r.v, 0) || 1;
        raw.forEach(r => r.pct = r.v / sum);
        return raw;
    };

    /**
     * overall intensity [0..1] from live server players (1 = unknown/full)
     * @returns {number}
     */
    let intensity = () => {
        if(serverPlayers === null){
            return 1;
        }
        let t = (serverPlayers - config.pcuLow) / (config.pcuHigh - config.pcuLow);
        return Math.max(0, Math.min(1, t));
    };

    /**
     * format big numbers -> "12.6k"
     * @param n
     * @returns {string}
     */
    let formatK = n => n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : String(n);

    /**
     * render strip segments into wrapper element
     */
    let render = () => {
        let stripEl = stripElRef;
        if(!stripEl){
            return;
        }

        let snap = snapshot();
        let topKey = snap.reduce((top, r) => r.v > top.v ? r : top, snap[0]).key;

        // keep a fixed region order for a stable band -> width = activity, top = highlighted
        let html = TZ.map(tz => {
            let r = snap.find(s => s.key === tz.key);
            let isTop = r.key === topKey;
            let flex = Math.max(r.pct, 0.03);                                   // keep tiny segments visible
            let opacity = isTop ? 1 : (0.55 + r.pct * 0.45);

            // absolute count (estimate) only when live total is known -> read by custom tooltip
            let cnt = (serverPlayers !== null) ? formatK(Math.round(serverPlayers * r.pct)) : '';

            return `<div class="${config.segClass}${isTop ? ' top' : ''}" ` +
                `data-ko="${r.ko}" data-name="${r.name}" data-pct="${Math.round(r.pct * 100)}" data-cnt="${cnt}" ` +
                `style="flex:${flex};background:${tz.color};opacity:${opacity.toFixed(2)};">${r.ko}</div>`;
        }).join('');
        stripEl.innerHTML = html;

        // overall intensity -> brightness/saturation of whole strip (quiet server = muted)
        let i = intensity();
        stripEl.style.filter = `saturate(${(0.5 + 0.5 * i).toFixed(2)}) brightness(${(0.75 + 0.25 * i).toFixed(2)})`;

        // hide labels that don't fit the (narrow) segment -> color + tooltip remain
        requestAnimationFrame(() => {
            stripEl.querySelectorAll('.' + config.segClass).forEach(seg => {
                if(seg.scrollWidth > seg.clientWidth + 1){
                    seg.textContent = '';
                }
            });
        });
    };

    /**
     * fetch live server status from ESI (public endpoint, client side)
     * -> updates "serverPlayers", degrades gracefully on error/CORS/downtime
     * @returns {Promise}
     */
    let fetchStatus = () => {
        return fetch(config.esiStatusUrl, {headers: {Accept: 'application/json'}})
            .then(resp => resp.ok ? resp.json() : Promise.reject(new Error('status ' + resp.status)))
            .then(data => {
                serverPlayers = Number.isFinite(data.players) ? data.players : null;
            })
            .catch(() => {
                // network / CORS / server offline -> unknown (neutral intensity, no counts)
                serverPlayers = null;
            });
    };

    /**
     * sync toggle button visual state with current "usePop"
     */
    let updateToggle = () => {
        if(!toggleElRef){
            return;
        }
        toggleElRef.classList.toggle('active', usePop);
        let icon = toggleElRef.querySelector('i');
        if(icon){
            icon.className = 'fas fa-fw ' + (usePop ? 'fa-users' : 'fa-user-slash');
        }
    };

    /**
     * tooltip content for a hovered strip segment
     * @param seg  segment DOM element
     * @returns {string}
     */
    let segTipHtml = seg => {
        let cnt = seg.dataset.cnt;
        return `<b>${seg.dataset.ko}</b> <span class="pf-tip-code">(${seg.dataset.name})</span>` +
            `<span class="pf-tip-pct">${seg.dataset.pct}%</span>` +
            (cnt ? `<span class="pf-tip-cnt">~${cnt}</span>` : '');
    };

    /**
     * tooltip content for the "population weight" toggle (explains behaviour + state)
     * @returns {string}
     */
    let toggleTipHtml = () => usePop ?
        `<b class="pf-tip-on">인구 가중 ON</b><br>유저 규모 × 접속비율 = <b>예상 동접</b> 기준<br>` +
            `<span class="pf-tip-code">클릭 → OFF (순수 프라임타임)</span>` :
        `<b class="pf-tip-off">인구 가중 OFF</b><br>규모 무시, <b>순수 프라임타임</b>만 반영<br>` +
            `<span class="pf-tip-code">클릭 → ON (예상 동접 기준)</span>`;

    /**
     * show custom tooltip at cursor (flips near right edge)
     * @param html
     * @param x
     * @param y
     */
    let showTip = (html, x, y) => {
        if(!tipEl){
            return;
        }
        tipEl.innerHTML = html;
        tipEl.style.opacity = '1';
        let pad = 12;
        let left = x + pad;
        if(left + tipEl.offsetWidth > window.innerWidth - 8){
            left = x - tipEl.offsetWidth - pad;                                 // flip to left of cursor
        }
        tipEl.style.left = left + 'px';
        tipEl.style.top = (y + pad) + 'px';
    };

    let hideTip = () => {
        if(tipEl){
            tipEl.style.opacity = '0';
        }
    };

    /**
     * init TZ strip inside header container
     * @param containerEl  DOM element (#pf-head-tz)
     */
    let initTzStrip = containerEl => {
        if(!containerEl){
            return;
        }

        // build inner structure (toggle button + strip)
        containerEl.innerHTML =
            `<a class="${config.toggleClass}" href="javascript:void(0)" aria-label="인구 가중">` +
                `<i class="fas fa-fw fa-users"></i>` +
            `</a>` +
            `<div class="${config.stripClass}"></div>`;

        toggleElRef = containerEl.querySelector('.' + config.toggleClass);
        stripElRef = containerEl.querySelector('.' + config.stripClass);

        // custom tooltip element (once, appended to body so it can overflow the header)
        if(!tipEl){
            tipEl = document.createElement('div');
            tipEl.className = config.tipClass;
            document.body.appendChild(tipEl);
        }

        // instant hover tooltip: segment details / toggle explanation
        $(containerEl).on('mousemove', e => {
            let seg = e.target.closest('.' + config.segClass);
            let tog = e.target.closest('.' + config.toggleClass);
            if(seg){
                showTip(segTipHtml(seg), e.clientX, e.clientY);
            }else if(tog){
                showTip(toggleTipHtml(), e.clientX, e.clientY);
            }else{
                hideTip();
            }
        }).on('mouseleave', hideTip);

        // load persisted "population weight" setting (default: on) -> then first render
        Util.getLocalStore('default').getItem(config.storeKey).then(val => {
            usePop = (val === null || val === undefined) ? true : !!val;
            updateToggle();
            render();
        });

        // toggle -> flip + persist + re-render (like other header feature buttons)
        $(toggleElRef).on('click', e => {
            e.preventDefault();
            usePop = !usePop;
            updateToggle();
            render();
            Util.getLocalStore('default').setItem(config.storeKey, usePop);
        });

        // live server status: fetch now + poll
        fetchStatus().then(render);
        if(esiInterval){
            clearInterval(esiInterval);
        }
        esiInterval = setInterval(() => fetchStatus().then(render), config.esiPollMs);

        // re-render once per minute (UTC time advances -> active TZ shifts)
        if(renderInterval){
            clearInterval(renderInterval);
        }
        renderInterval = setInterval(render, config.renderMs);
    };

    return {
        initTzStrip: initTzStrip
    };
});
