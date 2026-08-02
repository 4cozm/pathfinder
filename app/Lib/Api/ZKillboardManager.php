<?php

namespace Exodus4D\Pathfinder\Lib\Api;

use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Controller\LogController;

/**
 * derives a "prime time" indicator from zKillboard killmail history.
 *
 * v1 (system-only) turned out to be a bad signal for WH systems: residents rarely fight
 * IN their own home, they roam out and fight elsewhere -> the system's own killmail history
 * is thin and skewed toward whoever passed through, not toward the actual residents' TZ.
 *
 * v2 instead: (1) use the system's own 30d killmails only to figure out WHO lives there
 * (ranked by distinct active days, not raw kill count -> a single big fight can only ever
 * contribute ONE day, so it can't fake residency), then (2) pull each identified owner
 * corp's OWN all-time zKillboard activity stats (their fights anywhere, not just here) and
 * derive prime time from THAT. Falls back to the v1 system-only computation if no corp
 * can be confidently identified as a resident.
 *
 * zKillboard has no official ESI-client package -> plain cURL, no auth, fair-use UA required.
 */
class ZKillboardManager extends \Prefab {

    /**
     * kept low deliberately: this all runs inline in the standalone daemon's single-
     * threaded loop (shared with character tracking), which has a 120s healthcheck
     * budget. A worst-case sweep (3 months x MAX_PAGES_PER_MONTH pages + OWNER_MAX_CORPS
     * stats calls, all timing out) must stay comfortably under that even at
     * SWEEP_BATCH_SIZE=1, or a merely-slow (not down) zKillboard causes autoheal to
     * restart the daemon and interrupt tracking.
     */
    const REQUEST_TIMEOUT = 4;

    /**
     * zKillboard paginates /api/kills/ results at exactly 200 per page (verified against
     * a busy real system -> /page/2/, /page/3/ each independently returned 200 more).
     * Fetching only page 1 silently truncates any month with 200+ kills, which for a busy
     * WH corridor system is common and would bias the owner-corp day-count ranking toward
     * whatever's in that first page.
     */
    const KILLS_PER_PAGE = 200;

    /**
     * safety cap on pages walked per month -> a pathological hub system (Thera, Turnur,...)
     * could otherwise page indefinitely; a real WH's own killmail history never gets close.
     * See REQUEST_TIMEOUT comment for why this stays small.
     */
    const MAX_PAGES_PER_MONTH = 3;

    /**
     * fallback backoff when zKillboard returns 429 without a usable Retry-After header
     * @see request()
     */
    const DEFAULT_RATE_LIMIT_BACKOFF = 1800;

    /**
     * set by request() when the most recent call hit HTTP 429 -> epoch seconds until
     * which this manager instance should not be asked to fetch again. Checked by the
     * caller (PrimeTime.php) after a null fetchPrimeTime() result to decide between an
     * immediate retry (ordinary failure) and a cooldown (rate limited).
     * @var int|null
     */
    protected $rateLimitedUntil = null;

    /**
     * @return int|null epoch seconds, set only if the most recent fetchPrimeTime() call
     *         hit a 429 somewhere along the way
     */
    public function getRateLimitedUntil() : ?int {
        return $this->rateLimitedUntil;
    }

    /**
     * fetch + compute prime-time data for a solar system
     * @param int $systemId CCP solar_system_id (not the pathfinder row id)
     * @param \Base $f3
     * @return array|null null on any failure -> caller must leave zkbCheckedAt untouched so it
     *         retries, UNLESS getRateLimitedUntil() is now non-null (429 -> apply a cooldown instead)
     */
    public function fetchPrimeTime(int $systemId, \Base $f3) : ?array {
        $this->rateLimitedUntil = null;

        $killmails = $this->fetchSystemKillmails($systemId, $f3);
        if(is_null($killmails)){
            return null;
        }

        $owners = $this->identifyOwnerCorps($killmails, $f3);
        if(!empty($owners)){
            if(!is_null($corpStats = $this->computeCorpBasedStats($owners))){
                // system-level numbers are kept too -> useful context for the tooltip
                // (e.g. "owner identified from N kills across M days in this system")
                $corpStats['systemN'] = count($killmails);
                $corpStats['systemActiveDays'] = count($this->groupByDay($killmails));
                return $corpStats;
            }
            // every owner corp's stats call failed (not the same as "no owner found")
            // -> fall through to the system-only computation rather than losing the fetch
        }

        return $this->computeSystemStats($killmails, $f3);
    }

