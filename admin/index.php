<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数（白名单校验，非法值按未选择处理，保证任意翻页链接条件一致）
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
if (!in_array($status, ['0', '1', '2'], true)) $status = '';
if (!in_array($type, ['help', 'suggest', 'lost'], true)) $type = '';

// 列表、总数统计、CSV 导出共用同一筛选条件
list($where, $params) = buildMessageWhere($status, $type, $keyword);

// 导出当前筛选范围内的全部留言（与列表、总数范围一致）
if (($_GET['export'] ?? '') === 'csv') {
    exportMessagesCsv($db, $where, $params);
}

$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($total / $pageSize);

// 页码超出有效范围（如筛选条件改变、删除末页数据）时回到最后一页，
// 避免表格空白却保留旧总数
if ($page > $totalPages && $total > 0) {
    $redirect = array_merge($_GET, ['page' => $totalPages]);
    unset($redirect['export']);
    header('Location: index.php?' . http_build_query($redirect));
    exit;
}
if ($total === 0) $page = 1;

$offset = ($page - 1) * $pageSize;

// 统一构造翻页与导出链接，确保携带完全相同的筛选条件
$baseQuery = ['status' => $status, 'type' => $type, 'keyword' => $keyword];
$activeQuery = array_filter($baseQuery, fn($v) => $v !== '');
$pageUrl = function ($p) use ($baseQuery) {
    return 'index.php?' . http_build_query($baseQuery + ['page' => $p]);
};
$exportUrl = 'index.php?' . http_build_query($activeQuery + ['export' => 'csv']);
$hasActiveFilter = !empty($activeQuery);

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form" id="filterForm">
                <input type="hidden" name="page" value="1">
                <select name="status" onchange="document.getElementById('filterForm').submit()">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type" onchange="document.getElementById('filterForm').submit()">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词（%、_ 按普通字符）..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
                <a class="btn btn-secondary btn-sm" href="<?= $exportUrl ?>">导出CSV</a>
            </form>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <?php if ($total === 0 && $hasActiveFilter): ?>
                    <tr>
                        <td colspan="8" class="text-center">
                            没有符合当前筛选条件的留言<?= $keyword !== '' ? '（关键词："' . cleanInput($keyword) . '"）' : '' ?>，
                            请调整筛选条件或<a href="index.php">清除筛选</a>后重试
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr><td colspan="8" class="text-center">暂无留言数据</td></tr>
                    <?php endif; ?>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= $msg['id'] ?>)">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= $msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= $msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= $msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <div class="pagination">
            <?php if ($totalPages > 1): ?>
            <?php if ($page > 1): ?>
            <a href="<?= $pageUrl($page - 1) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="<?= $pageUrl($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= $pageUrl($page + 1) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条，第 <?= $page ?>/<?= max(1, $totalPages) ?> 页</span>
        </div>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<script>
// 操作完成后回到当前筛选条件的第 1 页，避免停留在已失效的旧页码
function reloadWithFilters() {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');
    const qs = params.toString();
    location.href = 'index.php' + (qs ? '?' + qs : '');
}

function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    if (!confirm('确定要' + action + '这条留言吗？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=audit&id=' + id + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('操作成功');
            reloadWithFilters();
        } else {
            alert(data.msg);
        }
    });
}

function deleteMessage(id) {
    if (!confirm('确定要删除这条留言吗？此操作不可恢复！')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('删除成功');
            reloadWithFilters();
        } else {
            alert(data.msg);
        }
    });
}

function viewMessage(id) {
    document.getElementById('viewModal').style.display = 'flex';
    document.getElementById('modalBody').innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            document.getElementById('modalBody').innerHTML = html;
        } else {
            document.getElementById('modalBody').innerHTML = data.msg;
        }
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
