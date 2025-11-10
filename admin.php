<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 引入统一配置 ===
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: " . htmlspecialchars($e->getMessage()));
}

$error = '';
$success = '';
$query_result = null;

// === 工具函数：通过用户名或 UID 查询账号 ===
function getAccount($pdo, $identifier) {
    $stmt = $pdo->prepare("
        SELECT a.*, COUNT(c.id) as card_count
        FROM accounts a
        LEFT JOIN cards c ON a.id = c.account_id
        WHERE a.username = ? OR a.id IN (
            SELECT account_id FROM cards WHERE uid = ?
        )
        GROUP BY a.id
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// === 处理 POST 请求 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['action'])) {
        $error = "无效操作";
    } else {
        $action = $_POST['action'];
        try {
            if ($action === 'create_user') {
                $username = trim($_POST['username'] ?? '');
                if (empty($username)) {
                    $error = "用户名不能为空";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO pending_registrations (username, type, expires_at) VALUES (?, 'new_user', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                    $stmt->execute([$username]);
                    $success = "✅ 用户「" . htmlspecialchars($username) . "」创建成功，请在10分钟内刷卡激活！";
                }
            } elseif ($action === 'bind_card') {
                $username = trim($_POST['bind_username'] ?? '');
                if (empty($username)) {
                    $error = "请输入用户名";
                } else {
                    $account = $pdo->prepare("SELECT id FROM accounts WHERE username = ?");
                    $account->execute([$username]);
                    $acc = $account->fetch();
                    if (!$acc) {
                        $error = "用户不存在";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO pending_registrations (username, type, expires_at) VALUES (?, 'bind_card', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                        $stmt->execute([$username]);
                        $success = "✅ 请在10分钟内刷新卡，绑定到用户「" . htmlspecialchars($username) . "」！";
                    }
                }
            } elseif ($action === 'generate_unbind') {
                $username = trim($_POST['unbind_username'] ?? '');
                if (empty($username)) {
                    $error = "请输入用户名";
                } else {
                    $account = $pdo->prepare("SELECT id FROM accounts WHERE username = ?");
                    $account->execute([$username]);
                    $acc = $account->fetch();
                    if (!$acc) {
                        $error = "用户不存在";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO pending_registrations (username, type, expires_at) VALUES (?, 'unbind_card', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                        $stmt->execute([$username]);
                        $success = "✅ 请在10分钟内刷卡，系统将自动解绑该卡！";
                    }
                }
            } elseif ($action === 'query') {
                $identifier = trim($_POST['identifier'] ?? '');
                if (empty($identifier)) {
                    $error = "请输入用户名或卡片UID";
                } else {
                    $account = getAccount($pdo, $identifier);
                    if (!$account) {
                        $error = "未找到该用户或卡片";
                    } else {
                        $query_result = $account;
                    }
                }
            } elseif ($action === 'add_coins') {
                $username = trim($_POST['add_username'] ?? '');
                $coins = intval($_POST['coins'] ?? 0);
                if (empty($username) || $coins <= 0) {
                    $error = "请输入有效用户名和充值金额";
                } else {
                    // 先获取用户当前余额和 ID
                    $stmt = $pdo->prepare("SELECT id, coins FROM accounts WHERE username = ?");
                    $stmt->execute([$username]);
                    $acc = $stmt->fetch();
                    if (!$acc) {
                        $error = "用户不存在";
                    } else {
                        $new_balance = $acc['coins'] + $coins;
                        // 更新余额
                        $update_stmt = $pdo->prepare("UPDATE accounts SET coins = ? WHERE username = ?");
                        $update_stmt->execute([$new_balance, $username]);
                        // 🔔 插入充值日志（关键修复！）
                        $log = $pdo->prepare("
                            INSERT INTO swipe_logs (account_id, username, action, coins_before, coins_after, uid)
                            VALUES (?, ?, 'admin_recharge', ?, ?, 'ADMIN')
                        ");
                        $log->execute([$acc['id'], $username, $acc['coins'], $new_balance]);
                        $success = "✅ 已为「" . htmlspecialchars($username) . "」充值 {$coins} 枚游戏币！";
                    }
                }
            }
            // ============ 新增：手动扣币（纠错） ============
            elseif ($action === 'deduct_coins') {
                $username = trim($_POST['deduct_username'] ?? '');
                $coins = intval($_POST['coins_to_deduct'] ?? 0);
                if (empty($username) || $coins <= 0) {
                    $error = "请输入有效用户名和扣除金额";
                } else {
                    $stmt = $pdo->prepare("SELECT id, coins FROM accounts WHERE username = ?");
                    $stmt->execute([$username]);
                    $acc = $stmt->fetch();
                    if (!$acc) {
                        $error = "用户不存在";
                    } elseif ($acc['coins'] < $coins) {
                        $error = "余额不足！当前余额：{$acc['coins']}，无法扣除 {$coins} 枚";
                    } else {
                        $new_balance = $acc['coins'] - $coins;
                        $stmt = $pdo->prepare("UPDATE accounts SET coins = ? WHERE username = ?");
                        $stmt->execute([$new_balance, $username]);
                        // 🔧 修复点1: 查询 account_id
                        $account_id = $acc['id'];
                        // 🔧 修复点2: 使用正确字段(uid) + 缩短action值(admin_adj)
                        $log = $pdo->prepare("
                            INSERT INTO swipe_logs (account_id, username, action, coins_before, coins_after, uid)
                            VALUES (?, ?, 'admin_adj', ?, ?, 'ADMIN')
                        ");
                        $log->execute([$account_id, $username, $acc['coins'], $new_balance]);
                        $success = "✅ 已为「" . htmlspecialchars($username) . "」扣除 {$coins} 枚游戏币（纠错操作）";
                    }
                }
            }
            // ============ 删除用户 ============
            elseif ($action === 'delete_user') {
                $username = trim($_POST['delete_username'] ?? '');
                if (empty($username)) {
                    $error = "请输入要删除的用户名";
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE username = ?");
                    $stmt->execute([$username]);
                    $acc = $stmt->fetch();
                    if (!$acc) {
                        $error = "用户不存在";
                    } else {
                        $pdo->prepare("DELETE FROM accounts WHERE id = ?")->execute([$acc['id']]);
                        $success = "🗑️ 用户「" . htmlspecialchars($username) . "」及其所有卡片已删除！";
                    }
                }
            } elseif ($action === 'manual_unbind') {
                $card_uid = trim($_POST['manual_unbind_uid'] ?? '');
                if (empty($card_uid)) {
                    $error = "请输入要解绑的卡片 UID";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM cards WHERE uid = ?");
                    $stmt->execute([$card_uid]);
                    if ($stmt->rowCount() > 0) {
                        $success = "🔓 卡片「" . htmlspecialchars($card_uid) . "」已手动解绑！";
                    } else {
                        $error = "未找到该卡片，或卡片未绑定";
                    }
                }
            } elseif ($action === 'cancel_pending') {
                $pending_id = (int)($_POST['pending_id'] ?? 0);
                if ($pending_id <= 0) {
                    $error = "无效的请求 ID";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM pending_registrations WHERE id = ?");
                    $stmt->execute([$pending_id]);
                    if ($stmt->rowCount() > 0) {
                        $success = "✅ 已取消该待处理操作。";
                    } else {
                        $error = "该请求已过期或不存在。";
                    }
                }
            }
        } catch (Exception $e) {
            $error = "操作失败：" . htmlspecialchars($e->getMessage());
        }
    }
}

// === 获取日志 ===
$log_stmt = $pdo->prepare("SELECT * FROM swipe_logs ORDER BY created_at DESC LIMIT 20");
$log_stmt->execute();
$logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

// === 获取所有用户及卡片 ===
$user_stmt = $pdo->prepare("
    SELECT a.id AS account_id, a.username, a.coins, a.updated_at,
           c.id AS card_id, c.uid, c.nickname
    FROM accounts a
    LEFT JOIN cards c ON a.id = c.account_id
    ORDER BY a.id DESC, c.id ASC
");
$user_stmt->execute();
$raw_users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
$users = [];
foreach ($raw_users as $row) {
    $acc_id = $row['account_id'];
    if (!isset($users[$acc_id])) {
        $users[$acc_id] = [
            'username' => $row['username'],
            'coins' => $row['coins'],
            'updated_at' => $row['updated_at'],
            'cards' => []
        ];
    }
    if ($row['card_id']) {
        $users[$acc_id]['cards'][] = [
            'uid' => $row['uid'],
            'nickname' => $row['nickname']
        ];
    }
}

// === 获取未过期的 pending 请求 ===
$pending_stmt = $pdo->prepare("
    SELECT id, username, type, expires_at
    FROM pending_registrations
    WHERE expires_at > NOW()
    ORDER BY created_at DESC
");
$pending_stmt->execute();
$pending_ops = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
 <meta charset="UTF-8">
 <title>游戏币管理系统</title>
 <style>
 body { font-family: "Microsoft YaHei", sans-serif; margin: 20px; background: #f5f5f5; }
 .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
 .tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
 .tab-btn { padding: 8px 16px; cursor: pointer; background: #e0e0e0; border: none; border-radius: 4px; }
 .tab-btn.active { background: #4CAF50; color: white; }
 .tab-content { display: none; }
 .tab-content.active { display: block; }
 .form-group { margin: 15px 0; }
 label { display: block; margin-bottom: 5px; font-weight: bold; }
 input[type="text"], input[type="number"] { width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; }
 button { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
 .btn-success { background: #4CAF50; color: white; }
 .btn-danger { background: #f44336; color: white; }
 .btn-warning { background: #ff9800; color: white; }
 button:hover { opacity: 0.9; }
 .alert { padding: 10px; margin: 15px 0; border-radius: 4px; }
 .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
 .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
 table { width: 100%; border-collapse: collapse; margin-top: 15px; }
 th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
 th { background: #f0f0f0; }
 .log-row { font-size: 0.9em; color: #555; }
 .card-item { margin-left: 20px; padding: 6px; background: #f9f9f9; border-radius: 4px; margin-top: 5px; font-size: 0.95em; }
 .pending-item { padding: 10px; border: 1px solid #eee; margin-bottom: 8px; border-radius: 6px; }
 .pending-type { font-weight: bold; color: #e91e63; }
 </style>
</head>
<body>
<div class="container">
 <h1>🎮 游戏币管理系统 <button class="tab-btn" onclick="window.location.href=window.location.pathname" style="font-size:14px; padding:4px 10px; margin-left:12px;">🔄 刷新</button> </h1>
 <?php if ($success): ?>
 <div class="alert alert-success"><?= $success ?></div>
 <?php endif; ?>
 <?php if ($error): ?>
 <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
 <?php endif; ?>
 <!-- Tabs -->
 <div class="tabs">
 <button class="tab-btn active" onclick="showTab('manage')">👥 用户管理</button>
 <button class="tab-btn" onclick="showTab('create')">➕ 新建用户</button>
 <button class="tab-btn" onclick="showTab('bind')">🔗 绑定卡片</button>
 <button class="tab-btn" onclick="showTab('unbind')">🔓 解绑卡片</button>
 <button class="tab-btn" onclick="showTab('recharge')">💰 充值</button>
 <button class="tab-btn" onclick="showTab('adjust')">🔧 余额调整</button>
 <button class="tab-btn" onclick="showTab('delete')">🗑️ 删除用户</button>
 <button class="tab-btn" onclick="showTab('query')">🔍 查询</button>
 <button class="tab-btn" onclick="showTab('pending')">⏳ 待处理请求</button>
 <button class="tab-btn" onclick="showTab('logs')">📜 日志</button>
 </div>
 <!-- 用户管理 -->
 <div id="tab-manage" class="tab-content active">
 <h3>现有用户 (<?= count($users) ?> 人)</h3>
 <?php if ($users): ?>
 <?php foreach ($users as $u): ?>
 <div style="border:1px solid #eee; padding:12px; margin-bottom:10px; border-radius:6px;">
 <strong><?= htmlspecialchars($u['username']) ?></strong> | 余额: <?= $u['coins'] ?> | 最后操作: <?= $u['updated_at'] ?? '无' ?>
 <?php if (!empty($u['cards'])): ?>
 <div style="margin-top:8px;">
 <strong>卡片:</strong>
 <?php foreach ($u['cards'] as $card): ?>
 <div class="card-item">UID: <code><?= htmlspecialchars($card['uid']) ?></code> (<?= htmlspecialchars($card['nickname']) ?>)</div>
 <?php endforeach; ?>
 </div>
 <?php else: ?>
 <div style="color:#888; margin-top:5px;">⚠️ 无绑定卡片</div>
 <?php endif; ?>
 </div>
 <?php endforeach; ?>
 <?php else: ?>
 <p>暂无用户</p>
 <?php endif; ?>
 </div>
 <!-- 新建用户 -->
 <div id="tab-create" class="tab-content">
 <form method="POST">
 <input type="hidden" name="action" value="create_user">
 <div class="form-group">
 <label>新用户名</label>
 <input type="text" name="username" placeholder="例如：小明" required>
 </div>
 <button type="submit" class="btn-success">创建用户（需刷卡激活）</button>
 </form>
 </div>
 <!-- 绑定卡片 -->
 <div id="tab-bind" class="tab-content">
 <form method="POST">
 <input type="hidden" name="action" value="bind_card">
 <div class="form-group">
 <label>要绑定的用户名</label>
 <input type="text" name="bind_username" placeholder="例如：小明" required>
 </div>
 <button type="submit" class="btn-warning">生成绑卡指令（10分钟内刷新卡）</button>
 </form>
 </div>
 <!-- 解绑卡片 -->
 <div id="tab-unbind" class="tab-content">
 <h3>方式一：刷卡自动解绑（推荐）</h3>
 <form method="POST">
 <input type="hidden" name="action" value="generate_unbind">
 <div class="form-group">
 <label>选择要解绑卡片的用户名</label>
 <input type="text" name="unbind_username" placeholder="例如：小明" required>
 </div>
 <button type="submit" class="btn-warning">生成解绑指令（10分钟内刷卡自动解绑）</button>
 </form>
 <hr style="margin:25px 0;">
 <h3>方式二：手动输入 UID 解绑（卡片丢失时使用）</h3>
 <form method="POST" onsubmit="return confirm('确定手动解绑此卡？无法撤销！')">
 <input type="hidden" name="action" value="manual_unbind">
 <div class="form-group">
 <label>卡片 UID</label>
 <input type="text" name="manual_unbind_uid" placeholder="例如：021FEFE4" required>
 </div>
 <button type="submit" class="btn-danger">🔓 手动解绑卡片</button>
 </form>
 </div>
 <!-- 充值 -->
 <div id="tab-recharge" class="tab-content">
 <form method="POST">
 <input type="hidden" name="action" value="add_coins">
 <div class="form-group">
 <label>用户名</label>
 <input type="text" name="add_username" placeholder="例如：小明" required>
 </div>
 <div class="form-group">
 <label>充值数量（枚）</label>
 <input type="number" name="coins" min="1" value="10" required>
 </div>
 <button type="submit" class="btn-success">立即充值</button>
 </form>
 </div>
 <!-- 🔻 新增：余额调整（扣币纠错） -->
 <div id="tab-adjust" class="tab-content">
 <h3>🔧 手动扣币（仅用于充错币等纠错场景）</h3>
 <form method="POST" onsubmit="return confirm('⚠️ 确定要扣除游戏币吗？此操作不可逆！')">
 <input type="hidden" name="action" value="deduct_coins">
 <div class="form-group">
 <label>用户名</label>
 <input type="text" name="deduct_username" placeholder="例如：小明" required>
 </div>
 <div class="form-group">
 <label>扣除数量（枚）</label>
 <input type="number" name="coins_to_deduct" min="1" value="10" required>
 </div>
 <button type="submit" class="btn-danger">🔻 扣除游戏币（纠错）</button>
 </form>
 <p style="color:#888; font-size:0.9em; margin-top:10px;">
 💡 提示：系统会自动检查余额，若余额不足将拒绝操作。
 </p>
 </div>
 <!-- 删除用户 -->
 <div id="tab-delete" class="tab-content">
 <form method="POST" onsubmit="return confirm('⚠️ 确定删除该用户？所有绑定卡片将永久丢失！')">
 <input type="hidden" name="action" value="delete_user">
 <div class="form-group">
 <label>要删除的用户名</label>
 <input type="text" name="delete_username" placeholder="例如：小明" required>
 </div>
 <button type="submit" class="btn-danger">🗑️ 删除用户及所有卡片</button>
 </form>
 </div>
 <!-- 查询 -->
 <div id="tab-query" class="tab-content">
 <form method="POST">
 <input type="hidden" name="action" value="query">
 <div class="form-group">
 <label>用户名 或 卡片 UID</label>
 <input type="text" name="identifier" placeholder="例如：小明 或 021FEFE4" required>
 </div>
 <button type="submit" class="btn-success">查询信息</button>
 </form>
 <?php if ($query_result): ?>
 <div class="alert alert-success" style="margin-top:15px;">
 <strong>👤 用户名：</strong><?= htmlspecialchars($query_result['username']) ?><br>
 <strong>💰 余额：</strong><?= $query_result['coins'] ?><br>
 <strong>📅 最后操作：</strong><?= $query_result['updated_at'] ?? '无' ?><br>
 <strong>💳 绑定卡片数：</strong><?= $query_result['card_count'] ?> <br><a href="user.php?identifier=<?= urlencode($query_result['username']) ?>" target="_blank" style="color:#1976d2; margin-top:8px; display:inline-block;">🔍 查看完整消费记录</a>
 </div>
 <?php endif; ?>
 </div>
 <!-- 待处理请求 -->
 <div id="tab-pending" class="tab-content">
 <h3>⏳ 待处理请求 (<?= count($pending_ops) ?> 项)</h3>
 <?php if ($pending_ops): ?>
 <?php foreach ($pending_ops as $op): ?>
 <div class="pending-item">
 <span class="pending-type">
 <?php switch ($op['type']) {
 case 'new_user': echo '🆕 创建用户'; break;
 case 'bind_card': echo '🔗 绑定卡片'; break;
 case 'unbind_card': echo '🔓 解绑卡片'; break;
 default: echo htmlspecialchars($op['type']);
 } ?>
 </span><br>
 用户名：<strong><?= htmlspecialchars($op['username']) ?></strong><br>
 过期时间：<code><?= htmlspecialchars($op['expires_at']) ?></code>
 <form method="POST" style="display:inline; margin-left:12px;" onsubmit="return confirm('确定取消此待处理操作？')">
 <input type="hidden" name="action" value="cancel_pending">
 <input type="hidden" name="pending_id" value="<?= (int)$op['id'] ?>">
 <button type="submit" class="btn-danger" style="padding:4px 8px; font-size:12px;">取消</button>
 </form>
 </div>
 <?php endforeach; ?>
 <?php else: ?>
 <p>✅ 当前没有待处理的请求。</p>
 <?php endif; ?>
 </div>
 <!-- 日志 -->
 <div id="tab-logs" class="tab-content">
 <h3>最近操作日志</h3>
 <?php if ($logs): ?>
 <?php foreach ($logs as $log): ?>
 <div class="log-row">
 [<?= $log['created_at'] ?>] <?= htmlspecialchars($log['username']) ?> -
 <?php if ($log['action'] === 'deduct') {
 echo '投币';
 } elseif ($log['action'] === 'admin_adj') {
 echo '<span style="color:#f44336;">🔧 管理员扣币（纠错）</span>';
 } elseif ($log['action'] === 'admin_recharge') {
 echo '<span style="color:#4CAF50;">💰 管理员充值</span>';
 } else {
 echo htmlspecialchars($log['action']);
 } ?>
 (余额: <?= $log['coins_before'] ?> → <?= $log['coins_after'] ?>)
 </div>
 <?php endforeach; ?>
 <?php else: ?>
 <p>暂无日志</p>
 <?php endif; ?>
 </div>
</div>
<script>
function showTab(tabId) {
 document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
 document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
 document.getElementById('tab-' + tabId).classList.add('active');
 event.target.classList.add('active');
}
</script>
</body>
</html>