    /**
     * fetch this system's own killmails for the configured lookback window
     * @param int $systemId
     * @param \Base $f3
     * @return array|null null on request failure
     */
    protected function fetchSystemKillmails(int $systemId, \Base $f3) : ?array {
        $lookbackDays = (int)$f3->get('PATHFINDER.PRIMETIME.LOOKBACK_DAYS') ?: 30;

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $cutoff = (clone $now)->modify('-' . $lookbackDays . ' days');

        // zKillboard has no rolling "past N days > 7d" endpoint -> pull whole calendar
        // months and filter to the rolling window ourselves. Walk back one month at a
        // time until we've covered cutoff's month (a 30d window can span 3 calendar
        // months around a short month like February).
        $killmails = [];
        $monthCursor = new \DateTime($now->format('Y-m-01'), new \DateTimeZone('UTC'));
        $cutoffMonth = new \DateTime($cutoff->format('Y-m-01'), new \DateTimeZone('UTC'));
        do {
            $monthKills = $this->fetchMonth($systemId, (int)$monthCursor->format('Y'), (int)$monthCursor->format('n'));
            if(is_null($monthKills)){
                // a single failed month call invalidates the whole fetch -> caller retries later
                return null;
            }
            $killmails = array_merge($killmails, $monthKills);
            $monthCursor->modify('first day of last month');
        } while($monthCursor >= $cutoffMonth);

        return array_values(array_filter($killmails, function($km) use ($cutoff) {
            return !empty($km['killmail_time']) && new \DateTime($km['killmail_time']) >= $cutoff;
        }));
    }

    /**
     * rank corps that appear in this system's killmails (as victim OR attacker) by the
     * number of DISTINCT days they show up on -> a corp that lives here shows up across
     * many days; a corp that merely passed through for one (even large) fight shows up
     * on exactly one. The top corp must clear MIN_ACTIVE_DAYS on its own; any other corp
     * is admitted only if its own day-count is within OWNER_RATIO_THRESHOLD of the top
     * corp's -> lets genuinely co-habiting allied corps in while keeping out corps that
     * merely raid semi-regularly (e.g. 50% of the top corp's presence, not 90%+).
     * @param array $killmails
     * @param \Base $f3
     * @return array [] if no corp can be confidently identified, else list of
     *         ['id' => int, 'activeDays' => int, 'ratio' => float], ranked, capped at OWNER_MAX_CORPS
     */
    protected function identifyOwnerCorps(array $killmails, \Base $f3) : array {
        $minActiveDays = (int)$f3->get('PATHFINDER.PRIMETIME.MIN_ACTIVE_DAYS') ?: 5;
        $ratioThreshold = (float)$f3->get('PATHFINDER.PRIMETIME.OWNER_RATIO_THRESHOLD') ?: 0.9;
        $maxCorps = (int)$f3->get('PATHFINDER.PRIMETIME.OWNER_MAX_CORPS') ?: 3;

        $corpDays = [];
        foreach($killmails as $km){
            $day = (new \DateTime($km['killmail_time']))->format('Y-m-d');

            $corpIds = [];
            if(!empty($km['victim']['corporation_id'])){
                $corpIds[] = (int)$km['victim']['corporation_id'];
            }
            foreach(($km['attackers'] ?? []) as $attacker){
                if(!empty($attacker['corporation_id'])){
                    $corpIds[] = (int)$attacker['corporation_id'];
                }
            }

            foreach(array_unique($corpIds) as $corpId){
                $corpDays[$corpId][$day] = true;
            }
        }

        $ranked = [];
        foreach($corpDays as $corpId => $days){
            $ranked[] = ['id' => $corpId, 'activeDays' => count($days)];
        }
        usort($ranked, function($a, $b){ return $b['activeDays'] <=> $a['activeDays']; });

        if(empty($ranked) || $ranked[0]['activeDays'] < $minActiveDays){
            return [];
        }

        $topDays = $ranked[0]['activeDays'];
        $owners = [];
        foreach($ranked as $candidate){
            if(count($owners) >= $maxCorps){
                break;
            }
            $ratio = $topDays > 0 ? ($candidate['activeDays'] / $topDays) : 0;
            if($ratio >= $ratioThreshold){
                $candidate['ratio'] = round($ratio, 2);
                $owners[] = $candidate;
            }
        }

        return $owners;
    }

