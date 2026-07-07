<?php
/**
 * 平静之心 - 日历功能
 */

define('CAL_RANGE_COLORS', [
    ['bg' => 'rgba(59,130,246,0.18)', 'bar' => 'rgba(59,130,246,0.7)'],   // 蓝
    ['bg' => 'rgba(239,68,68,0.18)', 'bar' => 'rgba(239,68,68,0.7)'],     // 红
    ['bg' => 'rgba(16,185,129,0.18)', 'bar' => 'rgba(16,185,129,0.7)'],   // 绿
    ['bg' => 'rgba(139,92,246,0.18)', 'bar' => 'rgba(139,92,246,0.7)'],   // 紫
    ['bg' => 'rgba(245,158,11,0.18)', 'bar' => 'rgba(245,158,11,0.7)'],   // 橙
    ['bg' => 'rgba(236,72,153,0.18)', 'bar' => 'rgba(236,72,153,0.7)'],   // 粉
    ['bg' => 'rgba(20,184,166,0.18)', 'bar' => 'rgba(20,184,166,0.7)'],   // 青
    ['bg' => 'rgba(249,115,22,0.18)', 'bar' => 'rgba(249,115,22,0.7)'],   // 橘
]);

// Chinese public holidays (month-day => label), hardcoded for current year
function get_holidays(int $year): array {
    $map = [
        '01-01' => '元旦',
        '05-01' => '劳动节',
        '10-01' => '国庆节',
    ];
    // Lunar-based holidays (approximate solar dates)
    $lunar = [
        2026 => ['02-17' => '春节', '02-18' => '春节', '02-19' => '春节', '02-20' => '春节', '02-21' => '春节', '02-22' => '春节', '02-23' => '春节',
                  '04-03' => '清明节', '06-19' => '端午节', '09-25' => '中秋节'],
        2027 => ['02-06' => '春节', '02-07' => '春节', '02-08' => '春节', '02-09' => '春节', '02-10' => '春节', '02-11' => '春节', '02-12' => '春节',
                  '04-05' => '清明节', '06-09' => '端午节', '09-14' => '中秋节'],
    ];
    $holidays = $map;
    if (isset($lunar[$year])) {
        foreach ($lunar[$year] as $d => $n) $holidays[$d] = $n;
    }
    return $holidays;
}

function is_weekend(string $date_str): bool {
    $dow = (int)date('N', strtotime($date_str));
    return $dow >= 6; // 6=Sat, 7=Sun
}

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
        $is_range = $due['end'] !== null;

        $dates = expand_date_range($due['start'], $due['end']);

        foreach ($dates as $date) {
            $tasks[] = [
                'text' => $text,
                'done' => $done,
                'date' => $date,
                'date_end' => $due['end'],
                'is_range' => $is_range,
                'article_id' => $article['id'],
                'article_title' => $article['title'] ?: '无标题',
                'line_index' => $i,
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
    $ranges = [];

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
            if ($t_month !== $month_str || !isset($days[$t_day])) continue;

            if ($task['is_range']) {
                $key = $task['article_id'] . '|' . md5($task['text']) . '|' . ($task['done'] ? '1' : '0');
                if (!isset($ranges[$key])) {
                    $ranges[$key] = [
                        'text' => $task['text'],
                        'done' => $task['done'],
                        'date_start' => $task['date'],
                        'date_end' => $task['date_end'],
                        'article_title' => $task['article_title'],
                        'article_id' => $task['article_id'],
                        'line_index' => $task['line_index'],
                    ];
                } else {
                    if ($task['date'] < $ranges[$key]['date_start']) $ranges[$key]['date_start'] = $task['date'];
                    if ($task['date_end'] && $task['date_end'] > $ranges[$key]['date_end']) $ranges[$key]['date_end'] = $task['date_end'];
                }
            } else {
                $days[$t_day]['tasks'][] = $task;
            }
        }
    }

    // Clamp ranges to visible month
    $month_start = sprintf('%04d-%02d-01', $year, $month);
    $month_end = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);
    $ranges_out = [];
    foreach ($ranges as $r) {
        $rs = max($r['date_start'], $month_start);
        $re = min($r['date_end'] ?? $r['date_start'], $month_end);
        if ($rs <= $re) {
            $r['date_start'] = $rs;
            $r['date_end'] = $re;
            $ranges_out[] = $r;
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
        'ranges' => $ranges_out,
    ];
}

