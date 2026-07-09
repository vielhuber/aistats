<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use vielhuber\aihelper\aihelper;

final class Admin
{
    private string $logsDir = '/root/.cli-proxy-api/logs';
    private array $groupPalette = ['#2b3a67', '#234d3a', '#5c4a1e', '#5c2626', '#3b2b52', '#1e4a52', '#5c2b46', '#33384a', '#4a4420', '#2f4d2f', '#472b5c', '#264b4b'];

    private string $authUser = '';
    private string $authPass = '';

    private int $limit = 200;
    private string $dateFrom = '';
    private string $dateUntil = '';
    private string $search = '';
    private string $groupFilter = '';
    private string $modelFilter = '';
    private string $sourceFilter = '';
    private string $groupby = 'project';

    private array $requests = [];
    private int $sumIn = 0;
    private int $sumOut = 0;
    private int $errors = 0;
    private array $models = [];
    private array $byHour = [];
    private array $byStatus = [];
    private array $topGroups = [];
    private array $chartData = [];

    private string $apiBase = '';
    private array $apiKeys = [];
    private array $endpointModels = [];
    private array $modelsByFamily = [];
    private array $usageTools = [];

    private ?array $estimate = null;
    private int $recentRequests = 0;
    private bool $idle = false;
    private string $estimateSeverity = 'ok';
    private ?array $recommended = null;

    private string $renderedAt = '';
    private array $baseParams = [];

