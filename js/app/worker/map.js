'use strict';

// "fake" window object will contain "MsgWorker" after import
let window = {};                    // jshint ignore:line

// import "MsgWorker" class
self.importScripts( self.name );    // jshint ignore:line

let MsgWorker = window.MsgWorker;
let socket = null;
let ports = [];
let characterPorts = [];

// reconnect state ====================================================================================================
let wsUri = null;              // 마지막으로 접속한 URI — onclose 후 재연결에 사용
let reconnectDelay = 1000;     // 현재 backoff 지연(ms)
const RECONNECT_DELAY_MIN = 1000;
const RECONNECT_DELAY_MAX = 30000;
let reconnectTimer = null;
// close code(1000/1001)는 프록시·서버 재시작 시에도 올 수 있어 신뢰도가 낮다.
// 의도적 종료는 이 플래그로 명시하고, onclose는 플래그만 보고 재연결 여부를 결정한다.
let intentionallyClosed = false;

/** 맵 SharedWorker WS 진단 — 토큰·전체 URL 미포함, host만 */
let mapWsHostFromUri = uri => {
    if(!uri) return null;
    try {
        return new URL(uri).host;
    }catch(e){
        return null;
    }
};

let logMapWsDiag = (event, details) => {
    console.log('[PF][MapWS]', Object.assign({
        ts: new Date().toISOString(),
        event,
        host: mapWsHostFromUri(wsUri)
    }, details || {}));
};

let scheduleReconnect = () => {
    if(reconnectTimer !== null) return; // 중복 예약 방지
    const delayMs = reconnectDelay;
    const nextBackoffMs = Math.min(reconnectDelay * 2, RECONNECT_DELAY_MAX);
    logMapWsDiag('reconnect_scheduled', {
        delayMs,
        intentionallyClosed,
        nextBackoffMs
    });
    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        if(socket === null && wsUri !== null && !intentionallyClosed){
            connectSocket(wsUri);
        }
    }, delayMs);
    // exponential backoff: 1s → 2s → 4s → … → 30s
    reconnectDelay = nextBackoffMs;
};

// init "WebSocket" connection ========================================================================================
let connectSocket = uri => {
    logMapWsDiag('connect_attempt', { host: mapWsHostFromUri(uri) });
    // #region agent log
    fetch('http://127.0.0.1:7769/ingest/7d8aed94-a257-4347-a628-ee32df3e6718',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'c1d0c4'},body:JSON.stringify({sessionId:'c1d0c4',runId:'pre-fix',hypothesisId:'H1',location:'worker/map.js:connect_attempt',message:'Map worker connect attempt',data:{host:mapWsHostFromUri(uri)},timestamp:Date.now()})}).catch(()=>{});
    // #endregion
    socket = new WebSocket(uri);

    // "WebSocket" open -----------------------------------------------------------------------
    socket.onopen = () => {
        intentionallyClosed = false;          // 연결 성공 시 플래그 리셋
        reconnectDelay = RECONNECT_DELAY_MIN; // 재연결 성공 시 backoff 초기화

        logMapWsDiag('open', { readyState: socket.readyState });

        let MsgWorkerOpen = new MsgWorker('ws:open');
        MsgWorkerOpen.meta({readyState: socket.readyState});
        broadcastPorts(MsgWorkerOpen); // 모든 탭에 open 알림 → 각 탭이 subscribe 재전송
    };

    // "WebSocket" message --------------------------------------------------------------------
    socket.onmessage = e => {
        let response = JSON.parse(e.data);

        let MsgWorkerSend = new MsgWorker('ws:send');
        // 서버는 task 또는 type 필드로 메시지 타입을 전송함
        // (standalone.hello, combatAggregation.toast 등은 type 필드 사용)
        MsgWorkerSend.task( response.task || response.type || null );
        MsgWorkerSend.meta({
            readyState: socket ? socket.readyState : WebSocket.CLOSED,
            characterIds: response.characterIds || null
        });
        // load가 없는 메시지(type-only)는 response 전체를 data로 넘겨 expiresIn 등 접근 가능
        MsgWorkerSend.data( response.load !== undefined ? response.load : response );

        broadcastPorts(MsgWorkerSend);
    };

    // "WebSocket" close ----------------------------------------------------------------------
    socket.onclose = closeEvent => {
        const willReconnect = !intentionallyClosed && wsUri !== null;
        logMapWsDiag('close', {
            code: closeEvent.code,
            reason: closeEvent.reason || '',
            wasClean: closeEvent.wasClean,
            intentionallyClosed,
            willReconnect
        });
        // #region agent log
        fetch('http://127.0.0.1:7769/ingest/7d8aed94-a257-4347-a628-ee32df3e6718',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'c1d0c4'},body:JSON.stringify({sessionId:'c1d0c4',runId:'pre-fix',hypothesisId:'H1',location:'worker/map.js:onclose',message:'Map worker websocket closed',data:{code:closeEvent.code,reason:closeEvent.reason || null,wasClean:closeEvent.wasClean,intentionallyClosed:intentionallyClosed,willReconnect:willReconnect,host:mapWsHostFromUri(wsUri)},timestamp:Date.now()})}).catch(()=>{});
        // #endregion

        let MsgWorkerClosed = new MsgWorker('ws:closed');
        MsgWorkerClosed.meta({
            readyState: WebSocket.CLOSED,
            code: closeEvent.code,
            reason: closeEvent.reason,
            wasClean: closeEvent.wasClean
        });

        broadcastPorts(MsgWorkerClosed);
        socket = null;

        // intentionallyClosed 플래그가 없으면 항상 재연결 시도.
        // close code(1000/1001)는 프록시·서버 재시작 시에도 올 수 있어
        // 플래그 기반 판단이 더 신뢰성이 높다.
        if(willReconnect){
            scheduleReconnect();
        }
    };

    // "WebSocket" error ----------------------------------------------------------------------
    socket.onerror = () => {
        logMapWsDiag('error', {
            readyState: socket ? socket.readyState : WebSocket.CLOSED
        });
        // #region agent log
        fetch('http://127.0.0.1:7769/ingest/7d8aed94-a257-4347-a628-ee32df3e6718',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'c1d0c4'},body:JSON.stringify({sessionId:'c1d0c4',runId:'pre-fix',hypothesisId:'H1',location:'worker/map.js:onerror',message:'Map worker websocket error',data:{readyState:socket ? socket.readyState : WebSocket.CLOSED,host:mapWsHostFromUri(wsUri)},timestamp:Date.now()})}).catch(()=>{});
        // #endregion
        let MsgWorkerError = new MsgWorker('ws:error');
        MsgWorkerError.meta({
            readyState: socket ? socket.readyState : WebSocket.CLOSED
        });
        // error 직후 onclose가 반드시 발생하므로 재연결은 onclose에서 처리
        broadcastPorts(MsgWorkerError);
    };
};

