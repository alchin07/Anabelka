<?php

/** Read-only reports over the daily page-view aggregates. */
class VisitorStats
{
    private PDO $db;
    private DateTimeZone $timezone;
    private $clock;

    public function __construct(PDO $db, DateTimeZone $timezone, ?callable $clock = null)
    {
        $this->db = $db;
        $this->timezone = $timezone;
        $this->clock = $clock ?? static fn () => new DateTimeImmutable('now');
    }

    public function report(int $period = 30): array
    {
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;
        $now = ($this->clock)();
        if (!$now instanceof DateTimeInterface) {
            throw new UnexpectedValueException('The report clock must return a date and time.');
        }

        $today = DateTimeImmutable::createFromInterface($now)
            ->setTimezone($this->timezone)->setTime(0, 0);
        $end = $today->format('Y-m-d');
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        $chartStart = $today->modify('-29 days');
        $week = $today->modify('-6 days')->format('Y-m-d');
        $month = $chartStart->format('Y-m-d');
        $periodStart = $today->modify('-' . ($period - 1) . ' days')->format('Y-m-d');

        $dailyStatement = $this->db->prepare("
            SELECT stat_date, SUM(view_count) AS views
            FROM site_page_view_daily
            WHERE stat_date >= :date_from AND stat_date <= :date_to
            GROUP BY stat_date
            ORDER BY stat_date ASC
        ");
        $dailyStatement->execute([
            'date_from' => min($chartStart->format('Y-m-d'), $week, $month, $yesterday),
            'date_to' => $end
        ]);

        $daily = [];
        $summary = ['today' => 0, 'yesterday' => 0, 'week' => 0, 'month' => 0];
        foreach ($dailyStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string) $row['stat_date'];
            $views = max(0, (int) $row['views']);
            $daily[$date] = $views;
            if ($date === $end) {
                $summary['today'] += $views;
            }
            if ($date === $yesterday) {
                $summary['yesterday'] += $views;
            }
            if ($date >= $week) {
                $summary['week'] += $views;
            }
            if ($date >= $month) {
                $summary['month'] += $views;
            }
        }

        $chart = [];
        for ($day = $chartStart; $day <= $today; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $chart[] = ['date' => $date, 'views' => $daily[$date] ?? 0];
        }

        // Read the graph directly: the public models may create tables or cache
        // state, and an adult flag can be inherited from any ancestor depth.
        $categories = [];
        $categoryStatement = $this->db->query("
            SELECT c.id, c.parent_id, c.department_id, c.name, c.slug,
                c.is_adult, d.slug AS department_slug
            FROM categories c
            INNER JOIN departments d ON d.id = c.department_id
        ");
        foreach ($categoryStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $categories[(int) $row['id']] = $row;
        }
        $allowed = [];
        foreach ($categories as $id => $category) {
            $this->categoryAllowed($id, $categories, $allowed);
        }

        $categoryViews = $this->db->prepare("
            SELECT entity_id, SUM(view_count) AS view_count
            FROM site_page_view_daily
            WHERE page_type = :page_type
                AND stat_date >= :date_from AND stat_date <= :date_to
            GROUP BY entity_id
        ");
        $categoryViews->execute([
            'page_type' => 'category', 'date_from' => $periodStart, 'date_to' => $end
        ]);
        $topCategories = [];
        foreach ($categoryViews->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['entity_id'];
            if (empty($allowed[$id]) || (int) $row['view_count'] <= 0) {
                continue;
            }
            $category = $categories[$id];
            $topCategories[] = [
                'id' => $id, 'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
                'department_slug' => (string) $category['department_slug'],
                'view_count' => (int) $row['view_count']
            ];
        }

        $productViews = $this->db->prepare("
            SELECT p.id, p.category_id, p.name, p.slug, p.main_image,
                SUM(v.view_count) AS view_count
            FROM site_page_view_daily v
            INNER JOIN products p ON p.id = v.entity_id
            WHERE v.page_type = :page_type
                AND v.stat_date >= :date_from AND v.stat_date <= :date_to
            GROUP BY p.id, p.category_id, p.name, p.slug, p.main_image
        ");
        $productViews->execute([
            'page_type' => 'product', 'date_from' => $periodStart, 'date_to' => $end
        ]);
        $topProducts = [];
        foreach ($productViews->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (empty($allowed[(int) $row['category_id']]) || (int) $row['view_count'] <= 0) {
                continue;
            }
            $topProducts[] = [
                'id' => (int) $row['id'], 'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'main_image' => (string) ($row['main_image'] ?? ''),
                'view_count' => (int) $row['view_count']
            ];
        }

        $sortByViews = static function (array $left, array $right): int {
            return ($right['view_count'] <=> $left['view_count'])
                ?: ($left['id'] <=> $right['id']);
        };
        usort($topCategories, $sortByViews);
        usort($topProducts, $sortByViews);

        return [
            'period' => $period, 'timezone' => $this->timezone->getName(),
            'summary' => $summary, 'chart' => $chart,
            'top_categories' => array_slice($topCategories, 0, 10),
            'top_products' => array_slice($topProducts, 0, 10),
            'has_data' => array_sum($daily) > 0 || $topCategories !== [] || $topProducts !== []
        ];
    }

    private function categoryAllowed(int $id, array $categories, array &$allowed, array $path = []): bool
    {
        if (array_key_exists($id, $allowed)) {
            return $allowed[$id];
        }
        $category = $categories[$id] ?? null;
        if (!$category || isset($path[$id]) || !empty($category['is_adult'])) {
            return $allowed[$id] = false;
        }
        $parentId = (int) ($category['parent_id'] ?? 0);
        if ($parentId <= 0) {
            return $allowed[$id] = true;
        }
        $parent = $categories[$parentId] ?? null;
        if (!$parent || (int) $parent['department_id'] !== (int) $category['department_id']) {
            return $allowed[$id] = false;
        }
        $path[$id] = true;
        return $allowed[$id] = $this->categoryAllowed($parentId, $categories, $allowed, $path);
    }
}
