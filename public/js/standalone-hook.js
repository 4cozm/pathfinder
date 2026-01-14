/* PF_STANDALONE_HOOK v2 */
(function () {
  var inflight = null; // Promise<string|null>

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
      return String(j.payload);
    } catch (e) {
      return null;
    }
  }

  function prefetchIfNeeded(e) {
    var a = e.target && e.target.closest ? e.target.closest("a.pf-open-standalone") : null;
    if (!a) return;
    if (!inflight) inflight = issueToken(); // 미리 시작
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

    window.location.href = url;
  }

  // 토큰은 클릭 전에 받아두기 (안정성)
  document.addEventListener("pointerdown", prefetchIfNeeded, true);
  document.addEventListener("mousedown", prefetchIfNeeded, true);

  // 최종 실행
  document.addEventListener("click", onClick, true);
})();
