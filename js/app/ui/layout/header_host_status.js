/**
 * 호스트 상태 인디케이터 (map header)
 * -> 헤더의 점 하나. 클릭하면 상세 패널이 열린다.
 *
 * "호스트"라고 부르는 이유: 이 프로젝트에서 "server"는 이미 EVE Tranquility 를
 * 가리킨다(sticky panel 의 server_panel). 여기서 보여주는 것은 Pathfinder 가
 * 돌아가는 우리 박스다.
 *
 * 요청 정책:
 *  - 점 색을 칠하기 위해 로드 후 1회 받아온다. 점이 회색으로 떠 있으면
 *    "한눈에 보는 상태"라는 목적 자체가 사라진다(꺼진 것처럼 보인다)
 *  - 첫 요청은 페이지 로드 버스트가 지나간 뒤로 미룬다. 상태 표시는 급하지 않은데
 *    로드 순간은 번들/템플릿/맵 데이터가 몰리는 가장 나쁜 타이밍이다
 *  - 이후 2분마다 점 색만 갱신한다. **한 번 칠하고 끝내면 안 된다** — 탭을 하루
 *    종일 열어두는 사용 패턴이라, 굳어버린 초록 점은 없느니만 못한 거짓 신호가 된다
 *  - 상세는 열 때 받아오되, 서버가 알려준 cacheTtl 안이면 그 값을 재사용한다
 *
 * 갱신 주기를 2분으로 잡은 근거 (이 박스는 2코어다):
 *  동시 접속 ~15명 × 30회/시간 = 450 req/시간. 현재 총 트래픽이 ~4,500 req/시간이라
 *  약 +10%. 서버는 30초 캐시(PF_SERVER_STATUS)라 실제 계산은 분당 2회 이하이고
 *  나머지는 Redis 조회다. 더 짧게 잡으면 세션 처리 비용이 그대로 곱해진다.
 *  숨은 탭은 아예 건너뛰므로 실제 비용은 이보다 낮다 — 2026-07-24 에 폴링 루프가
 *  이 박스에 요청 스톰(하루 20,209콜 = 전체의 34%)을 만든 전례가 있어 보수적으로 잡는다.
 */

