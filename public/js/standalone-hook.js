(function () {
  var inflight = null; // Promise<string|null>
  var inflightAt = 0; // inflight 발급(요청 시작) 시각 ms — 신선도 판단용
  /** 성공한 payload 캐시 — updateUserData 등으로 헤더가 다시 그려져도 새 링크에 재적용 */
  var cachedPayload = null;
  var cachedPayloadAt = 0; // cachedPayload 발급 시각 ms

  /**
   * 서버 PAYLOAD_TTL_SECONDS(30s)보다 짧게 잡아, 토큰이 앱(verify)에 도달하기까지의
   * 네트워크 지연 + 시계 오차 마진을 남긴다. 이보다 오래된 토큰은 stale로 보고 재발급.
   */
  var PAYLOAD_STALE_MS = 20000;

  function tokenIsFresh(at) {
    return at > 0 && Date.now() - at < PAYLOAD_STALE_MS;
  }

  /**
   * 신선한 토큰 요청을 반환.
   * - 진행 중이거나 최근(STALE 이내) 발급된 inflight가 있으면 재사용
   * - 없거나 오래됐으면 새로 발급
   * - 발급 실패(null)면 캐시를 비워 다음 시도에서 즉시 재발급되도록 함
   */
  function getFreshToken() {
    if (inflight && tokenIsFresh(inflightAt)) {
      return inflight;
    }
    inflightAt = Date.now();
    inflight = issueToken();
    inflight.then(function (p) {
      if (p == null) {
        inflight = null;
        inflightAt = 0;
      }
    });
    return inflight;
  }

  /** 앱 미설치 시 안내용 다운로드 링크 (고물 헬퍼 Google Drive) */
  var DMC_HELPER_DOWNLOAD_URL = "https://drive.google.com/drive/folders/1PcN9wwXjzH-zOKtSnCHyETu3S0f7qw6w?usp=sharing";
  var notInstalledToastTimer = null;
  var notInstalledCheckTimer = null;

  async function issueToken() {
    // 서버 ts ≈ 요청 처리 시점. 요청 시작 시각을 기준으로 잡으면 실제 토큰보다
    // 약간 더 오래된 것으로 보수적으로 계산되어 만료 위험을 줄인다.
    var startedAt = Date.now();
    try {
      var r = await fetch("/api/Standalone/issue", {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        credentials: "same-origin",
      });
      if (!r.ok) return null;
      var j = await r.json();
      if (!j || !j.ok || !j.payload) return null;
      var p = String(j.payload);
      cachedPayload = p;
      cachedPayloadAt = startedAt;
      return p;
    } catch (e) {
      return null;
    }
  }

  function setLinkHref(a, payload) {
    if (!a) return;
    var url = payload ? "pathfinder://standalone?payload=" + encodeURIComponent(payload) : "pathfinder://standalone";
    a.href = url;
  }

  /** 링크가 pathfinder://가 아니면 캐시된 payload로 href 적용 (헤더 재렌더 대응) */
  function applyCachedToLinkIfNeeded(a) {
    if (!a || !cachedPayload) return;
    if (a.href && a.href.indexOf("pathfinder://") === 0) return;
    // 만료된 payload를 href에 박으면 onClick을 안 타는 경로(가운데 클릭/새 탭)에서
    // 죽은 토큰으로 앱이 열린다. 신선할 때만 적용하고, 아니면 onClick이 재발급하게 둔다.
    if (!tokenIsFresh(cachedPayloadAt)) return;
    setLinkHref(a, cachedPayload);
  }

  /** prefetch 완료 시 링크에 href 적용. 맵 헤더가 나중에 주입되므로 재시도 */
  function applyPrefetchToLink(payload) {
    if (payload == null) return;
    var attempt = 0;
    var maxAttempts = 15;
    var intervalMs = 300;
    function tryApply() {
      var a = document.querySelector("a.pf-open-standalone");
      if (a) {
        setLinkHref(a, payload);
        return;
      }
      attempt += 1;
      if (attempt < maxAttempts) setTimeout(tryApply, intervalMs);
    }
    tryApply();
  }

  /** 헤더가 다시 그려져서 링크가 새로 생기면 캐시된 payload로 href 재적용 */
  function observeStandaloneLink() {
    var observer = new MutationObserver(function () {
      var a = document.querySelector("a.pf-open-standalone");
      if (a) applyCachedToLinkIfNeeded(a);
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function prefetchIfNeeded(e) {
    var a = e.target && e.target.closest ? e.target.closest("a.pf-open-standalone") : null;
    if (!a) return;
    // 신선한 토큰이 있으면 재사용, 없거나 오래됐으면 재발급해서 링크에 반영
    getFreshToken().then(function (payload) {
      if (payload != null) setLinkHref(a, payload);
    });
  }

  /** 버튼 아래 말풍선 토스트로 다운로드 링크 표시 (앱 미설치 감지 시) */
  function showDownloadToast(anchor) {
    var container = anchor && anchor.closest ? anchor.closest(".pf-head-open-standalone") : document.querySelector(".pf-head-open-standalone");
    if (!container) return;
    hideDownloadToast();
    container.style.position = "relative";
    var toast = document.createElement("div");
    toast.className = "pf-standalone-download-toast";
    toast.setAttribute("role", "status");
    toast.style.cssText = "position:absolute;left:0;top:100%;margin-top:4px;z-index:1050;min-width:200px;max-width:280px;padding:8px 10px;background:#333;color:#eee;font-size:12px;line-height:1.4;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,0.25);white-space:normal;";
    var link = document.createElement("a");
    link.href = DMC_HELPER_DOWNLOAD_URL;
    link.target = "_blank";
    link.rel = "noopener";
    link.style.cssText = "color:#7dd3fc;text-decoration:underline;";
    link.textContent = "다운로드";
    link.addEventListener("click", function () {
      alert("다클라 헬퍼 설치 후 exe 파일을 한번 실행해 주세요.");
    });
    toast.appendChild(document.createTextNode("프로그램이 설치되어 있지 않다면 "));
    toast.appendChild(link);
    toast.dataset.pfStandaloneToast = "1";
    container.appendChild(toast);
    notInstalledToastTimer = setTimeout(function () {
      hideDownloadToast();
    }, 8000);
    document.addEventListener("click", hideOnClickOutside);
    function hideOnClickOutside(ev) {
      if (toast.contains(ev.target) || (anchor && anchor.contains(ev.target))) return;
      hideDownloadToast();
      document.removeEventListener("click", hideOnClickOutside);
    }
  }

  function hideDownloadToast() {
    if (notInstalledToastTimer) {
      clearTimeout(notInstalledToastTimer);
      notInstalledToastTimer = null;
    }
    var toast = document.querySelector(".pf-standalone-download-toast");
    if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
  }

  async function onClick(e) {
    var a = e.target && e.target.closest ? e.target.closest("a.pf-open-standalone") : null;
    if (!a) return;

    // 다른 핸들러(기존 로직)랑 싸우지 않게: 우리가 최종이면 여기서 막자
    e.preventDefault();
    e.stopPropagation();

    var payload = null;
    try {
      // 페이지 로드 때 미리 받아둔 토큰이 stale이면 getFreshToken이 재발급한다.
      // (방치 후 첫 클릭에서 만료 토큰이 나가던 버그 방지)
      payload = await getFreshToken();
    } finally {
      inflight = null; // 1회성: 다음 클릭은 새로 발급
      inflightAt = 0;
    }

    var url = payload ? "pathfinder://standalone?payload=" + encodeURIComponent(payload) : "pathfinder://standalone";
    setLinkHref(a, payload);

    // 메인 창의 location.href를 바꾸면 beforeunload가 떠서 clearUpdateTimeouts()로 API 폴링이 멈춤.
    // iframe으로 스킴만 열면 메인 문서는 그대로라 폴링이 유지됨.
    (function openSchemeInIframe(schemeUrl) {
      var iframe = document.createElement("iframe");
      iframe.setAttribute("aria-hidden", "true");
      iframe.style.cssText = "position:absolute;width:0;height:0;border:0;visibility:hidden;";
      iframe.src = schemeUrl;
      document.body.appendChild(iframe);
      setTimeout(function () {
        if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
      }, 500);
    })(url);

    // 앱 미설치 시: 스킴 이동 후 포커스/가시성 변화 없으면 일정 시간 뒤 토스트 표시
    if (notInstalledCheckTimer) clearTimeout(notInstalledCheckTimer);
    var appOpened = false;
    function onVisibilityChange() {
      if (document.visibilityState === "hidden") onBlurOrHidden();
    }
    function onBlurOrHidden() {
      appOpened = true;
      if (notInstalledCheckTimer) {
        clearTimeout(notInstalledCheckTimer);
        notInstalledCheckTimer = null;
      }
      window.removeEventListener("blur", onBlurOrHidden);
      document.removeEventListener("visibilitychange", onVisibilityChange);
    }
    window.addEventListener("blur", onBlurOrHidden);
    document.addEventListener("visibilitychange", onVisibilityChange);

    notInstalledCheckTimer = setTimeout(function () {
      notInstalledCheckTimer = null;
      window.removeEventListener("blur", onBlurOrHidden);
      document.removeEventListener("visibilitychange", onVisibilityChange);
      if (!appOpened) showDownloadToast(a);
    }, 2000);
  }

  // 페이지 로드 직후 prefetch 시작 (맵 페이지 진입 시 토큰이 미리 준비되도록)
  // 클릭까지 STALE_MS를 넘기면 onClick/prefetch가 알아서 재발급한다.
  getFreshToken().then(applyPrefetchToLink);

  // updateUserData 등으로 헤더가 다시 그려지면 새 링크에 캐시된 payload 재적용 (재발급 없음)
  if (document.body) observeStandaloneLink();
  else document.addEventListener("DOMContentLoaded", observeStandaloneLink);

  document.addEventListener("pointerdown", prefetchIfNeeded, true);
  document.addEventListener("mousedown", prefetchIfNeeded, true);
  document.addEventListener("click", onClick, true);
})();