    public function __construct()
    {
        // persistent login cookie (survives browser restart), not a session cookie; default session
        // storage — no private session dir
        $lifetime = 180 * 24 * 3600;
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/admin',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTPS'] ?? '') === 'on'
        ]);
        session_start();
        $this->loadEnv();
    }

    public function run(): void
    {
        $this->handleLogout();
        $loginFailed = $this->attemptLogin();
        if (($_SESSION['auth'] ?? false) !== true) {
            $this->renderLogin($loginFailed);
            return;
        }
        $this->serveRawLog();
        $this->collect();
        $this->render();
    }

    private function loadEnv(): void
    {
        $env = [];
        foreach (explode("\n", (string) @file_get_contents(__DIR__ . '/../.env')) as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === '' || $envLine[0] === '#' || !str_contains($envLine, '=')) {
                continue;
            }
            [$envKey, $envValue] = explode('=', $envLine, 2);
            $env[trim($envKey)] = trim(trim($envValue), "\"'");
        }
        $this->authUser = $env['AUTH_USER'] ?? '';
        $this->authPass = $env['AUTH_PASS'] ?? '';
    }

    private function handleLogout(): void
    {
        if (!isset($_GET['logout'])) {
            return;
        }
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    private function attemptLogin(): bool
    {
        if (($_POST['action'] ?? '') !== 'login') {
            return false;
        }
        if (
            $this->authUser !== '' &&
            $this->authPass !== '' &&
            hash_equals($this->authUser, (string) ($_POST['user'] ?? '')) &&
            hash_equals($this->authPass, (string) ($_POST['pass'] ?? ''))
        ) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
            exit();
        }
        return true;
    }

    // detail view: stream back the raw log file, guarded against path traversal
    private function serveRawLog(): void
    {
        $detail = trim((string) ($_GET['detail'] ?? ''));
        if ($detail === '') {
            return;
        }
        // allow the proxy log dir plus the local claude/codex session dirs; realpath resolves
        // any traversal so only files inside these roots can be served
        $allowed = ['/root/.cli-proxy-api/logs', '/root/.claude/projects', '/root/.codex/sessions'];
        $target = realpath($detail);
        $ok = false;
        foreach ($allowed as $base) {
            $baseReal = realpath($base);
            if ($target !== false && $baseReal !== false && str_starts_with($target, $baseReal . '/')) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            http_response_code(404);
            exit('not found');
        }
        header('Content-Type: text/plain; charset=utf-8');
        // session transcripts can be huge — stream only the last 5 MB of large files
        $maxBytes = 5 * 1048576;
        $size = filesize($target) ?: 0;
        if ($size > $maxBytes) {
            $handle = fopen($target, 'rb');
            fseek($handle, $size - $maxBytes);
            echo "… (truncated to last 5 MB)\n";
            fpassthru($handle);
            fclose($handle);
        } else {
            readfile($target);
        }
        exit();
    }

    private function collect(): void
    {
        $this->limit = max(1, min(2000, (int) ($_GET['limit'] ?? 200)));
        $this->dateFrom = trim((string) ($_GET['from'] ?? ''));
        $this->dateUntil = trim((string) ($_GET['to'] ?? ''));
        $this->search = trim((string) ($_GET['q'] ?? ''));
        $this->groupFilter = trim((string) ($_GET['group'] ?? ''));
        $this->modelFilter = trim((string) ($_GET['model'] ?? ''));
        $this->sourceFilter = trim((string) ($_GET['source'] ?? ''));
        $this->groupby = in_array($_GET['groupby'] ?? 'project', ['project', 'off'], true)
            ? (string) ($_GET['groupby'] ?? 'project')
            : 'project';
        $grouped = $this->groupby !== 'off';

        $args = [
            'limit' => $this->limit,
            'date_from' => $this->dateFrom !== '' ? $this->dateFrom : null,
            'date_until' => $this->dateUntil !== '' ? $this->dateUntil : null,
            'include_body' => true
        ];
        // aihelper renamed the grouping flag from `group` to `group_by` (both bool) — pass whichever the installed version exposes
        $params = array_map(fn($param) => $param->getName(), (new \ReflectionMethod(aihelper::class, 'getCliApiRequests'))->getParameters());
        if (in_array('group_by', $params, true)) {
            $args['group_by'] = $grouped;
        } elseif (in_array('group', $params, true)) {
            $args['group'] = $grouped;
        }
        $this->requests = aihelper::getCliApiRequests(...$args);
        $this->applySearch();
        $this->attachGroups();
        $this->applyFilters();
        $this->aggregate();
        $this->buildChartData();

        $scheme =
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTPS'] ?? '') === 'on'
                ? 'https'
                : 'http';
        $this->apiBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'ai.rebuhleiv.xyz') . '/v1';

        $this->apiKeys = $this->readApiKeys();
        $this->fetchEndpointModels();
        $this->fetchUsageLimits();
        $this->buildEstimate();

        $this->renderedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        $this->baseParams = array_filter(
            ['limit' => $this->limit, 'from' => $this->dateFrom, 'to' => $this->dateUntil, 'q' => $this->search, 'groupby' => $this->groupby !== 'project' ? $this->groupby : ''],
            fn($value) => (string) $value !== ''
        );
    }

    private function applySearch(): void
    {
        if ($this->search === '') {
            return;
        }
        $needle = mb_strtolower($this->search);
        $this->requests = array_values(
            array_filter($this->requests, function ($request) use ($needle) {
                $haystack = mb_strtolower(
                    implode(' ', [
                        $request['model'] ?? '',
                        $request['ip'] ?? '',
                        $request['host'] ?? '',
                        $request['user_agent'] ?? '',
                        $request['url'] ?? '',
                        $request['api_requests'][0]['auth']['label'] ?? ''
                    ])
                );
                return str_contains($haystack, $needle);
            })
        );
    }

    private function attachGroups(): void
    {
        foreach ($this->requests as $index => $request) {
            if (($request['project'] ?? '') !== '') {
                // project: the working directory (cwd) for local calls, the Referer for proxy calls
                $groupKey = 'project|' . ($request['source'] ?? 'proxy') . '|' . $request['project'];
                $groupLabel = $request['project'];
            } else {
                // proxy calls without a Referer — fall back to the (readable) prompt
                [$groupKey, $groupLabel] = $this->promptSignature($request['request_body'] ?? null);
                // calls whose prompt lies before the log tail window can't be attributed; keep each
                // session distinct so they stay findable instead of collapsing into one giant "other"
                if ($groupKey === 'other') {
                    $project = $request['model'] ?? '?';
                    $groupLabel = 'unattributed · ' . $project;
                    $groupKey = 'unattr|' . $project . '|' . basename((string) ($request['file'] ?? (string) $index));
                }
            }
            $this->requests[$index]['group_id'] = substr(md5($groupKey), 0, 8);
            $this->requests[$index]['group_label'] = $groupLabel;
        }
    }

    private function applyFilters(): void
    {
        if ($this->groupFilter !== '') {
            $this->requests = array_values(
                array_filter($this->requests, fn($request) => ($request['group_id'] ?? '') === $this->groupFilter)
            );
        }
        if ($this->modelFilter !== '') {
            $this->requests = array_values(
                array_filter($this->requests, fn($request) => ($request['model'] ?? '') === $this->modelFilter)
            );
        }
        if ($this->sourceFilter !== '') {
            $this->requests = array_values(
                array_filter($this->requests, fn($request) => ($request['source'] ?? 'proxy') === $this->sourceFilter)
            );
        }
    }

    private function aggregate(): void
    {
        $this->byStatus = ['2xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $promptGroups = [];
        foreach ($this->requests as $request) {
            $in = (int) ($this->tokensIn($request['usage'] ?? []) ?? 0);
            $out = (int) ($this->tokensOut($request['usage'] ?? []) ?? 0);
            $this->sumIn += $in;
            $this->sumOut += $out;
            $status = $request['response']['status'] ?? null;
            if ($request['error'] === true || ($status !== null && $status >= 400)) {
                $this->errors++;
            }
            if (($request['model'] ?? null) !== null) {
                $this->models[$request['model']] = ($this->models[$request['model']] ?? 0) + 1;
            }
            if (($request['time'] ?? null) !== null) {
                $hour = substr(str_replace('T', ' ', (string) $request['time']), 0, 13) . ':00';
                $this->byHour[$hour] = ($this->byHour[$hour] ?? 0) + 1;
            }
            if ($status === null) {
                // local cli logs carry no http status but the turn completed → count as success
                $this->byStatus[($request['source'] ?? 'proxy') === 'proxy' ? 'other' : '2xx']++;
            } elseif ($status >= 500) {
                $this->byStatus['5xx']++;
            } elseif ($status >= 400) {
                $this->byStatus['4xx']++;
            } elseif ($status >= 200) {
                $this->byStatus['2xx']++;
            } else {
                $this->byStatus['other']++;
            }
            $groupId = $request['group_id'];
            if (!isset($promptGroups[$groupId])) {
                $promptGroups[$groupId] = ['label' => $request['group_label'], 'tokens' => 0];
            }
            $promptGroups[$groupId]['tokens'] += $in + $out;
        }
        arsort($this->models);
        ksort($this->byHour);
        // rank prompt groups by cost (total tokens in + out), not by number of calls
        uasort($promptGroups, fn($a, $b) => $b['tokens'] <=> $a['tokens']);
        $this->topGroups = array_slice($promptGroups, 0, 12, true);
    }

    private function buildChartData(): void
    {
        $topModels = array_slice($this->models, 0, 10, true);
        $this->chartData = [
            'byHour' => ['labels' => array_map(fn($label) => substr($label, 5), array_keys($this->byHour)), 'data' => array_values($this->byHour)],
            'byModel' => ['labels' => array_keys($topModels), 'data' => array_values($topModels)],
            'byStatus' => ['labels' => array_keys($this->byStatus), 'data' => array_values($this->byStatus)],
            'byGroup' => [
                'labels' => array_map(
                    fn($group) => mb_strlen($group['label']) > 28 ? mb_substr($group['label'], 0, 28) . '…' : $group['label'],
                    array_values($this->topGroups)
                ),
                'data' => array_map(fn($group) => $group['tokens'], array_values($this->topGroups))
            ]
        ];
    }

    // read the client api keys straight from the cliproxyapi config
    private function readApiKeys(): array
    {
        $apiKeys = [];
        $inKeyBlock = false;
        foreach (explode("\n", (string) @file_get_contents('/root/cliproxyapi/config.yaml')) as $configLine) {
            if (preg_match('/^api-keys:\s*$/', $configLine)) {
                $inKeyBlock = true;
                continue;
            }
            if (!$inKeyBlock) {
                continue;
            }
            if (preg_match('/^\s*-\s*["\']?([^"\'\s]+)["\']?\s*$/', $configLine, $keyMatch)) {
                $apiKeys[] = $keyMatch[1];
                continue;
            }
            break;
        }
        return $apiKeys;
    }

    // all models the endpoint currently serves, grouped by owner
    private function fetchEndpointModels(): void
    {
        if (($this->apiKeys[0] ?? '') === '') {
            return;
        }
        $context = stream_context_create([
            'http' => ['header' => 'Authorization: Bearer ' . $this->apiKeys[0], 'timeout' => 5, 'ignore_errors' => true]
        ]);
        $modelsData = json_decode(
            (string) @file_get_contents('http://127.0.0.1:8317/v1/models', false, $context),
            true
        );
        foreach ($modelsData['data'] ?? [] as $modelEntry) {
            if (isset($modelEntry['id'])) {
                $this->endpointModels[(string) $modelEntry['id']] = (string) ($modelEntry['owned_by'] ?? '');
            }
        }
        // two levels: top group = provider family (claude, codex, …); within it, the tier (haiku, sonnet, …)
        $familyMap = ['anthropic' => 'claude', 'openai' => 'codex', 'google' => 'gemini', 'xai' => 'grok', 'deepseek' => 'deepseek'];
        $typeOrder = ['haiku', 'sonnet', 'opus', 'flash', 'mini', 'nano', 'lite', 'pro', 'max', 'ultra', 'image', 'codex', 'fable', 'mythos', 'gpt', 'grok', 'gemini', 'deepseek', 'kimi', 'qwen'];
        $typeOf = function (string $id) use ($typeOrder): string {
            foreach ($typeOrder as $keyword) {
                if (str_contains($id, $keyword)) {
                    return $keyword;
                }
            }
            return 'other';
        };
        $byFamily = [];
        foreach ($this->endpointModels as $modelId => $owner) {
            $family = $familyMap[$owner] ?? ($owner !== '' ? $owner : 'other');
            $byFamily[$family][$typeOf($modelId)][] = $modelId;
        }
        $familyOrder = ['claude', 'codex', 'gemini', 'grok', 'deepseek'];
        $families = array_keys($byFamily);
        usort($families, function ($a, $b) use ($familyOrder) {
            $ia = array_search($a, $familyOrder, true);
            $ib = array_search($b, $familyOrder, true);
            return [$ia === false ? 99 : $ia, $a] <=> [$ib === false ? 99 : $ib, $b];
        });
        foreach ($families as $family) {
            $orderedTypes = [];
            foreach (array_merge($typeOrder, ['other']) as $keyword) {
                if (isset($byFamily[$family][$keyword])) {
                    sort($byFamily[$family][$keyword]);
                    $orderedTypes[$keyword] = $byFamily[$family][$keyword];
                }
            }
            $this->modelsByFamily[$family] = $orderedTypes;
        }
    }

    // account usage limits via aihelper (reads the oauth auth files, hits the provider usage endpoints)
    // a tool is listed when its auth file is present, so it never silently disappears on a transient error
    private function fetchUsageLimits(): void
    {
        foreach ([
            'Claude' => ['anthropic', 'claude-sonnet-4-5-20250929', ['/root/.claude/.credentials.json', '/root/.cli-proxy-api/claude*.json']],
            'Codex' => ['openai', 'gpt-5-codex', ['/root/.codex/auth.json', '/root/.cli-proxy-api/codex*.json']],
            'Antigravity' => ['google', 'antigravity-gemini', ['/root/.gemini/antigravity-cli/antigravity-oauth-token', '/root/.cli-proxy-api/antigravity*.json']]
        ] as $toolLabel => $toolConfig) {
            $hasAuth = false;
            foreach ($toolConfig[2] as $globPattern) {
                if (!empty(glob($globPattern))) {
                    $hasAuth = true;
                    break;
                }
            }
            if (!$hasAuth) {
                continue;
            }
            // the provider usage endpoints rate-limit (429) when polled every refresh — cache the last
            // good result per tool and only re-hit the endpoint every few minutes, serving the cached
            // value while fresh and on any failure (so a transient error never shows as "no data").
            // aihelper caches this internally too as of its next release; this is belt-and-suspenders
            // that also works with the currently installed version.
            $cacheFile =
                sys_get_temp_dir() .
                '/aistats-usage-' .
                strtolower($toolLabel) .
                '-' .
                (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) .
                '.json';
            $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
            $cachedLimits = is_array($cached) && !empty($cached['limits']) ? $cached['limits'] : null;
            $lastAttempt = is_array($cached) ? (int) ($cached['time'] ?? 0) : 0;
            // a good result stays fresh 5 min; while we have none yet, still back off 90s between
            // attempts so a rate-limited (429) endpoint isn't re-hit on every 30s refresh
            $ttl = $cachedLimits !== null ? 300 : 90;
            if ($cached !== null && time() - $lastAttempt < $ttl) {
                $this->usageTools[$toolLabel] = $cachedLimits;
                continue;
            }
            $limits =
                aihelper::create(provider: $toolConfig[0], model: $toolConfig[1], api_key: 'x')->getCliUsageLimits() ?: null;
            // record every attempt time (throttles failures too); keep the last good result on failure
            @file_put_contents($cacheFile, json_encode(['time' => time(), 'limits' => $limits ?? $cachedLimits]));
            $this->usageTools[$toolLabel] = $limits ?? $cachedLimits;
        }
    }

    // pace estimator: project each usage window to its reset using the ACTUAL token consumption from
    // the logs (not wall-clock). the endpoint's used% only calibrates tokens<->%; the recent token
    // rate drives the projection, so it goes flat when idle instead of assuming linear-over-time growth.
    // surfaces the window (5-hour or weekly, claude or codex) that projects worst.
    private function buildEstimate(): void
    {
        $windowSeconds = ['5-hour' => 5 * 3600, 'weekly' => 7 * 86400];
        $now = time();

        [$tokensInWindow, $recentTokens, $this->recentRequests] = $this->usageTokenPace($windowSeconds);
        $this->idle = $this->recentRequests === 0;

        foreach ($this->usageTools as $toolLabel => $limits) {
            foreach ($limits ?? [] as $usageLimit) {
                $type = (string) ($usageLimit['type'] ?? '');
                $used = (float) ($usageLimit['percent used'] ?? 0);
                $resetTs = strtotime((string) ($usageLimit['resets_at'] ?? ''));
                if (!isset($windowSeconds[$type]) || $resetTs === false || $used <= 0) {
                    continue;
                }
                $windowTokens = $tokensInWindow[$toolLabel][$type] ?? 0;
                $ratePerSecond = ($recentTokens[$toolLabel] ?? 0) / 3600.0;
                if ($windowTokens <= 0 || $ratePerSecond <= 0) {
                    continue;
                }
                // the tokens spent in the window so far correspond to `used` percent → tokens per 1%
                $tokensPerPercent = $windowTokens / $used;
                $hitTs = (int) ($now + ($tokensPerPercent * (100 - $used)) / $ratePerSecond);
                $projected = $used + ($ratePerSecond * max(0, $resetTs - $now)) / $tokensPerPercent;
                $candidate = [
                    'tool' => $toolLabel,
                    'type' => $type,
                    'used' => $used,
                    'projected' => $projected,
                    'resetTs' => $resetTs,
                    'hitTs' => $hitTs
                ];
                // surface the binding constraint: among windows projected to blow past 100%, the one
                // that hits SOONEST (you get blocked by it first); if none exceed, the highest projected
                $current = $this->estimate;
                $take = $current === null;
                if ($current !== null) {
                    $candidateCrit = $projected >= 100;
                    $currentCrit = $current['projected'] >= 100;
                    if ($candidateCrit !== $currentCrit) {
                        $take = $candidateCrit;
                    } elseif ($candidateCrit) {
                        $take = $hitTs < $current['hitTs'];
                    } else {
                        $take = $projected > $current['projected'];
                    }
                }
                if ($take) {
                    $this->estimate = $candidate;
                }
            }
        }

        if (!$this->idle && $this->estimate !== null) {
            $this->estimateSeverity = $this->estimate['projected'] >= 100 ? 'crit' : ($this->estimate['projected'] >= 80 ? 'warn' : 'ok');
        }

        // recommend the workhorse (claude vs codex) with the most headroom = lowest binding usage %
        foreach (['Claude' => 'claude', 'Codex' => 'codex'] as $toolLabel => $name) {
            $limits = $this->usageTools[$toolLabel] ?? null;
            if (empty($limits)) {
                continue;
            }
            $maxUsed = 0.0;
            foreach ($limits as $usageLimit) {
                $maxUsed = max($maxUsed, (float) ($usageLimit['percent used'] ?? 0));
            }
            $free = 100 - $maxUsed;
            if ($this->recommended === null || $free > $this->recommended['free']) {
                $this->recommended = ['name' => $name, 'free' => $free];
            }
        }
    }

    // tokens spent per tool (Claude/Codex) within each usage window + over the last hour (= current
    // pace), and the request count in the last hour (idle gate), from a dedicated log scan over the
    // widest window. cached briefly since this repeats the table's scan on every 30s auto-refresh.
    // returns [tokensInWindow[tool][window], recentTokens[tool], recentRequestCount].
    private function usageTokenPace(array $windowSeconds): array
    {
        $now = time();
        $cacheFile =
            sys_get_temp_dir() . '/aistats-pace-' . (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) . '.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && $now - (int) ($cached['time'] ?? 0) < 120) {
            return [$cached['inWindow'] ?? [], $cached['recent'] ?? [], (int) ($cached['recentReq'] ?? 0)];
        }
        $inWindow = [];
        $recent = [];
        $recentReq = 0;
        $rows = aihelper::getCliApiRequests(date_from: date('Y-m-d H:i:s', $now - max($windowSeconds)));
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['time'] ?? ''));
            if ($ts === false) {
                continue;
            }
            if ($ts >= $now - 3600) {
                $recentReq++;
            }
            // attribute the request to the tool whose usage limit it consumes: claude-code → Claude,
            // codex → Codex, proxy → by model (this setup routes gpt through the proxy to the codex quota)
            $source = (string) ($row['source'] ?? '');
            $model = strtolower((string) ($row['model'] ?? ''));
            $tool = null;
            if ($source === 'claude-code' || ($source === 'proxy' && str_contains($model, 'claude'))) {
                $tool = 'Claude';
            } elseif (
                $source === 'codex' ||
                ($source === 'proxy' && (str_contains($model, 'gpt') || str_contains($model, 'codex')))
            ) {
                $tool = 'Codex';
            }
            if ($tool === null) {
                continue;
            }
            // input+output only — cache-read tokens are huge but heavily discounted, so counting them
            // would distort the pace; the used%↔token calibration just needs a stable proxy
            $usage = $row['usage'] ?? [];
            $tokens =
                (int) ($usage['input_tokens'] ?? ($usage['prompt_tokens'] ?? 0)) +
                (int) ($usage['output_tokens'] ?? ($usage['completion_tokens'] ?? 0));
            foreach ($windowSeconds as $type => $seconds) {
                if ($ts >= $now - $seconds) {
                    $inWindow[$tool][$type] = ($inWindow[$tool][$type] ?? 0) + $tokens;
                }
            }
            if ($ts >= $now - 3600) {
                $recent[$tool] = ($recent[$tool] ?? 0) + $tokens;
            }
        }
        @file_put_contents(
            $cacheFile,
            json_encode(['time' => $now, 'inWindow' => $inWindow, 'recent' => $recent, 'recentReq' => $recentReq])
        );
        return [$inWindow, $recent, $recentReq];
    }

    private function tokensIn(?array $usage): ?int
    {
        return $usage['input_tokens'] ?? ($usage['prompt_tokens'] ?? null);
    }

    private function tokensOut(?array $usage): ?int
    {
        return $usage['output_tokens'] ?? ($usage['completion_tokens'] ?? null);
    }

    // collect the readable text of a request (system prompt preferred, else user messages)
    private function collectText(?array $body, bool $systemOnly): string
    {
        if (!is_array($body)) {
            return '';
        }
        $text = '';
        if (is_string($body['system'] ?? null)) {
            $text .= ' ' . $body['system'];
        }
        if (is_array($body['system'] ?? null)) {
            foreach ($body['system'] as $part) {
                if (is_string($part)) {
                    $text .= ' ' . $part;
                }
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $text .= ' ' . ($part['text'] ?? '');
                }
            }
        }
        foreach ($body['messages'] ?? [] as $message) {
            $role = $message['role'] ?? '';
            if ($systemOnly && $role !== 'system') {
                continue;
            }
            if (!$systemOnly && $role !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $text .= ' ' . $content;
            }
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_string($part)) {
                        $text .= ' ' . $part;
                    }
                    if (is_array($part) && ($part['type'] ?? '') === 'text') {
                        $text .= ' ' . ($part['text'] ?? '');
                    }
                }
            }
            if (!$systemOnly) {
                break;
            }
        }
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    // short, sanitized preview of the current user prompt for the table
    private function promptExcerpt(?array $body): string
    {
        $text = $this->collectText($body, false);
        if ($text === '' && is_string($body['prompt'] ?? null)) {
            $text = trim(preg_replace('/\s+/', ' ', $body['prompt']) ?? $body['prompt']);
        }
        return mb_strlen($text) > 140 ? mb_substr($text, 0, 140) . '…' : $text;
    }

    // no source/referrer exists, so derive a stable identity from the prompt itself:
    // the system prompt is fixed per integration; templated user prompts share a stable prefix
    private function promptSignature(?array $body): array
    {
        $basis = $this->collectText($body, true);
        if ($basis === '') {
            $basis = $this->collectText($body, false);
        }
        if ($basis === '') {
            return ['other', 'other'];
        }
        $key = mb_strtolower(mb_substr($basis, 0, 48));
        $label = mb_strlen($basis) > 70 ? mb_substr($basis, 0, 70) . '…' : $basis;
        return [$key, $label];
    }

    private function groupColor(string $id): string
    {
        return $this->groupPalette[crc32($id) % count($this->groupPalette)];
    }

    private function statusClass(?int $status): string
    {
        if ($status === null) {
            return 'muted';
        }
        if ($status >= 500) {
            return 'red';
        }
        if ($status >= 400) {
            return 'orange';
        }
        return 'green';
    }

    private function usageColor(int $percent): string
    {
        return $percent >= 90 ? '#f87171' : ($percent >= 70 ? '#fbbf24' : '#4ade80');
    }

    private function fmtReset(?string $iso): string
    {
        if (($iso ?? '') === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m. H:i');
        } catch (\Exception) {
            return '';
        }
    }

    private function fmtTs(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m. H:i');
    }

    // normalize any source timestamp (proxy uses a +02:00 offset, local logs use Z/UTC) to local time
    private function fmtLocal(?string $iso): string
    {
        if (($iso ?? '') === '') {
            return '–';
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return substr((string) $iso, 0, 19);
        }
    }

    private function fmt(int|float|null $number): string
    {
        return $number === null ? '–' : number_format((float) $number, 0, ',', '.');
    }

    private function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // link that keeps the active filters while setting/clearing one of them
    private function filterHref(array $overrides): string
    {
        $params = $this->baseParams;
        if ($this->groupFilter !== '') {
            $params['group'] = $this->groupFilter;
        }
        if ($this->modelFilter !== '') {
            $params['model'] = $this->modelFilter;
        }
        if ($this->sourceFilter !== '') {
            $params['source'] = $this->sourceFilter;
        }
        $params = array_filter(array_merge($params, $overrides), fn($value) => (string) $value !== '');
        return '?' . http_build_query($params);
    }

    private function renderLogin(bool $error): void
    {
        $configured = $this->authUser !== '' && $this->authPass !== '';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>aistats</title>
            <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='3' fill='%230f1115'/><rect x='3' y='8' width='2.5' height='5' fill='%234dd2ff'/><rect x='6.75' y='5' width='2.5' height='8' fill='%234ade80'/><rect x='10.5' y='3' width='2.5' height='10' fill='%23fbbf24'/></svg>">
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
        <div class="login">
            <form class="login-box" method="post">
                <?php if (!$configured): ?>
                    <div class="login-error">Set AUTH_USER and AUTH_PASS in .env.</div>
                <?php elseif ($error): ?>
                    <div class="login-error">Invalid credentials.</div>
                <?php endif; ?>
                <input type="hidden" name="action" value="login">
                <input type="text" name="user" placeholder="username" autocomplete="username" autofocus>
                <input type="password" name="pass" placeholder="password" autocomplete="current-password">
                <button type="submit">Sign in</button>
            </form>
        </div>
        </body>
        </html>
        <?php
    }

    private function render(): void
    {
        $h = fn($value) => $this->h($value);
        $fmt = fn($value) => $this->fmt($value);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>aistats</title>
            <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='3' fill='%230f1115'/><rect x='3' y='8' width='2.5' height='5' fill='%234dd2ff'/><rect x='6.75' y='5' width='2.5' height='8' fill='%234ade80'/><rect x='10.5' y='3' width='2.5' height='10' fill='%23fbbf24'/></svg>">
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
        <header>
            <div class="brand"><?php foreach (str_split('aistats') as $brandIndex => $brandChar): ?><span style="animation-delay: <?= $brandIndex * 0.14 ?>s"><?= $h($brandChar) ?></span><?php endforeach; ?></div>
            <div class="clock" title="last refresh"><?= $h($this->renderedAt) ?></div>
            <label class="toggle"><input type="checkbox" id="autorefresh"> auto-refresh</label>
            <a class="logout" href="?logout">logout</a>
        </header>
        <div class="wrap">
            <div class="top">
                <div class="panel">
                    <h2>API access</h2>
                    <div class="kv">
                        <div class="k">Endpoint (OpenAI-compatible)</div>
                        <div class="v"><?= $h($this->apiBase) ?></div>
                    </div>
                    <?php foreach ($this->apiKeys as $keyIndex => $apiKey): ?>
                        <div class="kv">
                            <div class="k">API key <?= $keyIndex + 1 ?></div>
                            <div class="v"><?= $h($apiKey) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($this->apiKeys)): ?>
                        <div class="kv"><div class="v muted">No API keys found in config.yaml.</div></div>
                    <?php endif; ?>
                </div>
                <div class="panel">
                    <h2>Usage limits</h2>
                    <?php if ($this->recommended !== null): ?>
                        <div class="recommend">✅ recommended model currently: <b><?= $h($this->recommended['name']) ?></b> · <?= $fmt(round($this->recommended['free'])) ?>% free</div>
                    <?php endif; ?>
                    <div class="estimate-mini <?= $this->estimateSeverity ?>">
                        <?php if ($this->idle): ?>
                            idle — nothing on pace
                        <?php elseif ($this->estimate === null): ?>
                            too early to project
                        <?php elseif ($this->estimateSeverity === 'crit'): ?>
                            ⛔ at this pace: <?= $h($this->estimate['tool']) ?> hits in <span class="countdown" data-reset="<?= (int) $this->estimate['hitTs'] ?>"></span>!
                        <?php elseif ($this->estimateSeverity === 'warn'): ?>
                            trending: <?= $h($this->estimate['tool']) ?> <?= $h($this->estimate['type']) ?> ≈<?= $fmt(round($this->estimate['projected'])) ?>% by reset
                        <?php else: ?>
                            ✅ on pace
                        <?php endif; ?>
                    </div>
                    <?php if (empty($this->usageTools)): ?>
                        <div class="muted">No usage data available.</div>
                    <?php endif; ?>
                    <?php foreach ($this->usageTools as $toolLabel => $limits): ?>
                        <div class="toolname"><?= $h($toolLabel) ?></div>
                        <?php if (empty($limits)): ?>
                            <div class="muted" style="font-size:12px">no data (no auth token or endpoint error)</div>
                        <?php endif; ?>
                        <?php foreach ($limits ?? [] as $usageLimit): ?>
                            <?php $percent = (int) $usageLimit['percent used']; ?>
                            <div class="usage-row">
                                <div class="head">
                                    <b><?= $h($usageLimit['type']) ?> · <?= $percent ?>%</b>
                                    <?php $resetTs = strtotime((string) ($usageLimit['resets_at'] ?? '')); ?>
                                    <?php if ($resetTs !== false): ?>
                                        <span class="reset" title="<?= $h($this->fmtReset($usageLimit['resets_at'])) ?>">resets in <span class="countdown" data-reset="<?= $resetTs ?>"></span></span>
                                    <?php endif; ?>
                                </div>
                                <div class="bar"><span style="width: <?= max(2, $percent) ?>%; background: <?= $this->usageColor($percent) ?>;"></span></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="charts">
                <div class="chart-box"><h2>Requests per hour</h2><div class="canvas-wrap"><canvas id="chartHour"></canvas></div></div>
                <div class="chart-box"><h2>Models used</h2><div class="canvas-wrap"><canvas id="chartModel"></canvas></div></div>
                <div class="chart-box"><h2>Status</h2><div class="canvas-wrap"><canvas id="chartStatus"></canvas></div></div>
                <div class="chart-box"><h2>Prompt groups (tokens)</h2><div class="canvas-wrap"><canvas id="chartGroup"></canvas></div></div>
            </div>

            <div class="models-row">
                <div class="panel models-panel">
                    <div class="models-inner">
                        <h2>Endpoint models (<?= count($this->endpointModels) ?>)</h2>
                        <?php if (empty($this->endpointModels)): ?>
                            <div class="muted">Could not load model list.</div>
                        <?php else: ?>
                            <div class="models-scroll">
                                <div class="model-families">
                                    <?php foreach ($this->modelsByFamily as $family => $types): ?>
                                        <div class="model-family">
                                            <div class="family-name"><?= $h($family) ?></div>
                                            <?php foreach ($types as $type => $modelIds): ?>
                                                <div class="model-group">
                                                    <div class="model-owner"><?= $h($type) ?> (<?= count($modelIds) ?>)</div>
                                                    <div class="models">
                                                        <?php foreach ($modelIds as $modelId): ?>
                                                            <a class="tag" href="<?= $h($this->filterHref(['model' => $modelId])) ?>"><?= $h($modelId) ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cards">
                    <div class="card"><div class="label">Requests</div><div class="value"><?= $fmt(count($this->requests)) ?></div></div>
                    <div class="card"><div class="label">Errors</div><div class="value"><?= $fmt($this->errors) ?></div></div>
                    <div class="card"><div class="label">Input tokens</div><div class="value"><?= $fmt($this->sumIn) ?></div></div>
                    <div class="card"><div class="label">Output tokens</div><div class="value"><?= $fmt($this->sumOut) ?></div></div>
                    <div class="card"><div class="label">Models used</div><div class="value"><?= $fmt(count($this->models)) ?></div></div>
                </div>
            </div>

            <?php if ($this->groupFilter !== '' || $this->modelFilter !== '' || $this->sourceFilter !== ''): ?>
                <div class="filter-active">
                    <?php if ($this->sourceFilter !== ''): ?>
                        source: <b><?= $h($this->sourceFilter === 'proxy' ? 'cliproxyapi' : $this->sourceFilter) ?></b> <a href="<?= $h($this->filterHref(['source' => ''])) ?>">✕</a>
                    <?php endif; ?>
                    <?php if ($this->modelFilter !== ''): ?>
                        · model: <b><?= $h($this->modelFilter) ?></b> <a href="<?= $h($this->filterHref(['model' => ''])) ?>">✕</a>
                    <?php endif; ?>
                    <?php if ($this->groupFilter !== ''): ?>
                        · group <a href="<?= $h($this->filterHref(['group' => ''])) ?>">✕</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="get">
                <div><label>Search</label><input type="text" name="q" value="<?= $h($this->search) ?>" placeholder="model, host, account…" style="width:360px"></div>
                <div><label>From</label><input type="text" name="from" value="<?= $h($this->dateFrom) ?>" placeholder="2026-07-01 00:00:00" style="width:180px"></div>
                <div><label>To</label><input type="text" name="to" value="<?= $h($this->dateUntil) ?>" placeholder="2026-07-31 23:59:59" style="width:180px"></div>
                <div><label>Limit</label><input type="number" name="limit" value="<?= $h($this->limit) ?>" style="width:90px"></div>
                <div><label>Source</label><select name="source"><option value="" <?= $this->sourceFilter === '' ? 'selected' : '' ?>>all sources</option><option value="proxy" <?= $this->sourceFilter === 'proxy' ? 'selected' : '' ?>>cliproxyapi</option><option value="claude-code" <?= $this->sourceFilter === 'claude-code' ? 'selected' : '' ?>>claude-code</option><option value="codex" <?= $this->sourceFilter === 'codex' ? 'selected' : '' ?>>codex</option></select></div>
                <div><label>View</label><select name="groupby"><option value="project" <?= $this->groupby === 'project' ? 'selected' : '' ?>>grouped by project</option><option value="off" <?= $this->groupby === 'off' ? 'selected' : '' ?>>all calls</option></select></div>
                <button type="submit">Filter</button>
            </form>

            <div class="tablewrap">
                <table>
                    <colgroup>
                        <col style="width: 150px"><col style="width: 130px"><col style="width: 200px"><col style="width: 180px"><col style="width: 340px"><col style="width: 70px"><col style="width: 70px"><col style="width: 48px">
                    </colgroup>
                    <thead>
                    <tr>
                        <th data-sort="text">Time <span class="arrow"></span></th>
                        <th data-sort="text">Source <span class="arrow"></span></th>
                        <th data-sort="text">Model <span class="arrow"></span></th>
                        <th data-sort="text">Group <span class="arrow"></span></th>
                        <th data-sort="text">Prompt <span class="arrow"></span></th>
                        <th data-sort="num">In <span class="arrow"></span></th>
                        <th data-sort="num">Out <span class="arrow"></span></th>
                        <th>raw</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($this->requests)): ?>
                        <tr><td colspan="8" class="empty">No requests found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($this->requests as $request): ?>
                        <?php
                        $excerpt = $this->promptExcerpt($request['request_body'] ?? null);
                        $source = $request['source'] ?? 'proxy';
                        $sourceLabel = $source === 'proxy' ? 'cliproxyapi' : $source;
                        $hasRaw = ($request['file'] ?? '') !== '';
                        $rawUrl = $hasRaw ? '?detail=' . rawurlencode($request['file']) : '';
                        $model = $request['model'] ?? null;
                        $calls = (int) ($request['calls'] ?? 1);
                        // fade rows by age: last hour opacity 1, each older hour -0.1, floor at 0.3
                        $rowTs = ($request['time'] ?? null) !== null ? strtotime((string) $request['time']) : false;
                        $hoursAgo = $rowTs !== false ? (int) floor((time() - $rowTs) / 3600) : 999;
                        $rowOpacity = min(1.0, max(0.3, round(1 - $hoursAgo * 0.1, 1)));
                        ?>
                        <tr style="opacity:<?= $rowOpacity ?>"<?= $hasRaw ? ' data-raw="' . $h($rawUrl) . '"' : '' ?>>
                            <td><?= $h($this->fmtLocal($request['time'] ?? null)) ?></td>
                            <td><a class="src src-<?= $h($source) ?>" href="<?= $h($this->filterHref(['source' => $source])) ?>"><?= $h($sourceLabel) ?></a></td>
                            <td class="model" title="<?= $h($model ?? '') ?>"><?php if ($model !== null): ?><a class="modellink" href="<?= $h($this->filterHref(['model' => $model])) ?>"><?= $h($model) ?></a><?php else: ?>–<?php endif; ?></td>
                            <td><a class="grouptag" style="background: <?= $this->groupColor($request['group_id']) ?>;" href="<?= $h($this->filterHref(['group' => $request['group_id']])) ?>" title="filter by group: <?= $h($request['group_label']) ?>"><?= $h(mb_strimwidth($request['group_label'], 0, 32, '…')) ?></a></td>
                            <td class="prompt" title="<?= $h($excerpt) ?>"><?php if ($calls > 1): ?><span class="calls"><?= $calls ?>×</span> <?php endif; ?><?= $excerpt === '' ? '<span class="muted">–</span>' : $h($excerpt) ?></td>
                            <td><?= $fmt($this->tokensIn($request['usage'] ?? [])) ?></td>
                            <td><?= $fmt($this->tokensOut($request['usage'] ?? [])) ?></td>
                            <td><?php if ($hasRaw): ?><a class="rawlink" href="<?= $h($rawUrl) ?>" target="_blank" title="raw log">🔗</a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="pagination"></div>
        </div>
        <script id="chartdata" type="application/json"><?= json_encode($this->chartData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
        <script src="node_modules/chart.js/dist/chart.umd.js"></script>
        <script src="script.js"></script>
        </body>
        </html>
        <?php
    }
}

(new Admin())->run();
