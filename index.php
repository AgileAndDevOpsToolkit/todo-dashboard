<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');

const GITHUB_ACCOUNT = 'AgileAndDevOpsToolkit';
const GITHUB_API = 'https://api.github.com';
const USER_AGENT = 'AgileToolkit-Todo-Dashboard/1.0';

/*
 * Facultatif :
 *   export GITHUB_TOKEN="github_pat_..."
 *
 * Le token n'est pas nécessaire pour les dépôts publics, mais augmente
 * la limite de requêtes de l'API GitHub si la page est rechargée très souvent.
 */
$githubToken = getenv('GITHUB_TOKEN') ?: null;

/**
 * GET HTTP simple.
 *
 * @return array{status:int, body:string, error:?string}
 */
function httpGet(string $url, ?string $token = null): array
{
    $ch = curl_init($url);

    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch) ?: null;
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

/**
 * Récupère tous les dépôts publics appartenant au compte GitHub.
 */
function fetchRepositories(?string $token): array
{
    $repositories = [];

    for ($page = 1; ; $page++) {
        $url = GITHUB_API
            . '/users/' . rawurlencode(GITHUB_ACCOUNT)
            . '/repos?per_page=100'
            . '&page=' . $page
            . '&sort=full_name'
            . '&direction=asc';

        $response = httpGet($url, $token);

        if ($response['status'] !== 200) {
            throw new RuntimeException(
                'Impossible de récupérer les dépôts GitHub'
                . ' (HTTP ' . $response['status'] . ')'
                . ($response['error'] ? ' : ' . $response['error'] : '')
            );
        }

        try {
            $batch = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Réponse GitHub invalide : ' . $e->getMessage());
        }

        if (!is_array($batch)) {
            throw new RuntimeException('Réponse GitHub inattendue.');
        }

        foreach ($batch as $repo) {
            if (!isset($repo['name'], $repo['full_name'], $repo['default_branch'], $repo['html_url'])) {
                continue;
            }

            $repositories[] = [
                'name' => (string) $repo['name'],
                'full_name' => (string) $repo['full_name'],
                'default_branch' => (string) $repo['default_branch'],
                'html_url' => (string) $repo['html_url'],
                'archived' => (bool) ($repo['archived'] ?? false),
            ];
        }

        if (count($batch) < 100) {
            break;
        }
    }

    return $repositories;
}

/**
 * Encode un chemin en conservant les "/" entre segments.
 */
function encodePath(string $value): string
{
    return implode('/', array_map('rawurlencode', explode('/', $value)));
}

/**
 * Télécharge en parallèle le TODO.md racine de chaque dépôt.
 *
 * Retour :
 * - status 200 : TODO trouvé
 * - status 404 : aucun TODO.md racine
 * - autre : erreur à signaler
 */
function fetchTodosInParallel(array $repositories): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($repositories as $repo) {
        $url = 'https://raw.githubusercontent.com/'
            . rawurlencode(GITHUB_ACCOUNT) . '/'
            . rawurlencode($repo['name']) . '/'
            . encodePath($repo['default_branch']) . '/'
            . 'TODO.md';

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => USER_AGENT,
        ]);

        curl_multi_add_handle($multi, $ch);

        $handles[$repo['full_name']] = [
            'handle' => $ch,
            'url' => $url,
        ];
    }

    do {
        $result = curl_multi_exec($multi, $active);

        if ($active) {
            $selected = curl_multi_select($multi, 1.0);

            if ($selected === -1) {
                usleep(10000);
            }
        }
    } while ($active && $result === CURLM_OK);

    $results = [];

    foreach ($handles as $fullName => $entry) {
        $ch = $entry['handle'];

        $body = curl_multi_getcontent($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;

        $results[$fullName] = [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
            'raw_url' => $entry['url'],
        ];

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);

    return $results;
}

/**
 * Supprime une section Markdown "# DONE", "## DONE", etc.
 *
 * La suppression s'arrête au prochain titre de niveau égal ou supérieur.
 * Exemple : pour "## DONE", un nouveau "## ..." ou "# ..." reprend l'affichage.
 */
function removeDoneSections(string $markdown): string
{
    $lines = preg_split('/\R/u', $markdown) ?: [];
    $output = [];

    $skipping = false;
    $doneLevel = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (!$skipping && preg_match('/^(#{1,6})\s*DONE\b.*$/iu', $trimmed, $match)) {
            $skipping = true;
            $doneLevel = strlen($match[1]);
            continue;
        }

        if ($skipping) {
            if (
                preg_match('/^(#{1,6})\s+.+$/u', $trimmed, $match)
                && strlen($match[1]) <= $doneLevel
            ) {
                $skipping = false;
                $doneLevel = null;
                $output[] = $line;
            }

            continue;
        }

        $output[] = $line;
    }

    return trim(implode("\n", $output));
}

/**
 * Compte les éléments de liste Markdown restants.
 * C'est une estimation pratique du nombre de tâches ouvertes.
 */
