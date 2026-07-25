/**
 * 호스트 상태 인디케이터 (map header)
 * -> 헤더의 점 하나. 클릭하면 상세 패널이 열린다.
 *
 * "호스트"라고 부르는 이유: 이 프로젝트에서 "server"는 이미 EVE Tranquility 를
 * 가리킨다(sticky panel 의 server_panel). 여기서 보여주는 것은 Pathfinder 가
 * 돌아가는 우리 박스다.
 *
 * lazy: 페이지 로드 시에는 아무 요청도 하지 않는다. 처음 열 때 한 번 받아오고
 * 그 뒤로는 서버가 알려준 cacheTtl 동안 재사용한다. 폴링하지 않는다 —
 * 폴링 루프가 2코어 박스에 요청 스톰을 만든 전례가 있다(memo toast, 2026-07-24).
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
        fetchTimeoutMs: 8000                                                    // give up rather than hang the panel open with a spinner
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
            <i class="fa-li fas fa-fw ${icon}"></i>
            <span class="pf-head-host-label">${label}</span>
            <span class="pf-head-host-value">${valueHtml}</span>
        </li>`;

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
            `[Pathfinder 호스트 상태] ${(LEVEL[d.level] || LEVEL.unknown)[1]} (score ${val(d.score)})`,
            `측정: ${d.measuredAt ? d.measuredAt.kst : '-'} / ${d.measuredAt ? d.measuredAt.utc : '-'}`,
            `워커: ${val(w.active)} 실행 + ${val(w.idle)} 대기 = ${val(w.total)} / 한도 ${val(w.limit)}, 큐 ${val(w.queue)}`,
            `메모리: ${val(m.workingSetMb, 'MB')} / ${val(m.limitMb, 'MB')} (${val(m.percent, '%')})`,
            `CPU(호스트): load ${val(c.load1)} / ${val(c.cores)}코어 = 코어당 ${val(c.perCore)}` +
                (c.pressure ? `, 대기율 ${c.pressure.avg60}%` : ''),
            `내 트래킹: ${(TRACKING[t.state] || TRACKING.none)[1]}` +
                (t.ageSeconds !== null && t.ageSeconds !== undefined ? ` (${humanAge(t.ageSeconds)})` : '')
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

        // 워커 사용률 막대 — 한도 대비 얼마나 찼는지가 한눈에 보여야 한다
        let workerPct = (w.total && w.limit) ? Math.min(100, Math.round(w.total * 100 / w.limit)) : 0;
        let memPct = (m.percent === null || m.percent === undefined) ? 0 : Math.min(100, m.percent);

        let trackingRow = t.available === false
            ? ''
            : row('fa-satellite-dish', '내 트래킹',
                `<span class="txt-color ${trackColor}">${trackText}</span>` +
                (t.ageSeconds === null || t.ageSeconds === undefined
                    ? ''
                    : ` <span class="pf-head-host-dim">${humanAge(t.ageSeconds)}${t.systemName ? ' · ' + t.systemName : ''}</span>`)
            );

        return `
            <div class="pf-head-host-head">
                <span class="txt-color ${levelColor}"><i class="fas fa-fw fa-circle"></i> ${levelText}</span>
                <span class="pf-head-host-dim">score ${val(d.score)}</span>
            </div>
            <ul class="fa-ul pf-head-host-list">
                ${row('fa-microchip', '워커',
                    `${val(w.total)} <span class="pf-head-host-dim">/ ${val(w.limit)}</span>` +
                    `<span class="pf-head-host-bar"><i style="width:${workerPct}%"></i></span>` +
                    `<span class="pf-head-host-dim">실행 ${val(w.active)} · 대기 ${val(w.idle)}${w.queue ? ' · 큐 ' + w.queue : ''}</span>`)}
                ${row('fa-memory', '메모리',
                    `${val(m.workingSetMb, 'MB')} <span class="pf-head-host-dim">/ ${val(m.limitMb, 'MB')}</span>` +
                    `<span class="pf-head-host-bar"><i style="width:${memPct}%"></i></span>` +
                    `<span class="pf-head-host-dim">${val(m.percent, '%')}</span>`)}
                ${row('fa-tachometer-alt', 'CPU',
                    `${val(c.load1)} <span class="pf-head-host-dim">/ ${val(c.cores)}코어</span>` +
                    `<span class="pf-head-host-dim">코어당 ${val(c.perCore)}${c.pressure ? ' · 대기율 ' + c.pressure.avg60 + '%' : ''}</span>`)}
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

        panelEl.on('click', '.' + config.copyClass, function(e){
            e.preventDefault();
            if(!cache){
                return;
            }
            let btn = $(this);
            Util.copyToClipboard(asReportText(cache)).then(
                () => btn.html('<i class="fas fa-fw fa-check"></i> 복사됨'),
                () => btn.html('<i class="fas fa-fw fa-times"></i> 실패')
            );
        });
    };

    return {
        initHostStatus: initHostStatus
    };
});
