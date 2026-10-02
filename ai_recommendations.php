<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_reports', 'view_ai_recommendations']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';
require_once __DIR__ . '/lib/procurement.php';

$role = (string) ($_SESSION['role'] ?? '');
$canManageProcurement = role_can($role, 'manage_procurement');
$canManageRecommendations = role_can($role, 'manage_ai_recommendations');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!$canManageRecommendations) {
            throw new RuntimeException('You have read-only access to AI recommendations.');
        }
        $action = (string) ($_POST['action'] ?? 'status');
        if ($action === 'link_existing') {
            if (!$canManageProcurement) {
                throw new RuntimeException('Only Procurement can link a recommendation to a Purchase Request.');
            }
            $pdo->beginTransaction();
            link_ai_recommendation_to_purchase_request(
                $pdo,
                (int) ($_POST['recommendation_id'] ?? 0),
                (int) ($_POST['purchase_request_id'] ?? 0),
                (int) current_user()['id']
            );
            $pdo->commit();
            flash('success', 'Recommendation linked to the Purchase Request and marked as converted.');
        } else {
            $status = (string) ($_POST['status'] ?? 'open');
            if ($status === 'converted') {
                throw new RuntimeException('Use Convert to Purchase Request or link an existing request.');
            }
            update_ai_recommendation_status($pdo, (int) ($_POST['recommendation_id'] ?? 0), $status);
            flash('success', 'Recommendation status updated.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
    }
    redirect('ai_recommendations.php');
}

$urgencies = ['critical','high','medium','stable'];
$recommendationStatuses = ['open','acknowledged','converted','dismissed'];
$sources = ['gemini','rules'];
$q = filter_text($_GET, 'q');
$urgency = filter_choice($_GET, 'urgency', $urgencies);
$status = filter_choice($_GET, 'status', $recommendationStatuses);
$source = filter_choice($_GET, 'source', $sources);
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');

$where = [];
$params = [];
filter_where_like($where, $params, ['ar.title', 'ar.recommendation', 'ar.reason', 'ar.suggested_action', 'm.material_code', 'm.material_name', 'fr.run_no'], $q);
filter_where_equals($where, $params, 'ar.urgency', $urgency);
filter_where_equals($where, $params, 'ar.status', $status);
filter_where_equals($where, $params, 'ar.source', $source);
filter_where_between_dates($where, $params, 'ar.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT ar.*, fr.run_no, m.material_code, m.material_name, u.name AS unit_name,
            pr.id AS purchase_request_id, pr.pr_no AS purchase_request_no
     FROM ai_recommendations ar
     JOIN forecast_runs fr ON fr.id = ar.forecast_run_id
     JOIN materials m ON m.id = ar.material_id
     JOIN units u ON u.id = m.unit_id
     LEFT JOIN purchase_requests pr ON pr.ai_recommendation_id = ar.id
     {$whereSql}
     ORDER BY FIELD(ar.status, 'open', 'acknowledged', 'converted', 'dismissed'),
              FIELD(ar.urgency, 'critical', 'high', 'medium', 'stable'),
              ar.created_at DESC, ar.id DESC
     LIMIT 200"
);
$stmt->execute($params);
$recommendations = $stmt->fetchAll();
$linkableRequestsByMaterial = [];
if ($canManageProcurement) {
    $linkableRequests = $pdo->query(
        "SELECT DISTINCT pr.id, pr.pr_no, pri.material_id, pr.status, pr.created_at
         FROM purchase_requests pr
         JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
         WHERE pr.ai_recommendation_id IS NULL
           AND pr.status NOT IN ('rejected','completed','cancelled')
         ORDER BY pr.created_at DESC, pr.id DESC
         LIMIT 200"
    )->fetchAll();
    foreach ($linkableRequests as $linkableRequest) {
        $linkableRequestsByMaterial[(int) $linkableRequest['material_id']][] = $linkableRequest;
    }
}
$aiCounts = [
    'critical' => 0,
    'high' => 0,
    'medium' => 0,
    'stable' => 0,
    'open' => 0,
    'converted' => 0,
];
foreach ($recommendations as $rec) {
    $urgency = (string) $rec['urgency'];
    if (isset($aiCounts[$urgency])) {
        $aiCounts[$urgency]++;
    }
    if ((string) $rec['status'] === 'open') {
        $aiCounts['open']++;
    }
    if ((string) $rec['status'] === 'converted') {
        $aiCounts['converted']++;
    }
}

function ai_chart_attr(array $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES));
}