function countTasks(string $markdown): int
{
    preg_match_all('/^\s*[-*+]\s+.+$/mu', $markdown, $matches);
    return count($matches[0]);
}

/**
 * Petit rendu Markdown autonome, suffisant pour des TODO :
 * titres, listes, paragraphes, gras, barré et code inline.
 */
function renderInlineMarkdown(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/~~(.+?)~~/u', '<del>$1</del>', $text) ?? $text;

    return $text;
}

function renderMarkdown(string $markdown): string
{
    if (trim($markdown) === '') {
        return '<p class="empty">Aucune tâche ouverte.</p>';
    }

    $lines = preg_split('/\R/u', $markdown) ?: [];
    $html = [];
    $inList = false;

    $closeList = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    };

    foreach ($lines as $index => $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $match)) {
            // Les titres (ex. "TODO", "Nouvelles features :") ne sont pas affichés, seuls les items comptent.
            $closeList();
            continue;
        }

        if (preg_match('/^\s*[-*+]\s+(.+)$/u', $line, $match)) {
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }

            $html[] = '<li>' . renderInlineMarkdown($match[1]) . '</li>';
            continue;
        }

        // Une ligne courte type "TODO" ou "Nouvelles features :" juste avant une liste est un titre implicite : on l'ignore.
        $bareLabel = trim($trimmed, "*_ \t");
        if (preg_match('/^[\p{L}0-9\s]+:?$/u', $bareLabel) && isNextLineListItem($lines, $index)) {
            continue;
        }

        $closeList();
        $html[] = '<p>' . renderInlineMarkdown($trimmed) . '</p>';
    }

    $closeList();

    return implode("\n", $html);
}

/**
 * Vérifie si la prochaine ligne non vide est un item de liste Markdown.
 */
