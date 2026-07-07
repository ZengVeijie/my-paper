<?php
/**
 * 平静之心 - 日历功能
 */

function parse_due_from_line(string $line): ?array {
    if (!preg_match('/@due\((\d{4}-\d{2}-\d{2})(?:~(\d{4}-\d{2}-\d{2}))?\)/', $line, $m)) {
        return null;
    }
    return ['start' => $m[1], 'end' => $m[2] ?? null];
}

function expand_date_range(string $start, ?string $end): array {
    $dates = [];
    $cur = new DateTime($start);
    $stop = $end ? new DateTime($end) : new DateTime($start);
    while ($cur <= $stop) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $dates;
}

function parse_article_due_tasks(array $article): array {
    $content = $article['content'] ?? '';
    $lines = explode("\n", $content);
    $tasks = [];

    foreach ($lines as $i => $line) {
        if (!preg_match('/^\s*- \[([ x])\]/', $line, $cm)) continue;
        $due = parse_due_from_line($line);
        if (!$due) continue;

        $done = $cm[1] === 'x';
        $text = preg_replace('/^\s*- \[[ x]\]\s*/', '', $line);
        $text = preg_replace('/\s*\(完成于.*?\)$/', '', $text);
        $text = trim(preg_replace('/\s*@due\([^)]+\)/', '', $text));

        $dates = expand_date_range($due['start'], $due['end']);

        foreach ($dates as $date) {
            $tasks[] = [
                'text' => $text,
                'done' => $done,
                'date' => $date,
                'article_id' => $article['id'],
                'article_title' => $article['title'] ?: '无标题',
            ];
        }
    }

    return $tasks;
}

function build_calendar_data(int $year, int $month, array $articles): array {
    $ts = mktime(0, 0, 0, $month, 1, $year);
    $days_in_month = (int)date('t', $ts);
    $first_dow = (int)date('N', $ts) - 1; // 0=Mon, 6=Sun

    $prev_ts = strtotime('-1 month', $ts);
    $next_ts = strtotime('+1 month', $ts);

    $days = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $days[$d] = ['articles' => [], 'tasks' => []];
    }

    $month_str = sprintf('%04d-%02d', $year, $month);

    foreach ($articles as $art) {
        $created = substr($art['created_at'] ?? '', 0, 10);
        if (substr($created, 0, 7) === $month_str) {
            $day = (int)substr($created, 8, 2);
            $days[$day]['articles'][] = [
                'id' => $art['id'],
                'title' => $art['title'] ?: '无标题',
            ];
        }

        $tasks = parse_article_due_tasks($art);
        foreach ($tasks as $task) {
            $t_day = (int)substr($task['date'], 8, 2);
            $t_month = substr($task['date'], 0, 7);
            if ($t_month === $month_str && isset($days[$t_day])) {
                $days[$t_day]['tasks'][] = $task;
            }
        }
    }

    return [
        'year' => $year,
        'month' => $month,
        'days_in_month' => $days_in_month,
        'first_dow' => $first_dow,
        'prev_month' => ['year' => (int)date('Y', $prev_ts), 'month' => (int)date('m', $prev_ts)],
        'next_month' => ['year' => (int)date('Y', $next_ts), 'month' => (int)date('m', $next_ts)],
        'days' => $days,
    ];
}

function render_calendar_html(array $cal, int $year, int $month): string {
    $week_headers = ['一', '二', '三', '四', '五', '六', '日'];
    $today_str = date('Y-m-d');

    $prev = $cal['prev_month'];
    $next = $cal['next_month'];

    $h = '<div class="home-section-header">';
    $h .= '<h2>' . $year . '年' . $month . '月</h2>';
    $h .= '<div class="calendar-nav">';
    $h .= '<a href="?cal_year=' . $prev['year'] . '&cal_month=' . $prev['month'] . '" class="btn btn-sm">&laquo; ' . $prev['month'] . '月</a>';
    $h .= '<a href="?" class="btn-text">今天</a>';
    $h .= '<a href="?cal_year=' . $next['year'] . '&cal_month=' . $next['month'] . '" class="btn btn-sm">' . $next['month'] . '月 &raquo;</a>';
    $h .= '</div></div>';

    $h .= '<div class="calendar-grid">';

    foreach ($week_headers as $wh) {
        $h .= '<div class="cal-header">' . $wh . '</div>';
    }

    // leading empty cells
    for ($i = 0; $i < $cal['first_dow']; $i++) {
        $h .= '<div class="cal-cell cal-cell-empty"></div>';
    }

    for ($d = 1; $d <= $cal['days_in_month']; $d++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $today_class = ($date_str === $today_str) ? ' today' : '';
        $cell = $cal['days'][$d] ?? ['articles' => [], 'tasks' => []];

        $h .= '<div class="cal-cell' . $today_class . '">';
        $h .= '<span class="cal-day">' . $d . '</span>';

        // article dots
        $arts = $cell['articles'];
        if ($arts) {
            $h .= '<div class="cal-articles">';
            foreach ($arts as $art) {
                $h .= '<a href="/article/' . h($art['id']) . '" class="cal-art-dot" title="' . h($art['title']) . '"></a>';
            }
            $h .= '</div>';
        }

        // task badges
        $todos = $cell['tasks'];
        if ($todos) {
            $pending = count(array_filter($todos, fn($t) => !$t['done']));
            $done = count(array_filter($todos, fn($t) => $t['done']));
            $h .= '<div class="cal-tasks">';
            if ($pending > 0) {
                $titles = implode("\n", array_map(fn($t) => h($t['done'] ? '✓ ' . $t['text'] : '◻ ' . $t['text']), $todos));
                $h .= '<span class="cal-task-badge cal-task-pending" title="' . h($titles) . '">' . $pending . '</span>';
            }
            if ($done > 0) {
                $h .= '<span class="cal-task-badge cal-task-done" title="">' . $done . '</span>';
            }
            $h .= '</div>';
        }

        $h .= '</div>';
    }

    $h .= '</div>';
    return $h;
}