$activePage = 'ai_recommendations';
$title = 'AI Recommendations';
$eyebrow = 'FORECAST ACTIONS';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php require __DIR__ . '/partials/assets.php'; ?>
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/partials/header.php'; ?>
        <div class="page-content">
            <section class="forecast-terminal">
                <div>
                    <span class="eyebrow light">REPLENISHMENT INTELLIGENCE</span>
                    <h2>AI Recommendations</h2>
                    <p>Review forecast-driven actions, acknowledge material risks, and move replenishment decisions into the procurement workflow.</p>
                </div>
                <div class="hero-actions">
                    <a class="btn glass" href="forecasting.php">Run Forecast</a>
                    <a class="btn glass subtle" href="reports_forecast.php">Forecast Report</a>
                </div>
            </section>

            <form class="filter-card" method="get" data-filter-form>
                <label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Material, run, title, reason, action"></label>
                <label>Urgency<select name="urgency"><?php render_status_options($urgencies, $urgency, 'All urgency'); ?></select></label>
                <label>Status<select name="status"><?php render_status_options($recommendationStatuses, $status); ?></select></label>
                <label>Source<select name="source"><option value="">All sources</option><?php foreach ($sources as $option): ?><option value="<?= e($option) ?>" <?= $source === $option ? 'selected' : '' ?>><?= e($option === 'gemini' ? 'AI-assisted' : 'Rule-based analysis') ?></option><?php endforeach; ?></select></label>
                <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
                <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
                <div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="ai_recommendations.php" data-clear-filters>Clear</a></div>
                <?= filter_badge(count($recommendations)) ?>
            </form>

            <section class="forecast-grid">
                <article class="metric-card risk-critical"><span>Critical AI Actions</span><strong><?= number_format($aiCounts['critical']) ?></strong><small>Highest urgency</small></article>
                <article class="metric-card risk-high"><span>High AI Actions</span><strong><?= number_format($aiCounts['high']) ?></strong><small>Next review window</small></article>
                <article class="metric-card risk-medium"><span>Open Actions</span><strong><?= number_format($aiCounts['open']) ?></strong><small>Needs planner touch</small></article>
                <article class="metric-card risk-stable"><span>Converted</span><strong><?= number_format($aiCounts['converted']) ?></strong><small>Moved forward</small></article>
            </section>

            <section class="live-dashboard forecast-live-board">
                <article class="live-card diagram-card">
                    <div class="live-card-head"><div><span class="eyebrow">AI MIX</span><h2>Urgency Balance</h2></div></div>
                    <canvas class="chart-canvas" width="320" height="210" data-chart="ai-urgency" data-chart-data="<?= ai_chart_attr(['critical' => $aiCounts['critical'], 'high' => $aiCounts['high'], 'medium' => $aiCounts['medium'], 'stable' => $aiCounts['stable']]) ?>"></canvas>
                </article>
                <article class="live-card ai-pulse-card wide">
                    <div class="live-card-head">
                        <div><span class="eyebrow">ACTION DESK</span><h2>Recommendation Desk</h2></div>
                        <span class="signal-led">Active</span>
                    </div>
                    <p>Urgent items stay visible until the team acknowledges, converts to a traceable Purchase Request, or dismisses the recommendation.</p>
                    <div class="ai-mini-feed">
                        <span>Open <?= number_format($aiCounts['open']) ?></span>
                        <span>Converted <?= number_format($aiCounts['converted']) ?></span>
                        <span>Total <?= number_format(count($recommendations)) ?></span>
                    </div>
                </article>
            </section>

            <section class="recommendation-board">
                <?php if (!$recommendations): ?>
                    <article class="content-card"><p class="muted">No recommendations found. Run a forecast to populate the board.</p></article>
                <?php endif; ?>
                <?php foreach ($recommendations as $rec): ?>
                    <article class="ai-card risk-<?= e($rec['urgency']) ?>" id="recommendation-<?= (int) $rec['id'] ?>">
                        <span><?= e($rec['urgency']) ?> / <?= e($rec['source'] === 'gemini' ? 'AI-assisted' : 'Rule-based analysis') ?> / <?= e($rec['status']) ?></span>
                        <h3><?= e($rec['title']) ?></h3>
                        <p><?= e($rec['recommendation']) ?></p>
                        <small><?= e($rec['material_code']) ?> - <?= e($rec['material_name']) ?> / <?= e($rec['run_no']) ?></small>
                        <div class="ai-reason">
                            <b>Reason</b>
                            <p><?= e($rec['reason']) ?></p>
                            <b>Suggested Action</b>
                            <p><?= e($rec['suggested_action']) ?></p>
                        </div>
                        <?php if (!empty($rec['purchase_request_id'])): ?>
                            <p><b>Converted to:</b> <a class="table-action" href="purchase_request_view.php?id=<?= (int) $rec['purchase_request_id'] ?>"><?= e($rec['purchase_request_no']) ?></a></p>
                        <?php elseif ($canManageRecommendations): ?>
                            <form method="post" class="table-action-group" data-block-on-submit="Updating recommendation">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="recommendation_id" value="<?= (int) $rec['id'] ?>">
                                <button class="btn secondary compact" name="status" value="acknowledged" data-confirm="Mark this recommendation as acknowledged?" data-confirm-title="Acknowledge">Acknowledge</button>
                                <button class="btn danger compact" name="status" value="dismissed" data-confirm="Dismiss this recommendation?" data-confirm-title="Dismiss">Dismiss</button>
                                <?php if ($canManageProcurement): ?><a class="btn primary compact" href="purchase_request_create.php?recommendation_id=<?= (int) $rec['id'] ?>">Convert to Purchase Request</a><?php endif; ?>
                            </form>
                            <?php $linkableForMaterial = $linkableRequestsByMaterial[(int) $rec['material_id']] ?? []; ?>
                            <?php if ($canManageProcurement && $linkableForMaterial): ?>
                                <form method="post" class="table-action-group" data-block-on-submit="Linking Purchase Request">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="link_existing">
                                    <input type="hidden" name="recommendation_id" value="<?= (int) $rec['id'] ?>">
                                    <select name="purchase_request_id" required aria-label="Existing Purchase Request">
                                        <option value="">Link existing Purchase Request</option>
                                        <?php foreach ($linkableForMaterial as $linkableRequest): ?><option value="<?= (int) $linkableRequest['id'] ?>"><?= e($linkableRequest['pr_no']) ?> — <?= e(str_replace('_', ' ', $linkableRequest['status'])) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn secondary compact" type="submit" data-confirm="Link this recommendation to the selected Purchase Request?" data-confirm-title="Link Purchase Request">Link</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="muted">Read-only recommendation. Procurement manages acknowledgement, dismissal, and conversion.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
