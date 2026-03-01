/* PF_STANDALONE_HOOK v2 */
(function () {
  console.log("[다클라 헬퍼] standalone-hook.js 로드됨");
  var inflight = null; // Promise<string|null>
  /** 성공한 payload 캐시 — updateUserData 등으로 헤더가 다시 그려져도 새 링크에 재적용 */
  var cachedPayload = null;

  async function issueToken() {
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
    if (!inflight) {
      inflight = issueToken();
      inflight.then(applyPrefetchToLink);
    } else {
      inflight.then(function (payload) {
        if (payload != null) setLinkHref(a, payload);
      });
    }
  }

  async function onClick(e) {
    var a = e.target && e.target.closest ? e.target.closest("a.pf-open-standalone") : null;
    if (!a) return;

    // 다른 핸들러(기존 로직)랑 싸우지 않게: 우리가 최종이면 여기서 막자
    e.preventDefault();
    e.stopPropagation();

    var payload = null;
    try {
      payload = inflight ? await inflight : await issueToken();
    } finally {
      inflight = null; // TTL 짧으니 재사용 금지
    }

    var url = payload ? "pathfinder://standalone?payload=" + encodeURIComponent(payload) : "pathfinder://standalone";
    setLinkHref(a, payload);

    // [임시] 앱 스킴 호출 디버그 (F12 콘솔에서 확인)
    console.log("[다클라 헬퍼] 앱 스킴 호출", {
      hasPayload: !!payload,
      payloadLength: payload ? payload.length : 0,
      url: url,
    });

    window.location.href = url;
  }

  // 페이지 로드 직후 prefetch 시작 (맵 페이지 진입 시 토큰이 미리 준비되도록)
  inflight = issueToken();
  inflight.then(applyPrefetchToLink);

  // updateUserData 등으로 헤더가 다시 그려지면 새 링크에 캐시된 payload 재적용 (재발급 없음)
  if (document.body) observeStandaloneLink();
  else document.addEventListener("DOMContentLoaded", observeStandaloneLink);

  document.addEventListener("pointerdown", prefetchIfNeeded, true);
  document.addEventListener("mousedown", prefetchIfNeeded, true);
  document.addEventListener("click", onClick, true);
})();