define([
    'jquery',
    'app/util'
], ($, Util) => {
    'use strict';

    let config = {
        indicatorClass: 'pf-head-host-status',                                  // class for header indicator (the dot)
        dotClass: 'pf-head-host-status-dot',                                    // class for the colored dot itself
        panelClass: 'pf-head-host-panel',                                       // class for detail panel (appended to body)
        panelOpenClass: 'pf-head-host-panel--open',                             // class for opened panel
        rowClass: 'pf-head-host-row',                                           // class for a single stat row
        copyClass: 'pf-head-host-copy',                                         // class for "copy for report" button

        url: '/api/rest/ServerStatus',
        fetchTimeoutMs: 8000,                                                   // give up rather than hang the panel open with a spinner
        initialFetchDelayMs: 3000,                                              // stay off the page-load burst (bundle/templates/map data)
        refreshMs: 120 * 1000                                                   // dot refresh (visible tabs only) — see cost note below
    };

    // level -> [dot color class, label]
    // 색은 기존 txt-color 팔레트를 그대로 쓴다 (sass/_main-colorpallet.scss)
    let LEVEL = {
        ok:       ['txt-color-greenLight', '정상'],
        warn:     ['txt-color-orange',     '부하'],
        degraded: ['txt-color-red',        '저하'],
        unknown:  ['txt-color-grayLight',  '알 수 없음']
    };

    let TRACKING = {
        ok:    ['txt-color-greenLight', '정상'],
        lag:   ['txt-color-orange',     '지연'],
        stale: ['txt-color-red',        '끊김'],
        none:  ['txt-color-grayLight',  '위치 없음']
    };

    let cache = null;                                                           // last payload
    let cacheAt = 0;                                                            // epoch(ms) the payload was received
    let lastFetchAt = 0;                                                        // epoch(ms) of the last ATTEMPT (success or not)
    let inFlight = null;                                                        // de-dupe concurrent opens

    /**
     * @returns {boolean} true while the cached payload is still within its server-declared TTL
     */
    let cacheValid = () => {
        if(!cache){
            return false;
        }
        let ttlMs = ((cache.cacheTtl || 30) * 1000);
        return (Date.now() - cacheAt) < ttlMs;
    };

    /**
     * @returns {Promise<object>}
     */
    let fetchStatus = () => {
        if(inFlight){
            return inFlight;
        }

        inFlight = new Promise(resolve => {
            $.ajax({
                url: config.url,
                dataType: 'json',
                timeout: config.fetchTimeoutMs
            }).done(data => {
                cache = data;
                cacheAt = Date.now();
                resolve(data);
            }).fail(() => {
                // 실패를 캐시하지 않는다 -> 다음에 열면 다시 시도한다
                resolve(null);
            }).always(() => {
                inFlight = null;
                // 성공 여부와 무관하게 "마지막 시도" 시각을 남긴다.
                // cacheAt(성공 시각)만 보면, 서버가 아파서 계속 실패하는 동안
                // cacheAt 이 낡은 채로 멈춰 visibilitychange 조건이 항상 참이 되고
                // 탭을 오갈 때마다 재요청한다 — 하필 박스가 이미 아픈 시점에.
                lastFetchAt = Date.now();
            });
        });

        return inFlight;
    };

    /**
     * n초 전 → 사람이 읽는 문자열
     * @param seconds
     * @returns {string}
     */
    let humanAge = seconds => {
        if(seconds === null || seconds === undefined){
            return '-';
        }
        if(seconds < 60){
            return seconds + '초 전';
        }
        if(seconds < 3600){
            return Math.floor(seconds / 60) + '분 전';
        }
        return Math.floor(seconds / 3600) + '시간 전';
    };

    /**
     * 값이 없을 수 있는 지표는 '-' 로 (0 과 "측정 불가"를 섞지 않는다)
     * @param value
     * @param suffix
     * @returns {string}
     */
    let val = (value, suffix = '') => {
        return (value === null || value === undefined) ? '-' : (value + suffix);
    };

    /**
     * @param icon      FontAwesome icon class
     * @param label
     * @param valueHtml
     * @returns {string}
     */
    let row = (icon, label, valueHtml) => `
        <li class="${config.rowClass}">
            <i class="fas fa-fw ${icon} pf-head-host-icon"></i>
            <div class="pf-head-host-body">
                <span class="pf-head-host-label">${label}</span>
                <span class="pf-head-host-value">${valueHtml}</span>
            </div>
        </li>`;

    /**
     * 지금 가장 많이 차 있는 자원과 그 사용률.
     *
     * 헤드라인 숫자로 backpressure score 를 쓰지 않는 이유:
     * 그 점수는 "폴링을 덜어낼지" 판단하는 **스로틀 트리거**지 건강도 게이지가 아니다.
     * 워커는 한도의 50%(=16개)를 넘어야, 메모리는 워킹셋이 70%(=840MB)를 넘어야,
     * 지연은 ESI 평균이 2초를 넘어야 비로소 0 을 벗어나고, 합산값도 5 미만이면
     * 0 으로 스냅된다. 평상시(워커 1~3, 메모리 13%)에는 정의상 항상 0 이라
     * "계산이 안 되는 값"으로 보인다. 실제로 스톰 때만 움직인다(7/24 max 20).
     *
     * 대신 항상 움직이고 병목이 어디인지도 알려주는 값을 보여준다.
     *
     * @param d
     * @returns {{pct: number|null, label: string}}
     */
    let loadSummary = d => {
        let w = d.worker || {};
        let m = d.memory || {};
        let c = d.cpu || {};
        let parts = [];

        // total 이 아니라 active 로 재야 한다.
        // pm=dynamic + min_spare 8 / max_spare 16 이라 **완전 유휴일 때도 8~16개는
        // 항상 살아 있다**(콜드스타트를 피하려고 예열해 둔 것). total/limit 으로 재면
        // 무부하 상태에서도 "부하 40%" 로 보인다. 실제로 일하고 있는 것은 active 다.
        if(w.limit && (w.active !== null && w.active !== undefined)){
            parts.push({pct: Math.round(w.active * 100 / w.limit), label: '워커'});
        }
        // 컨테이너가 아니라 박스 전체 기준. 이 서버에는 pf 외에 pfdb/redis/pf-socket/
        // traefik 이 같이 살아서, 컨테이너만 보면 박스가 차오르는 것을 놓친다.
        if(m.host && m.host.percent !== null && m.host.percent !== undefined){
            parts.push({pct: Math.round(m.host.percent), label: '메모리'});
        }
        // 실측 사용률(usage)이 있으면 그것을, 없으면 loadavg 근사를 쓴다
        if(c.usage && c.usage.totalPct !== null && c.usage.totalPct !== undefined){
            parts.push({pct: Math.round(c.usage.totalPct), label: 'CPU'});
        }else if(c.perCore !== null && c.perCore !== undefined){
            parts.push({pct: Math.round(c.perCore * 100), label: 'CPU'});
        }

        if(!parts.length){
            return {pct: null, label: ''};
        }

        return parts.reduce((a, b) => (b.pct > a.pct) ? b : a);
    };

    /**
     * 슬랙에 그대로 붙일 수 있는 텍스트. 보고용이라 측정 시각을 반드시 포함한다.
     * @param d
     * @returns {string}
     */
    let asReportText = d => {
        let w = d.worker || {};
        let m = d.memory || {};
        let c = d.cpu || {};
        let t = d.tracking || {};

        return [
            `[패파 서버 상태] ${(LEVEL[d.level] || LEVEL.unknown)[1]} · 부하 ${val(loadSummary(d).pct, '%')} (${loadSummary(d).label})`,
            `스로틀: ${d.score > 0 ? '작동 중 (점수 ' + d.score + '/100)' : '없음 (점수 0)'}`,
            `측정: ${d.measuredAt ? d.measuredAt.kst : '-'} / ${d.measuredAt ? d.measuredAt.utc : '-'}`,
            `워커: 구동중 ${val(w.active)} / ${val(w.limit)} (예비 ${val(w.idle)}, 대기열 ${val(w.queue)})`,
            `메모리: 패파 ${val((m.container||{}).workingSetMb, 'MB')} + 기타 ${val(m.otherMb, 'MB')} = ${val((m.host||{}).usedMb, 'MB')} / ${val((m.host||{}).totalMb, 'MB')}, 여유 ${val((m.host||{}).availableMb, 'MB')}`,
            `CPU(박스 전체): ${c.usage ? '사용 ' + val(c.usage.totalPct, '%') + ' (코어별 ' + c.usage.cores.join('% / ') + '%)' : 'load ' + val(c.load1) + ' / ' + val(c.cores) + '코어'}` +
                (c.pressure ? `, 대기율 ${c.pressure.avg60}%` : ''),
            `내 트래킹: ${(TRACKING[t.state] || TRACKING.none)[1]}` +
                (t.ageSeconds !== null && t.ageSeconds !== undefined ? ` — 위치 갱신 ${humanAge(t.ageSeconds)}` : '') +
                ((d.peers && d.peers.count > 1 && d.peers.avgAgeSeconds !== null && t.state !== 'ok')
                    ? (t.ageSeconds > Math.max(60, d.peers.avgAgeSeconds * 3)
                        ? ' (다른 접속자는 정상 — 내 연결 문제로 추정)'
                        : ' (다른 접속자도 늦음 — 서버 문제로 추정)')
                    : '')
        ].join('\n');
    };

    /**
     * @param d payload (or null on failure)
     * @returns {string}
     */
    let renderPanel = d => {
        if(!d){
            return `<div class="pf-head-host-empty">상태를 가져오지 못했습니다.</div>`;
        }

        let [levelColor, levelText] = LEVEL[d.level] || LEVEL.unknown;
        let w = d.worker || {};
        let m = d.memory || {};
        let c = d.cpu || {};
        let t = d.tracking || {};
        let [trackColor, trackText] = TRACKING[t.state] || TRACKING.none;
        let load = loadSummary(d);
        // 코어별 사용률 막대. usage 는 두 스냅샷의 차이라 첫 호출 직후엔 없을 수 있다
        // (그때는 loadavg 로 폴백해 값 자체는 항상 보인다)
        let cu = (c.usage && Array.isArray(c.usage.cores) && c.usage.cores.length) ? c.usage : null;
        // 막대들은 반드시 전용 행 컨테이너에 감싼다. 감싸지 않고 텍스트 사이에
        // inline 으로 흘리면 값 텍스트 옆에 하나, 다음 줄에 하나로 제멋대로 감긴다.
        let coreBars = cu
            ? `<span class="pf-head-host-cores">` +
                cu.cores.map(pct =>
                    `<span class="pf-head-host-bar"><i class="pf-head-host-bar--active" style="width:${Math.min(100, Math.round(pct))}%"></i></span>`
                ).join('') +
              `</span>`
            : '';

        // 워커 사용률 막대 — 한도 대비 얼마나 찼는지가 한눈에 보여야 한다
        // 막대는 구동중(active) / 예비(idle) 두 칸. total 은 예열된 풀 크기라
        // 무부하에서도 8~16 이므로 total 로 재면 안 논다.
        let workerPct     = (w.limit && w.active) ? Math.min(100, Math.round(w.active * 100 / w.limit)) : 0;
        let workerIdlePct = (w.limit && w.idle)   ? Math.min(100 - workerPct, Math.round(w.idle * 100 / w.limit)) : 0;
        let workerFree    = (w.limit && (w.total !== null && w.total !== undefined)) ? Math.max(0, w.limit - w.total) : null;
        let mh = (m.host || {});
        let mc = (m.container || {});
        // 막대는 박스 전체 기준. 패파 몫과 나머지(DB·Redis·소켓·OS)를 나눠 칠한다
        let memPfPct    = (mh.totalMb && mc.workingSetMb) ? Math.round(mc.workingSetMb * 100 / mh.totalMb) : 0;
        let memOtherPct = (mh.totalMb && (m.otherMb !== null && m.otherMb !== undefined)) ? Math.round(m.otherMb * 100 / mh.totalMb) : 0;

        // 트래킹 행은 "내 위치가 지도에 잘 반영되고 있나"라는 질문 하나에 답한다.
        // 평상시: "위치 갱신 3초 전 (J115405)" — 짧게, 뭐가 갱신됐는지 명시.
        // 문제일 때만 남들과 비교해 원인을 문장으로 진단한다:
        //   남들은 정상  → 내 쪽(ESI 토큰/클라이언트) 문제
        //   남들도 늦음  → 서버 쪽 문제
        // 정상일 때 "접속자 N명 평균 X초 전" 같은 원자료를 나열하면 아무도 못 읽는다.
        let p = d.peers || null;
        let trackingHtml = '';
        if(t.ageSeconds === null || t.ageSeconds === undefined){
            trackingHtml = `<span class="txt-color ${trackColor}">${trackText}</span>`;
        }else if(t.state === 'ok'){
            trackingHtml =
                `<span class="txt-color ${trackColor}">${trackText}</span>` +
                ` <span class="pf-head-host-dim">위치 갱신 ${humanAge(t.ageSeconds)}${t.systemName ? ' (' + t.systemName + ')' : ''}</span>`;
        }else{
            let diagnosis = '';
            if(p && p.count > 1 && p.avgAgeSeconds !== null){
                diagnosis = (t.ageSeconds > Math.max(60, p.avgAgeSeconds * 3))
                    ? `<br><span class="txt-color txt-color-orange">다른 접속자들은 정상이에요 — 내 연결 쪽 문제일 가능성이 높아요</span>`
                    : `<br><span class="txt-color txt-color-orange">다른 접속자들도 같이 늦어지고 있어요 — 서버 쪽 문제예요</span>`;
            }
            trackingHtml =
                `<span class="txt-color ${trackColor}">${trackText}</span>` +
                ` <span class="pf-head-host-dim">마지막 위치 갱신 ${humanAge(t.ageSeconds)}</span>` +
                diagnosis;
        }
        let trackingRow = t.available === false
            ? ''
            : row('fa-satellite-dish', '내 트래킹', trackingHtml);

        return `
            <div class="pf-head-host-head">
                <span class="txt-color ${levelColor}"><i class="fas fa-fw fa-circle"></i> ${levelText}</span>
                <span class="pf-head-host-score">부하 ${val(load.pct, '%')}<span class="pf-head-host-dim">${load.label}</span></span>
            </div>
            ${d.score > 0 ? `<div class="pf-head-host-throttle">
                <i class="fas fa-fw fa-exclamation-triangle"></i>
                스로틀 작동 중 (점수 ${val(d.score)}/100) — 위치 폴링이 일부 생략됩니다
            </div>` : ''}
            <ul class="pf-head-host-list">
                ${row('fa-microchip', '워커',
                    `${val(w.active)} <span class="pf-head-host-dim">/ ${val(w.limit)} 구동중</span>` +
                    // 막대를 구동중 / 예비 두 칸으로 나눠 색으로 구분한다.
                    // 물음표 툴팁으로 설명해야 하는 UI 는 이미 진 것이다 — 용어가 자명하면 된다.
                    `<span class="pf-head-host-bar">` +
                        `<i class="pf-head-host-bar--active" style="width:${workerPct}%"></i>` +
                        `<i class="pf-head-host-bar--idle" style="width:${workerIdlePct}%"></i>` +
                    `</span>` +
                    `<span class="pf-head-host-dim">예비 ${val(w.idle)} · 여유 ${val(workerFree)}${w.queue ? ' · 대기열 ' + w.queue : ''}</span>`)}
                ${row('fa-memory', '메모리',
                    `${val(mh.usedMb, 'MB')} <span class="pf-head-host-dim">/ ${val(mh.totalMb, 'MB')} 사용</span>` +
                    // 패파(진한) / 기타 서비스(연한) / 빈칸 = 여유 — 워커 막대와 같은 문법
                    `<span class="pf-head-host-bar">` +
                        `<i class="pf-head-host-bar--active" style="width:${memPfPct}%"></i>` +
                        `<i class="pf-head-host-bar--idle" style="width:${memOtherPct}%"></i>` +
                    `</span>` +
                    `<span class="pf-head-host-dim">패파 ${val(mc.workingSetMb, 'MB')} · 기타 ${val(m.otherMb, 'MB')} · 여유 ${val(mh.availableMb, 'MB')}</span>`)}
                ${row('fa-tachometer-alt', 'CPU',
                    `${cu ? val(cu.totalPct, '%') : val(c.load1)} <span class="pf-head-host-dim">${cu ? '사용' : '/ ' + val(c.cores) + '코어'}</span>` +
                    // 코어마다 막대 하나씩 — "코어별로 얼마나 도는지"가 한눈에
                    `${coreBars}` +
                    `<span class="pf-head-host-dim">load ${val(c.load1)}${c.pressure ? ' · 대기율 ' + c.pressure.avg60 + '%' : ''}</span>`)}
                ${trackingRow}
            </ul>
            <div class="pf-head-host-foot">
                <span class="pf-head-host-dim" title="${d.measuredAt ? d.measuredAt.utc : ''}">
                    ${d.measuredAt ? d.measuredAt.kst : '-'} 기준 · 최대 ${val(d.cacheTtl)}초 지연
                </span>
                <a href="javascript:void(0);" class="${config.copyClass}" title="보고용 텍스트 복사">
                    <i class="fas fa-fw fa-copy"></i> 복사
                </a>
            </div>`;
    };

    /**
     * 점 색만 갱신 (패널을 열지 않아도 마지막으로 받은 값이 있으면 반영)
     * @param indicatorEl
     */
    let renderDot = indicatorEl => {
        let [color] = cache ? (LEVEL[cache.level] || LEVEL.unknown) : LEVEL.unknown;
        indicatorEl.find('.' + config.dotClass)
            .attr('class', 'fas fa-fw fa-circle txt-color ' + config.dotClass + ' ' + color);
    };

    /**
     * 클립보드 복사.
     *
     * Util.copyToClipboard() 를 쓰지 않는다:
     *  - 실패해도 reject 하지 않고 resolve({data:false}) 라 .then(성공,실패) 로는
     *    실패를 알 수 없다
     *  - 내부에서 navigator.permissions.query({name:'clipboard-write'}) 를 부르는데
     *    이 권한 이름은 Firefox 등에서 지원되지 않아 query() 가 reject 하고,
     *    거기에 .catch 가 없어 **프라미스가 영영 settle 되지 않는다** → 버튼이
     *    아무 반응도 하지 않는다 (실제 증상)
     *
     * writeText 는 사용자 제스처 + https 컨텍스트면 권한 조회 없이 바로 동작한다.
     * 구형/비보안 컨텍스트를 위해 textarea + execCommand 폴백을 둔다.
     *
     * @param text
     * @returns {Promise<boolean>} 복사 성공 여부 (reject 하지 않는다)
     */
    let copyText = text => {
        if(navigator.clipboard && navigator.clipboard.writeText){
            return navigator.clipboard.writeText(text).then(() => true, () => fallbackCopy(text));
        }

        return Promise.resolve(fallbackCopy(text));
    };

    /**
     * @param text
     * @returns {boolean}
     */
    let fallbackCopy = text => {
        let el = document.createElement('textarea');
        el.value = text;
        // 화면 밖으로 빼되 focus 는 받을 수 있어야 execCommand 가 동작한다
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.top = '-1000px';
        document.body.appendChild(el);

        let ok = false;
        try{
            el.select();
            ok = document.execCommand('copy');
        }catch(e){
            ok = false;
        }

        document.body.removeChild(el);

        return ok;
    };

    /**
     * @param panelEl
     * @param indicatorEl
     */
    let positionPanel = (panelEl, indicatorEl) => {
        let rect = indicatorEl[0].getBoundingClientRect();
        let width = panelEl.outerWidth();
        // 오른쪽 끝에 붙는 헤더 항목이라 화면 밖으로 나가지 않게 좌측으로 정렬한다
        let left = Math.min(rect.left, window.innerWidth - width - 8);
        panelEl.css({
            top: (rect.bottom + 6) + 'px',
            left: Math.max(8, left) + 'px'
        });
    };

    /**
     * @param indicatorEl jQuery header element
     */
    let initHostStatus = indicatorEl => {
        indicatorEl = $(indicatorEl);
        if(!indicatorEl.length){
            return;
        }

        // 패널은 body 에 붙인다 — 헤더 안에 두면 navbar 의 overflow 에 잘린다
        // (pf-head-tz-tip 이 같은 이유로 body 에 붙는다)
        let panelEl = $('<div>', {class: config.panelClass}).appendTo('body');

        let close = () => {
            panelEl.removeClass(config.panelOpenClass);
            $(document).off('click.hostStatus');
            $(window).off('resize.hostStatus');
        };

        let open = () => {
            panelEl.html('<div class="pf-head-host-empty">불러오는 중…</div>')
                .addClass(config.panelOpenClass);
            positionPanel(panelEl, indicatorEl);

            let show = data => {
                panelEl.html(renderPanel(data));
                renderDot(indicatorEl);
                positionPanel(panelEl, indicatorEl);
            };

            if(cacheValid()){
                show(cache);
            }else{
                fetchStatus().then(show);
            }

            // 바깥 클릭으로 닫기 (패널 내부 클릭은 유지 — 복사 버튼 때문)
            $(document).on('click.hostStatus', e => {
                if(!$(e.target).closest('.' + config.panelClass).length &&
                    !$(e.target).closest('.' + config.indicatorClass).length){
                    close();
                }
            });
            $(window).on('resize.hostStatus', () => positionPanel(panelEl, indicatorEl));
        };

        indicatorEl.on('click', e => {
            e.preventDefault();
            e.stopPropagation();
            panelEl.hasClass(config.panelOpenClass) ? close() : open();
        });

        // 점 색 갱신 -------------------------------------------------------------------------------------------------
        // 숨은 탭에서는 건너뛴다. 백그라운드 탭의 점은 아무도 보지 않으므로 순수 낭비다.
        let refreshDot = () => {
            if(document.visibilityState === 'hidden'){
                return;
            }
            fetchStatus().then(() => renderDot(indicatorEl));
        };

        // 로드 버스트를 피해 잠깐 미룬 뒤 최초 1회
        setTimeout(refreshDot, config.initialFetchDelayMs);
        setInterval(refreshDot, config.refreshMs);

        // 숨어 있는 동안 주기를 건너뛰었으므로, 돌아왔을 때 값이 낡았으면 즉시 갱신한다.
        // 기준은 cacheValid()(30초)가 아니라 refreshMs 다 — 30초로 잡으면 탭을 자주
        // 오가는 사람이 2분 주기보다 오히려 더 많이 요청하게 된다.
        $(document).on('visibilitychange.hostStatus', () => {
            if(document.visibilityState === 'visible' && (Date.now() - lastFetchAt) > config.refreshMs){
                refreshDot();
            }
        });

        panelEl.on('click', '.' + config.copyClass, function(e){
            e.preventDefault();
            if(!cache){
                return;
            }
            let btn = $(this);
            let original = btn.html();

            copyText(asReportText(cache)).then(ok => {
                btn.html(ok
                    ? '<i class="fas fa-fw fa-check"></i> 복사됨'
                    : '<i class="fas fa-fw fa-times"></i> 실패');
                // 원래 라벨로 되돌린다 — 패널은 계속 열려 있을 수 있어서
                // "복사됨"이 박제되면 다음에 눌렀는지 안 눌렀는지 알 수 없다
                setTimeout(() => btn.html(original), 1500);
            });
        });
    };

    return {
        initHostStatus: initHostStatus
    };
});