function render_calendar_html(array $cal, int $year, int $month): string {
    $week_headers = ['一', '二', '三', '四', '五', '六', '日'];
    $today_str = date('Y-m-d');
    $colors = CAL_RANGE_COLORS;
    $holidays = get_holidays($year);

    $prev = $cal['prev_month'];
    $next = $cal['next_month'];

    $ranges = $cal['ranges'] ?? [];
    $day_ranges = [];
    foreach ($ranges as $ri => $r) {
        $start_d = (int)substr($r['date_start'], 8, 2);
        $end_d = (int)substr($r['date_end'], 8, 2);
        for ($d = $start_d; $d <= $end_d; $d++) {
            $day_ranges[$d][] = $ri;
        }
    }

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

    for ($i = 0; $i < $cal['first_dow']; $i++) {
        $h .= '<div class="cal-cell cal-cell-empty"></div>';
    }

    for ($d = 1; $d <= $cal['days_in_month']; $d++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $today_class = ($date_str === $today_str) ? ' today' : '';
        $cell = $cal['days'][$d] ?? ['articles' => [], 'tasks' => []];

        // Weekend / holiday classes
        $extra_cls = '';
        $holiday_label = $holidays[substr($date_str, 5)] ?? null;
        if ($holiday_label) {
            $extra_cls .= ' cal-holiday';
        } elseif (is_weekend($date_str)) {
            $extra_cls .= ' cal-weekend';
        }

        $cell_ranges = $day_ranges[$d] ?? [];
        $has_ranges = !empty($cell_ranges);

        // Build cell class + inline style for range bottom-border strips
        $inline_style = '';
        if ($has_ranges) {
            $extra_cls .= ' cal-has-range';
            $titles = [];
            $n = count($cell_ranges);
            $strip_h = $n * 4; // total height of all range strips
            $stops = [];
            // Start with normal cell background above the strips
            $stops[] = 'var(--bg-card) 0%';
            $stops[] = 'var(--bg-card) calc(100% - ' . $strip_h . 'px)';
            // Each range gets a 4px strip, stacking upward from bottom
            // range 0 = bottom strip, range 1 = above it, etc.
            foreach ($cell_ranges as $ri) {
                $r = $ranges[$ri];
                $ci = $ri % count($colors);
                $color = $colors[$ci]['bar'];
                $top_from_bottom = ($n - $ri) * 4;
                $bot_from_bottom = ($n - $ri - 1) * 4;
                $stops[] = $color . ' calc(100% - ' . $top_from_bottom . 'px)';
                $stops[] = $color . ' calc(100% - ' . $bot_from_bottom . 'px)';
                $prefix = $r['done'] ? '✓ ' : '';
                $titles[] = $prefix . $r['text'];
            }
            $inline_style = ' style="background:linear-gradient(to bottom,' . implode(',', $stops) . ');"';
        }

        $title_attr = '';
        if ($has_ranges && !empty($titles)) {
            $title_attr = ' title="' . h(implode(' | ', $titles)) . '"';
        }

        $h .= '<div class="cal-cell' . $today_class . $extra_cls . '" data-date="' . $date_str . '"' . $inline_style . $title_attr . '>';
        $h .= '<span class="cal-day" onclick="calQuickAdd(event, \'' . $date_str . '\')" title="点击添加待办">' . $d . '</span>';
        if ($holiday_label) {
            $h .= '<span class="cal-holiday-label">' . h($holiday_label) . '</span>';
        }

        // Range labels — only on first day
        foreach ($cell_ranges as $ri) {
            $r = $ranges[$ri];
            $r_start_d = (int)substr($r['date_start'], 8, 2);
            if ($d === $r_start_d) {
                $ci = $ri % count($colors);
                $done_cls = $r['done'] ? ' done' : '';
                $h .= '<span class="cal-range-label' . $done_cls . '" style="background:' . $colors[$ci]['bar'] . ';">' . h($r['text']) . '</span>';
            }
        }

        // Article links
        $arts = $cell['articles'];
        if ($arts) {
            $h .= '<div class="cal-articles">';
            foreach ($arts as $art) {
                $h .= '<a href="/article/' . h($art['id']) . '" class="cal-art-link" title="' . h($art['title']) . '">' . h($art['title']) . '</a>';
            }
            $h .= '</div>';
        }

        // Single-day task items (clickable to toggle)
        $todos = $cell['tasks'];
        if ($todos) {
            $h .= '<div class="cal-tasks">';
            $max_show = 3;
            $shown = 0;
            foreach ($todos as $task) {
                if ($shown >= $max_show) break;
                $cls = $task['done'] ? 'cal-task-item done' : 'cal-task-item';
                $prefix = $task['done'] ? '&#10003; ' : '&#9711; ';
                $h .= '<span class="' . $cls . '" data-aid="' . h($task['article_id']) . '" data-li="' . $task['line_index'] . '" onclick="calToggleTask(this)" title="' . h($task['article_title']) . ' — 点击切换完成状态">' . $prefix . h($task['text']) . '</span>';
                $shown++;
            }
            $remaining = count($todos) - $max_show;
            if ($remaining > 0) {
                $h .= '<span class="cal-task-more">+' . $remaining . ' 项</span>';
            }
            $h .= '</div>';
        }

        $h .= '</div>';
    }

    $h .= '</div>';
    return $h;
}