    /**
     * fetch each owner corp's all-time zKillboard activity matrix (7 weekdays x 24 hours,
     * server-side pre-aggregated by zKillboard over the corp's ENTIRE killboard history),
     * sum them elementwise, then take the median of the 7 weekday peak hours -> same
     * "resist a single outlier" idea as v1's daily-peak median, just over years of data
     * instead of 30 days.
     * @param array $owners from identifyOwnerCorps()
     * @return array|null null if every owner corp's stats call failed
     */
    protected function computeCorpBasedStats(array $owners) : ?array {
        $merged = [];
        for($d = 0; $d < 7; $d++){
            $merged[$d] = array_fill(0, 24, 0);
        }

        $ownersOut = [];
        foreach($owners as $owner){
            $stats = $this->fetchCorpStats($owner['id']);
            if(is_null($stats)){
                // one owner corp's stats call failing shouldn't sink the others
                continue;
            }

            // keys "0".."6" verified against a real response: paired with the response's
            // own "days" => ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"] label array,
            // i.e. 0 = Sunday
            for($d = 0; $d < 7; $d++){
                $row = $stats['activity'][(string)$d] ?? [];
                for($h = 0; $h < 24; $h++){
                    $merged[$d][$h] += (int)($row[$h] ?? 0);
                }
            }

            $ownersOut[] = [
                'id' => $owner['id'],
                'name' => $stats['info']['name'] ?? null,
                'ticker' => $stats['info']['ticker'] ?? null,
                'activeDays' => $owner['activeDays'],
                'ratio' => $owner['ratio'],
            ];
        }

        if(empty($ownersOut)){
            return null;
        }

        $weekdayPeaks = [];
        foreach($merged as $row){
            if(array_sum($row) > 0){
                $weekdayPeaks[] = array_search(max($row), $row);
            }
        }
        $medianHour = $this->circularMedian($weekdayPeaks);

        $histogram = array_fill(0, 24, 0);
        foreach($merged as $row){
            for($h = 0; $h < 24; $h++){
                $histogram[$h] += $row[$h];
            }
        }

        return [
            'source' => 'corp',
            'owners' => $ownersOut,
            'medianHour' => $medianHour,
            'histogram' => array_values($histogram),
            'confident' => true, // owner identification (day-count + ratio gate) IS the confidence check
        ];
    }

    /**
     * v1 fallback: derive prime time straight from this system's own killmails
     * (used when no corp can be confidently identified as a resident)
     * @param array $killmails filtered to the lookback window, each with a 'killmail_time' key
     * @param \Base $f3
     * @return array
     */
    protected function computeSystemStats(array $killmails, \Base $f3) : array {
        $minKills = (int)$f3->get('PATHFINDER.PRIMETIME.MIN_KILLS') ?: 15;
        $minActiveDays = (int)$f3->get('PATHFINDER.PRIMETIME.MIN_ACTIVE_DAYS') ?: 5;

        $histogram = array_fill(0, 24, 0);
        $dayBuckets = $this->groupByDay($killmails);

        foreach($killmails as $km){
            $hour = (int)(new \DateTime($km['killmail_time']))->format('G');
            $histogram[$hour]++;
        }

        // median of each active day's own peak hour -> resistant to one large fight
        // skewing the result (a single busy day can only contribute ONE data point)
        $dailyPeakHours = [];
        foreach($dayBuckets as $hours){
            $dailyPeakHours[] = array_search(max($hours), $hours);
        }
        $medianHour = $this->circularMedian($dailyPeakHours);

        $n = count($killmails);
        $activeDays = count($dayBuckets);

        return [
            'source' => 'system',
            'owners' => [],
            'n' => $n,
            'activeDays' => $activeDays,
            'medianHour' => $medianHour,
            'histogram' => array_values($histogram),
            'confident' => $n >= $minKills && $activeDays >= $minActiveDays,
        ];
    }

    /**
     * median of a set of hour-of-day values (0-23), circular-aware.
     * -> a plain numeric median breaks near midnight: peaks [0, 1, 22, 23] (a hard
     * US-TZ pattern straddling midnight) would linear-median to 12 -> exactly wrong.
     * Finds the "cut" (of 24 possible) that leaves the values least spread out once
     * rotated to start there, takes the ordinary median of that rotated set, then
     * rotates the answer back.
     * @param array $hours
     * @return int|null
     */
    protected function circularMedian(array $hours) : ?int {
        if(empty($hours)){
            return null;
        }

        $bestCut = 0;
        $bestSpread = 24;
        for($cut = 0; $cut < 24; $cut++){
            $rotated = array_map(function($h) use ($cut){ return ($h - $cut + 24) % 24; }, $hours);
            sort($rotated);
            $spread = end($rotated) - reset($rotated);
            if($spread < $bestSpread){
                $bestSpread = $spread;
                $bestCut = $cut;
            }
        }

        $rotated = array_map(function($h) use ($bestCut){ return ($h - $bestCut + 24) % 24; }, $hours);
        sort($rotated);
        $count = count($rotated);
        $mid = (int)floor($count / 2);
        $medianRotated = ($count % 2)
            ? $rotated[$mid]
            : (int)round(($rotated[$mid - 1] + $rotated[$mid]) / 2);

        return ($medianRotated + $bestCut) % 24;
    }

