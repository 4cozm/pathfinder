# ![Pathfinder logo](favicon/favicon-32x32.png "Logo") *PATHFINDER*

#### Mapping tool for [*EVE ONLINE*](https://www.eveonline.com)

This Pathfinder Fork is an attempt to make a community supported fork in the absence of [Exodus4d](https://github.com/exodus4d) that will include bug fixes and be upgraded for any changes to the Eve Online universe.

**For installation please see our [Docker-compose solution](https://github.com/goryn-clade/pathfinder-containers) that includes a detailed guide on how to get up and running.**

If you wish to contribute please join the [Pathfinder Slack](https://join.slack.com/t/pathfinder-eve-online/shared_invite/enQtMzMyOTkyMjczMTA3LWI2NGE1OTY5ODBmNDZlMDY3MDIzYjk5ZTljM2JjZjIwNDRkNzMyMTEwMDUzOGQwM2E3ZjE1NGEwNThlMzYzY2Y).

- Project URL [https://www.pathfinder-w.space](https://www.pathfinder-w.space)
- Screenshots [imgur.com](http://imgur.com/a/k2aVa)
- Videos [youtube.com](https://www.youtube.com/channel/UC7HU7XEoMbqRwqxDTbMjSPg)
- Licence [MIT](http://opensource.org/licenses/MIT)

#### Development
- Developer [Slack](https://slack.com) chat:
  - https://pathfinder-eve-online.slack.com
  - Join channel [pathfinder-eve-online.slack.com](https://join.slack.com/t/pathfinder-eve-online/shared_invite/enQtMzMyOTkyMjczMTA3LWI2NGE1OTY5ODBmNDZlMDY3MDIzYjk5ZTljM2JjZjIwNDRkNzMyMTEwMDUzOGQwM2E3ZjE1NGEwNThlMzYzY2Y)
  - Can´t join? pathfinder@exodus4d.de

**Feel free to check the code for bugs and security issues.
Issues should be reported in the [Issue](https://github.com/exodus4d/pathfinder/issues) section.**

***

### Map UI
- **Wormhole memo toast:** When a wormhole system is first drawn, Pathfinder shows its description (memo) as a speech-bubble toast in an independent map toast layer (not clipped by `.pf-system` overflow). If description/statics are not immediately available during creation, Pathfinder retries briefly and shows the toast as soon as data arrives; if no memo exists, nothing is shown. The toast uses a high-contrast palette for readability over the map background.
- **Map WebSocket diagnostics:** The browser **page console** (F12 → Console) logs lines prefixed with `[PF][MapWS]` when the map SharedWorker’s WebSocket opens, closes, errors, schedules reconnect, or (throttled) receives `mapSubscriptions`. The SharedWorker script logs the same prefix with additional detail (Chrome: DevTools → **Application** → **Shared workers** → select the map worker → Console). Use these logs when reporting map sync issues; they intentionally omit tokens and only include the WS host, codes, and timing.
- **PHP → WebSocket TCP client:** The internal TCP socket used by PHP to talk to the `pathfinder-socket` server (`TcpSocket` / `$f3->webSocket()`) uses a default **2 second** connect/read timeout (`app/Lib/Config.php`). The Setup page TCP health check uses the same duration (`app/Controller/Setup.php`).
- **Map WS resubscribe after reconnect:** On every WebSocket `open` (including reconnect), the map page calls `getAccessData` again so PHP registers fresh `mapConnectionAccess` tokens on the socket server before sending `subscribe`. A forced map-data Ajax refresh runs after a successful token fetch so changes missed while disconnected are applied without a full page reload. If token fetch fails, sync falls back to Ajax (`js/app/mappage.js`). The socket server keeps map access tokens valid for **300 seconds** (`websocket` submodule `MapUpdate::$mapAccessExpireSeconds`).

### Project structure
<pre>
 ─╮
  ├─ app/              [0755] → PHP root
  │  ├─ Controller/           → controller classes for app/ajax endpoints (see routes.ini)
  │  ├─ Cron/                 → controller classes cronjob endpoints (see cron.ini)
  │  ├─ Data/                 → classes for data handling
  │  ├─ Db/                   → classes for DB handling
  │  ├─ Exception/            → custom exceptions
  │  ├─ Lib/                  → libs
  │  ├─ Model/                → ORM
  │  ├─ config.ini            → config - F3 core config: <a href="//fatfreeframework.com/3.7/quick-reference#SystemVariables" title="Fat-Free Framework - SystemVariables">SystemVariables</a>
  │  ├─ cron.ini              → config - cronjobs
  │  ├─ environment.ini       → config - system environment
  │  ├─ pathfinder.ini        → config - pathfinder
  │  ├─ plugin.ini            → config - custom plugins
  │  ├─ requirements.ini      → config - system requirements
  │  └─ routes.ini            → config - routes
  ├─ export/           [0755] → static data
  │  ├─ csv/                  → *.csv used by /setup page
  │  └─ sql/                  → DB dump for import (eve_universe.sql.zip)
  ├─ favicon/          [0755] → favicons
  ├─ history/          [0777] → log files (map history logs) [optional]
  ├─ js/               [0755] → JS source files (not used for production)
  │  ├─ app/                  → "PATHFINDER" core files
  │  ├─ lib/                  → 3rd party libs
  │  └─ app.js                → require.js config
  ├─ logs/             [0777] → log files
  │  └─ …
  ├─ public/           [0755] → static resources
  │  ├─ css/                  → CSS dist/build folder (minified)
  │  ├─ fonts/                → icon-/fonts
  │  ├─ img/                  → images
  │  ├─ js/                   → JS dist/build folder and source maps (minified, uglified)
  │  └─ templates/            → templates
  ├─ sass/                    → SCSS sources (not used for production)
  ├─ tmp/              [0777] → cache folder (PHP templates)
  │  └─ cache/         [0777] → cache folder (PHP cache)
  ├─ .htaccess         [0755] → reroute/caching rules ("Apache" only!)
  └─ index.php         [0755]

  ━━━━━━━━━━━━━━━━━━━━━━━━━━
  CI/CD config files:
  
  ├─ .jshintrc                → "JSHint" config (not used for production)
  ├─ composer.json            → "Composer" package definition
  ├─ gulpfile.js              → "Gulp" task config (not used for production)
  ├─ package.json             → "Node.js" dependency config (not used for production)
  └─ README.md                → This file :) (not used for production)
</pre>

***

### Contributing

[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/0)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/0)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/1)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/1)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/2)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/2)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/3)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/3)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/4)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/4)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/5)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/5)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/6)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/6)[![](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/images/7)](https://sourcerer.io/fame/exodus4d/exodus4d/pathfinder/links/7)


