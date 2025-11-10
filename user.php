<?php
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
$user_info = null;
$logs = [];

// 处理 POST 查询
$identifier = trim($_POST['identifier'] ?? $_GET['q'] ?? '');
if ($identifier) {
    // 尝试按用户名查
    $stmt = $pdo->prepare("SELECT id, username, coins FROM accounts WHERE username = ?");
    $stmt->execute([$identifier]);
    $user_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // 如果没查到，再尝试按卡片 UID 查
    if (!$user_info) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.username, a.coins
            FROM accounts a
            JOIN cards c ON a.id = c.account_id
            WHERE c.uid = ?
        ");
        $stmt->execute([$identifier]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($user_info) {
        // 获取日志
        $log_stmt = $pdo->prepare("SELECT * FROM swipe_logs WHERE account_id = ? ORDER BY created_at DESC");
        $log_stmt->execute([$user_info['id']]);
        $logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error = "❌ 未找到该用户或卡片，请检查输入是否正确（支持用户名或卡片UID）。";
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>🎮 个人游戏币记录查询</title>
 <style>
 body { font-family: "Microsoft YaHei", sans-serif; margin: 0; padding: 20px; background: #f0f4f8; }
 .container { max-width: 700px; margin: 0 auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
 h1 { text-align: center; color: #1a237e; margin-bottom: 25px; }
 .search-box { text-align: center; margin-bottom: 25px; }
 .search-box input { width: 100%; max-width: 400px; padding: 12px 16px; font-size: 16px; border: 2px solid #90caf9; border-radius: 8px; outline: none; transition: border-color 0.3s; }
 .search-box input:focus { border-color: #1976d2; }
 .search-box button { margin-top: 12px; padding: 10px 24px; font-size: 16px; background: #1976d2; color: white; border: none; border-radius: 6px; cursor: pointer; }
 .search-box button:hover { background: #1565c0; }
 .result { margin-top: 20px; }
 .user-card { background: #e3f2fd; padding: 18px; border-radius: 10px; margin-bottom: 20px; text-align: center; }
 .user-card h2 { margin: 0; color: #0d47a1; }
 .balance { font-size: 1.3em; font-weight: bold; color: #2e7d32; margin-top: 8px; }
 .log-item { padding: 12px 0; border-bottom: 1px solid #eee; }
 .log-time { color: #78909c; font-size: 0.9em; }
 .action-recharge { color: #2e7d32; font-weight: bold; }
 .action-deduct { color: #c62828; font-weight: bold; }
 .action-spend { color: #0288d1; }
 .no-logs { text-align: center; color: #90a4ae; padding: 20px; }
 .alert-error { background: #ffebee; color: #c62828; padding: 14px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
 </style>
</head>
<body>
<div class="container">
 <h1>🎮 游戏币消费记录查询</h1>
 <!-- 查询输入框 -->
 <div class="search-box">
 <form method="POST">
 <input type="text" name="identifier" placeholder="请输入用户名 或 卡片UID（如：小明 或 021FEFE4）" value="<?= htmlspecialchars($identifier) ?>" required autofocus>
 <br>
 <button type="submit">🔍 查询记录</button>
 </form>
 </div>
 <!-- 错误提示 -->
 <?php if ($error): ?>
 <div class="alert-error"><?= $error ?></div>
 <?php endif; ?>
 <!-- 查询结果 -->
 <?php if ($user_info): ?>
 <div class="result">
 <div class="user-card">
 <h2><?= htmlspecialchars($user_info['username']) ?></h2>
 <div class="balance">💰 当前余额：<?= $user_info['coins'] ?> 枚</div>
 </div>
 <h3>📜 最近操作记录（共 <?= count($logs) ?> 条）</h3>
 <?php if ($logs): ?>
 <?php foreach ($logs as $log): ?>
 <div class="log-item">
 <div class="log-time"><?= htmlspecialchars($log['created_at']) ?></div>
 <div>
 <?php switch ($log['action']) {
 case 'deduct':
 $text = '游戏代币';
 $cls = 'action-spend';
 break;
 case 'admin_recharge':
 $text = '💰 管理员充值';
 $cls = 'action-recharge';
 break;
 case 'admin_adj':
 $text = '🔧 管理员扣币（纠错）';
 $cls = 'action-deduct';
 break;
 default:
 $text = htmlspecialchars($log['action']);
 $cls = '';
 }
 ?>
 <span class="<?= $cls ?>"><?= $text ?></span> （<?= $log['coins_before'] ?> → <?= $log['coins_after'] ?>）
 </div>
 </div>
 <?php endforeach; ?>
 <?php else: ?>
 <div class="no-logs">暂无操作记录</div>
 <?php endif; ?>
 </div>
 <?php endif; ?>
</div>
</body>
</html>