let initSocket = uri => {
    wsUri = uri; // URI 저장 — 재연결 시 사용

    let MsgWorkerOpen = new MsgWorker('ws:open');

    if(socket === null){
        connectSocket(uri);
    }else{
        // socket still open
        MsgWorkerOpen.meta({readyState: socket.readyState});
        sendToCurrentPort(MsgWorkerOpen);
    }
};

// send message to port(s) ============================================================================================
let sendToCurrentPort = load => {
    ports[ports.length - 1].postMessage(load);
};

let broadcastPorts = load => {
    // default: sent to all ports
    let sentToPorts = ports;

    // check if send() is limited to some ports
    let meta = load.meta();
    if(
        meta &&
        meta.characterIds &&
        meta.characterIds !== 'undefined' &&
        meta.characterIds instanceof Array
    ){
        // ... get ports for characterIds
        sentToPorts = getPortsByCharacterIds(meta.characterIds);
    }

    for(let i = 0; i < sentToPorts.length; i++){
        sentToPorts[i].postMessage(load);
    }
};

// port functions =====================================================================================================
let addPort = (port, characterId) => {
    characterId = parseInt(characterId);

    if(characterId > 0){
        characterPorts.push({
            characterId: characterId,
            port: port
        });
    }else{
        ports.push(port);
    }
};

let getPortsByCharacterIds = characterIds => {
    let ports = [];

    for(let i = 0; i < characterPorts.length; i++){
        for(let j = 0; j < characterIds.length; j++){
            if(characterPorts[i].characterId === characterIds[j]){
                ports.push(characterPorts[i].port);
            }
        }
    }

    return ports;
};

/**
 *
 * @param port
 * @returns {int[]}
 */
let removePort = port => {
    let characterIds = [];

    // reverse loop required because of array index reset after splice()
    let i = characterPorts.length;
    while(i--){
        if(characterPorts[i].port === port){
            // collectt all character Ids mapped to the removed port
            characterIds.push(characterPorts[i].characterId);
            characterPorts.splice(i, 1);
        }
    }

    let j = ports.length;
    while(j--){
        if(ports[j] === port){
            ports.splice(j, 1);
        }
    }

    // return unique characterIds
    return [...new Set(characterIds)];
};

// "SharedWorker" connection ==========================================================================================
self.addEventListener('connect', event => {   // jshint ignore:line
    let port = event.ports[0];
    addPort(port);

    port.addEventListener('message', (e) => {
        let MsgWorkerMessage = e.data;
        Object.setPrototypeOf(MsgWorkerMessage, MsgWorker.prototype);

        switch(MsgWorkerMessage.command){
            case 'ws:init':
                let data = MsgWorkerMessage.data();
                // add character specific port (for broadcast) to individual ports (tabs)
                addPort(port, data.characterId);
                initSocket(data.uri);
                break;
            case 'ws:send':
                if(socket && socket.readyState === WebSocket.OPEN){
                    socket.send(JSON.stringify({
                        task: MsgWorkerMessage.task(),
                        load: MsgWorkerMessage.data()
                    }));
                }
                break;
            case 'sw:closePort':
                port.close();

                // remove port from store
                // -> charIds managed by closed port
                let characterIds = removePort(port);

                // check if there are still other ports active that manage removed ports
                // .. if not -> send "unsubscribe" event to WebSocket server
                let portsLeft = getPortsByCharacterIds(characterIds);

                if(!portsLeft.length && socket && socket.readyState === WebSocket.OPEN){
                    socket.send(JSON.stringify({
                        task: MsgWorkerMessage.task(),
                        load: characterIds
                    }));
                }
                break;
            case 'ws:close':
                // 명시적 종료 — 이후 onclose에서 재연결하지 않는다
                intentionallyClosed = true;
                logMapWsDiag('intentional_close_requested', {});
                if(reconnectTimer !== null){
                    clearTimeout(reconnectTimer);
                    reconnectTimer = null;
                }
                if(socket && socket.readyState === WebSocket.OPEN){
                    socket.close(1000, 'intentional');
                }
                break;
        }
    }, false);

    port.start();
}, false);