    /**
     * @param array $killmails
     * @return array 'Y-m-d' => [24 hourly counts]
     */
    protected function groupByDay(array $killmails) : array {
        $dayBuckets = [];
        foreach($killmails as $km){
            $time = new \DateTime($km['killmail_time']);
            $day = $time->format('Y-m-d');
            $hour = (int)$time->format('G');

            if(!isset($dayBuckets[$day])){
                $dayBuckets[$day] = array_fill(0, 24, 0);
            }
            $dayBuckets[$day][$hour]++;
        }
        return $dayBuckets;
    }

    /**
     * @param int $systemId
     * @param int $year
     * @param int $month
     * @return array|null null on request failure, [] on empty-but-valid response
     */
    protected function fetchMonth(int $systemId, int $year, int $month) : ?array {
        $baseUrl = rtrim((string)Config::getPathfinderData('api.z_killboard'), '/');
        $killmails = [];

        for($page = 1; $page <= self::MAX_PAGES_PER_MONTH; $page++){
            $url = $baseUrl . '/kills/systemID/' . $systemId . '/year/' . $year . '/month/' . $month . '/' .
                ($page > 1 ? 'page/' . $page . '/' : '');

            $data = $this->request($url, false);
            if(!is_array($data)){
                // a failed page invalidates the whole month -> caller retries later rather
                // than silently persisting a partial (and therefore skewed) result
                return null;
            }

            $killmails = array_merge($killmails, $data);
            if(count($data) < self::KILLS_PER_PAGE){
                // short page -> this was the last one
                break;
            }
        }

        return $killmails;
    }

    /**
     * @param int $corpId
     * @return array|null null on request failure or an unexpected/error response shape
     */
    protected function fetchCorpStats(int $corpId) : ?array {
        $baseUrl = rtrim((string)Config::getPathfinderData('api.z_killboard'), '/');
        $url = $baseUrl . '/stats/corporationID/' . $corpId . '/';

        // this endpoint 302-redirects to a canonical .../kills/ URL -> must follow
        $data = $this->request($url, true);
        if(!is_array($data) || isset($data['error']) || empty($data['activity'])){
            return null;
        }

        return $data;
    }

    /**
     * @param string $url
     * @param bool $followRedirects
     * @return array|null null on request failure, decoded JSON (array|assoc) otherwise
     */
    protected function request(string $url, bool $followRedirects) {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $this->getUserAgent()],
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            // only used to catch a possible Retry-After on a 429 -> zKillboard doesn't
            // proactively expose any rate-limit/token headers on ordinary responses
            CURLOPT_HEADERFUNCTION => function($ch, $headerLine) use (&$responseHeaders){
                if(strpos($headerLine, ':') !== false){
                    [$name, $value] = explode(':', $headerLine, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($headerLine);
            },
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if($body === false || $error){
            $this->getLogger()->write('zKillboard request failed (' . $url . '): ' . $error);
            return null;
        }
        if($httpCode === 429){
            $this->rateLimitedUntil = time() + $this->parseRetryAfter($responseHeaders['retry-after'] ?? null);
            $this->getLogger()->write('zKillboard rate limited (' . $url . '), backing off until ' . date('c', $this->rateLimitedUntil));
            return null;
        }
        if($httpCode !== 200){
            $this->getLogger()->write('zKillboard request failed (' . $url . '): HTTP ' . $httpCode);
            return null;
        }

        return json_decode($body, true);
    }

    /**
     * zKillboard doesn't document its 429 behavior at all (confirmed against the wiki/FAQ
     * -> only qualitative "don't hammer the server, space requests out" guidance, no
     * documented header names or numeric limits). Retry-After per RFC 7231 can be either
     * an integer seconds value or an HTTP-date -> handle both, fall back to a fixed
     * backoff if the header is absent or unparseable (it being Cloudflare-fronted means
     * Cloudflare's own edge throttling might add it even if the zKillboard app doesn't).
     * @param string|null $headerValue
     * @return int seconds
     */
    protected function parseRetryAfter(?string $headerValue) : int {
        if($headerValue === null || $headerValue === ''){
            return self::DEFAULT_RATE_LIMIT_BACKOFF;
        }
        if(ctype_digit(trim($headerValue))){
            return max(1, (int)$headerValue);
        }
        if(($timestamp = strtotime($headerValue)) !== false){
            return max(1, $timestamp - time());
        }
        return self::DEFAULT_RATE_LIMIT_BACKOFF;
    }

    /**
     * fair-use requires a descriptive UA identifying the app + contact
     * @return string
     */
    protected function getUserAgent() : string {
        return Config::getPathfinderData('name') . ' - ' . Config::getPathfinderData('version') .
            ' | ' . Config::getPathfinderData('contact');
    }

    /**
     * @return \Log
     */
    protected function getLogger() : \Log {
        return LogController::getLogger('ERROR');
    }
}