function isNextLineListItem(array $lines, int $index): bool
{
    for ($i = $index + 1; $i < count($lines); $i++) {
        $next = trim($lines[$i]);

        if ($next === '') {
            continue;
        }

        return (bool) preg_match('/^\s*[-*+]\s+.+$/u', $lines[$i]);
    }

    return false;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errorMessage = null;
$repositories = [];
$withTodo = [];
$withoutTodo = [];
$errors = [];
$totalTasks = 0;

try {
    $repositories = fetchRepositories($githubToken);
    $todoResults = fetchTodosInParallel($repositories);

    foreach ($repositories as $repo) {
        $result = $todoResults[$repo['full_name']] ?? null;

        if ($result === null) {
            $errors[] = [
                'repo' => $repo,
                'message' => 'Résultat manquant',
            ];
            continue;
        }

        if ($result['status'] === 200) {
            $todo = removeDoneSections($result['body']);
            $taskCount = countTasks($todo);
            $totalTasks += $taskCount;

            $withTodo[] = [
                'repo' => $repo,
                'todo' => $todo,
                'task_count' => $taskCount,
            ];
        } elseif ($result['status'] === 404) {
            $withoutTodo[] = $repo;
        } else {
            $errors[] = [
                'repo' => $repo,
                'message' => 'HTTP ' . $result['status']
                    . ($result['error'] ? ' — ' . $result['error'] : ''),
            ];
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$generatedAt = new DateTimeImmutable();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TODO GitHub — <?= h(GITHUB_ACCOUNT) ?></title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f8fa;
            --card: #ffffff;
            --text: #1f2328;
            --muted: #59636e;
            --border: #d0d7de;
            --accent: #0969da;
            --soft: #ddf4ff;
            --ok: #1a7f37;
            --warn: #9a6700;
            --danger: #cf222e;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --card: #161b22;
                --text: #e6edf3;
                --muted: #8b949e;
                --border: #30363d;
                --accent: #58a6ff;
                --soft: #13233a;
                --ok: #3fb950;
                --warn: #d29922;
                --danger: #f85149;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        main {
            width: min(1100px, calc(100% - 32px));
            margin: 36px auto 64px;
        }

        h1 {
            margin-bottom: 6px;
            font-size: clamp(1.8rem, 4vw, 2.6rem);
        }

        h2 { margin-top: 42px; }
        h3, h4, h5, h6 { margin-bottom: 8px; }

        a {
            color: var(--accent);
            text-decoration: none;
        }

        a:hover { text-decoration: underline; }

        .subtitle {
            color: var(--muted);
            margin-top: 0;
        }

        .toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .button {
            display: inline-block;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 13px;
            background: var(--card);
            color: var(--text);
            font-weight: 600;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 24px 0 36px;
        }

        .metric {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
        }

        .metric strong {
            display: block;
            font-size: 1.8rem;
        }

        .metric span {
            color: var(--muted);
            font-size: .92rem;
        }

        .repo-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px 22px;
            margin: 14px 0;
        }

        .repo-header {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
            margin-bottom: 12px;
        }

        .repo-header h2 {
            margin: 0;
            font-size: 1.35rem;
        }

        .repo-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: .9rem;
        }

        .badge {
            background: var(--soft);
            border-radius: 999px;
            padding: 3px 9px;
            font-size: .82rem;
            font-weight: 700;
        }

        .badge.archived {
            color: var(--warn);
        }

        .todo-content ul {
            padding-left: 24px;
        }

        .todo-content li {
            margin: 7px 0;
        }

        code {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 1px 5px;
        }

        .empty {
            color: var(--ok);
            font-weight: 600;
        }

        .without-todo {
            columns: 2;
            column-gap: 32px;
            padding-left: 22px;
        }

        .without-todo li {
            break-inside: avoid;
            margin: 7px 0;
        }

        .alert {
            border: 1px solid var(--danger);
            border-radius: 10px;
            padding: 14px 16px;
            background: var(--card);
            margin: 20px 0;
        }

        footer {
            color: var(--muted);
            margin-top: 48px;
            border-top: 1px solid var(--border);
            padding-top: 18px;
            font-size: .9rem;
        }

        @media (max-width: 650px) {
            .without-todo { columns: 1; }
            main { width: min(100% - 20px, 1100px); }
            .repo-card { padding: 16px; }
        }
    </style>
</head>
<body>
<main>
    <h1>Vue globale des TODO</h1>
    <p class="subtitle">
        Compte GitHub :
        <a href="https://github.com/<?= h(GITHUB_ACCOUNT) ?>" target="_blank" rel="noopener">
            <?= h(GITHUB_ACCOUNT) ?>
        </a>
    </p>

    <div class="toolbar">
        <a class="button" href="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">↻ Actualiser</a>
        <span class="subtitle">
            Généré le <?= h($generatedAt->format('d/m/Y à H:i:s')) ?>
        </span>
    </div>

    <?php if ($errorMessage !== null): ?>
        <div class="alert">
            <strong>Erreur :</strong> <?= h($errorMessage) ?>
        </div>
    <?php else: ?>

        <section class="summary" aria-label="Synthèse">
            <div class="metric">
                <strong><?= count($repositories) ?></strong>
                <span>dépôts vérifiés</span>
            </div>
            <div class="metric">
                <strong><?= count($withTodo) ?></strong>
                <span>avec TODO.md</span>
            </div>
            <div class="metric">
                <strong><?= count($withoutTodo) ?></strong>
                <span>sans TODO.md</span>
            </div>
            <div class="metric">
                <strong><?= $totalTasks ?></strong>
                <span>tâches ouvertes estimées</span>
            </div>
        </section>

        <h2>TODO par dépôt</h2>

        <?php if ($withTodo === []): ?>
            <p>Aucun fichier <code>TODO.md</code> trouvé à la racine.</p>
        <?php endif; ?>

        <?php foreach ($withTodo as $item): ?>
            <?php
                $repo = $item['repo'];
                $todoUrl = $repo['html_url']
                    . '/blob/'
                    . encodePath($repo['default_branch'])
                    . '/TODO.md';
            ?>
            <article class="repo-card">
                <header class="repo-header">
                    <h2>
                        <a href="<?= h($repo['html_url']) ?>" target="_blank" rel="noopener">
                            <?= h($repo['name']) ?>
                        </a>
                    </h2>

                    <div class="repo-meta">
                        <span class="badge">
                            <?= (int) $item['task_count'] ?>
                            tâche<?= $item['task_count'] > 1 ? 's' : '' ?>
                        </span>

                        <?php if ($repo['archived']): ?>
                            <span class="badge archived">archivé</span>
                        <?php endif; ?>

                        <a href="<?= h($todoUrl) ?>" target="_blank" rel="noopener">
                            TODO.md ↗
                        </a>
                    </div>
                </header>

                <div class="todo-content">
                    <?= renderMarkdown($item['todo']) ?>
                </div>
            </article>
        <?php endforeach; ?>

        <h2>Dépôts sans TODO.md à la racine</h2>

        <?php if ($withoutTodo === []): ?>
            <p>Tous les dépôts possèdent un <code>TODO.md</code>.</p>
        <?php else: ?>
            <ul class="without-todo">
                <?php foreach ($withoutTodo as $repo): ?>
                    <li>
                        <a href="<?= h($repo['html_url']) ?>" target="_blank" rel="noopener">
                            <?= h($repo['name']) ?>
                        </a>
                        <?php if ($repo['archived']): ?>
                            <span class="badge archived">archivé</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <h2>Erreurs de lecture</h2>
            <div class="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li>
                            <?= h($error['repo']['name']) ?> :
                            <?= h($error['message']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <footer>
        Les sections Markdown intitulées <code># DONE</code>,
        <code>## DONE</code>, etc. sont automatiquement exclues du rapport.
        Les fichiers recherchés sont uniquement les <code>TODO.md</code>
        placés à la racine de la branche par défaut de chaque dépôt.
    </footer>
</main>
</body>
</html>
