<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 转义 LIKE 搜索词中的特殊字符（\、%、_），
 * 使百分号、下划线按普通文本匹配，需配合 ESCAPE '\\' 使用
 */
function escapeLike($value) {
    return addcslashes($value, '\\%_');
}

/**
 * 构造后台留言列表的筛选条件
 * 列表查询、总数统计、CSV 导出共用此条件，保证翻页、导出范围与总数完全一致
 * 返回 [WHERE SQL 片段, 绑定参数数组]
 */
function buildMessageWhere($status, $type, $keyword) {
    $where = 'WHERE 1=1';
    $params = [];

    if ($status !== '') {
        $where .= ' AND status = ?';
        $params[] = (int) $status;
    }
    if ($type !== '') {
        $where .= ' AND type = ?';
        $params[] = $type;
    }
    if ($keyword !== '') {
        $like = '%' . escapeLike($keyword) . '%';
        $where .= " AND (title LIKE ? ESCAPE '\\\\' OR content LIKE ? ESCAPE '\\\\' OR nickname LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
    }

    return [$where, $params];
}

/**
 * 构造后台举报列表的筛选条件（同上，列表/计数/导出共用）
 */
function buildReportWhere($status, $reportType, $keyword) {
    $where = 'WHERE 1=1';
    $params = [];

    if ($status !== '') {
        $where .= ' AND r.status = ?';
        $params[] = (int) $status;
    }
    if ($reportType !== '') {
        $where .= ' AND r.report_type = ?';
        $params[] = $reportType;
    }
    if ($keyword !== '') {
        $like = '%' . escapeLike($keyword) . '%';
        $where .= " AND (m.title LIKE ? ESCAPE '\\\\' OR m.content LIKE ? ESCAPE '\\\\' OR r.description LIKE ? ESCAPE '\\\\')";
        array_push($params, $like, $like, $like);
    }

    return [$where, $params];
}

/**
 * CSV 单元格处理：防止 Excel 将 =、+、-、@ 开头的内容解析为公式
 */
function csvCell($value) {
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        $value = "'" . $value;
    }
    return $value;
}

/**
 * 发送 CSV 下载响应头
 */
function sendCsvHeaders($filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

/**
 * 导出留言列表为 CSV（与列表、总数使用完全相同的筛选条件）
 */
function exportMessagesCsv(PDO $db, $where, array $params) {
    $sql = "SELECT id, type, title, nickname, phone, status, views, created_at
            FROM messages $where ORDER BY created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    sendCsvHeaders('messages_' . date('YmdHis') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，保证 Excel 正确识别中文
    fputcsv($out, ['ID', '类型', '标题', '昵称', '联系电话', '状态', '浏览量', '创建时间']);
    while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array_map('csvCell', [
            $m['id'],
            getTypeLabel($m['type']),
            $m['title'],
            $m['nickname'],
            $m['phone'] ?? '',
            getStatusLabel((int) $m['status']),
            $m['views'],
            $m['created_at'],
        ]));
    }
    fclose($out);
    exit;
}

/**
 * 导出举报列表为 CSV（与列表、总数使用完全相同的筛选条件）
 */
function exportReportsCsv(PDO $db, $where, array $params) {
    $sql = "SELECT r.id, r.report_type, r.message_id, m.title AS message_title,
                   r.description, r.status, r.created_at, a.username AS admin_name, r.processed_at
            FROM reports r
            LEFT JOIN messages m ON r.message_id = m.id
            LEFT JOIN admins a ON r.processed_by = a.id
            $where ORDER BY r.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    sendCsvHeaders('reports_' . date('YmdHis') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', '举报类型', '被举报留言ID', '被举报留言标题', '举报说明', '状态', '举报时间', '处理人', '处理时间']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array_map('csvCell', [
            $r['id'],
            getReportTypeLabel($r['report_type']),
            $r['message_id'],
            $r['message_title'] ?? '',
            $r['description'] ?? '',
            getReportStatusLabel((int) $r['status']),
            $r['created_at'],
            $r['admin_name'] ?? '',
            $r['processed_at'] ?? '',
        ]));
    }
    fclose($out);
    exit;
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}
