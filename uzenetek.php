<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "cucidb";

$currentUserId = (int)$_SESSION['user_id'];

function generateMessageId(): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < 25; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Send message ──────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');

        if ($receiverId > 0 && $receiverId !== $currentUserId && $message !== '') {
            // Verify receiver exists
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $chk->execute([$receiverId]);
            if ($chk->fetch()) {
                do {
                    $newMsgId = generateMessageId();
                    $idChk = $conn->prepare("SELECT COUNT(*) FROM uzenetek WHERE id = ?");
                    $idChk->execute([$newMsgId]);
                } while ($idChk->fetchColumn() > 0);

                $ins = $conn->prepare("INSERT INTO uzenetek (id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
                $ins->execute([$newMsgId, $currentUserId, $receiverId, $message]);
            }
        }
        header("Location: uzenetek.php?with=" . $receiverId);
        exit();
    }

    // ── Mark messages as read ─────────────────────────────────────────────────
    $withUserId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
    if ($withUserId > 0) {
        $markRead = $conn->prepare("
            UPDATE uzenetek SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $markRead->execute([$withUserId, $currentUserId]);
    }

    // ── Fetch conversation partners ───────────────────────────────────────────
    $partnersStmt = $conn->prepare("
        SELECT
            u.id,
            u.username,
            u.created_at AS member_since,
            MAX(m.sent_at) AS last_message_at,
            SUM(CASE WHEN m.receiver_id = :me AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM users u
        JOIN uzenetek m ON (
            (m.sender_id = u.id AND m.receiver_id = :me2)
            OR
            (m.receiver_id = u.id AND m.sender_id = :me3)
        )
        WHERE u.id != :me4
        GROUP BY u.id, u.username, u.created_at
        ORDER BY last_message_at DESC
    ");
    $partnersStmt->execute([
        ':me'  => $currentUserId,
        ':me2' => $currentUserId,
        ':me3' => $currentUserId,
        ':me4' => $currentUserId,
    ]);
    $partners = $partnersStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Fetch messages with selected user ────────────────────────────────────
    $messages    = [];
    $withUser    = null;
    if ($withUserId > 0) {
        $userStmt = $conn->prepare("SELECT id, username, created_at FROM users WHERE id = ?");
        $userStmt->execute([$withUserId]);
        $withUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($withUser) {
            $msgStmt = $conn->prepare("
                SELECT id, sender_id, receiver_id, message, sent_at, is_read
                FROM uzenetek
                WHERE (sender_id = :me AND receiver_id = :other)
                   OR (sender_id = :other2 AND receiver_id = :me2)
                ORDER BY sent_at ASC
            ");
            $msgStmt->execute([
                ':me'    => $currentUserId,
                ':other' => $withUserId,
                ':other2'=> $withUserId,
                ':me2'   => $currentUserId,
            ]);
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ── Count total unread ────────────────────────────────────────────────────
    $unreadStmt = $conn->prepare("SELECT COUNT(*) FROM uzenetek WHERE receiver_id = ? AND is_read = 0");
    $unreadStmt->execute([$currentUserId]);
    $totalUnread = (int)$unreadStmt->fetchColumn();

} catch (PDOException $e) {
    die("DB hiba: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Üzenetek</title>
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* Base variables (dark mode defaults) */
        :root {
            --accent: #ff8c00;
            --accent-glow: rgba(255,140,0,0.3);
            --accent-gradient: linear-gradient(135deg, #ff8c00, #ff5500);
            --bg-glass: rgba(0,0,0,0.7);
            --border-glass: rgba(255,140,0,0.2);
            --text-primary: #ffffff;
            --bg-sidebar: rgba(5,5,5,0.8);
            --bg-chat: rgba(8,8,8,0.95);
            --bg-header: rgba(5,5,5,0.8);
            --bg-input: rgba(255,255,255,0.05);
            --msg-sent: linear-gradient(135deg, rgba(255,140,0,0.85), rgba(200,80,0,0.85));
            --msg-received-bg: rgba(30,30,30,0.85);
            --msg-received-border: rgba(255,140,0,0.15);
            --msg-received-color: #ffffff;
            --partner-time: rgba(255,255,255,0.4);
            --avatar-bg: linear-gradient(135deg, #ff8c00, #ff5500);
            --avatar-color: #000;
        }

        body {
            min-height: 100vh;
            background: #0a0a0a;
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* Light mode overrides */
        body[data-theme="light"] {
            --accent: #7a9200;
            --accent-glow: rgba(122,146,0,0.3);
            --accent-gradient: linear-gradient(135deg, #B0CB1F, #8aA000);
            --bg-glass: rgba(240,240,235,0.9);
            --border-glass: rgba(122,146,0,0.3);
            --text-primary: #1a1f00;
            --bg-sidebar: rgba(240,240,235,0.9);
            --bg-chat: rgba(250,250,248,0.98);
            --bg-header: rgba(240,240,238,0.9);
            --bg-input: rgba(0,0,0,0.05);
            --msg-sent: linear-gradient(135deg, #B0CB1F, #8aA000);
            --msg-received-bg: rgba(220,220,215,0.85);
            --msg-received-border: rgba(122,146,0,0.3);
            --msg-received-color: #1a1f00;
            --partner-time: rgba(0,0,0,0.4);
            --avatar-bg: linear-gradient(135deg, #B0CB1F, #8aA000);
            --avatar-color: #1a1f00;
            background: #f5f5f0;
        }

        /* Top Bar */
        .top-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.6rem 1.2rem;
            background: var(--bg-glass);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-glass);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 1rem;
            border: 1px solid var(--border-glass);
            border-radius: 50px;
            background: rgba(var(--accent-rgb, 255,140,0), 0.1);
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            color: var(--accent);
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            user-select: none;
        }

        .back-btn:hover {
            background: color-mix(in srgb, var(--accent) 25%, transparent);
            border-color: var(--accent);
        }

        .page-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent);
            flex: 1;
        }

        /* Layout */
        .messages-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            height: calc(100vh - 54px);
            margin-top: 54px;
        }

        /* Sidebar */
        .sidebar {
            border-right: 1px solid var(--border-glass);
            background: var(--bg-sidebar);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem 1.2rem;
            font-size: 0.85rem;
            color: var(--partner-time);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-bottom: 1px solid var(--border-glass);
        }

        .partner-item {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 0.9rem 1.2rem;
            cursor: pointer;
            border-bottom: 1px solid rgba(255,140,0,0.06);
            border-bottom-color: color-mix(in srgb, var(--border-glass) 20%, transparent);
            transition: background 0.18s;
            text-decoration: none;
            color: inherit;
        }

        .partner-item:hover,
        .partner-item.active {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
        }

        .partner-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--avatar-bg);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--avatar-color);
            flex-shrink: 0;
            position: relative;
        }

        .partner-info { flex: 1; min-width: 0; }

        .partner-name {
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .partner-time {
            font-size: 0.75rem;
            color: var(--partner-time);
            margin-top: 2px;
        }

        .unread-badge {
            background: var(--accent);
            color: var(--avatar-color);
            border-radius: 50%;
            width: 20px; height: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .no-partners {
            padding: 2rem 1.2rem;
            text-align: center;
            color: var(--partner-time);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        /* Chat area */
        .chat-area {
            display: flex;
            flex-direction: column;
            background: var(--bg-chat);
            position: relative;
        }

        .chat-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-glass);
            display: flex;
            align-items: center;
            gap: 1rem;
            background: var(--bg-header);
        }

        .chat-partner-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--avatar-bg);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            color: var(--avatar-color);
        }

        .chat-partner-name {
            font-weight: 700;
            font-size: 1.05rem;
        }

        .chat-partner-member {
            font-size: 0.75rem;
            color: var(--partner-time);
        }

        .messages-list {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
        }

        .msg-bubble {
            max-width: 65%;
            padding: 0.65rem 1rem;
            border-radius: 18px;
            font-size: 0.97rem;
            line-height: 1.5;
            word-wrap: break-word;
            position: relative;
        }

        .msg-bubble.sent {
            align-self: flex-end;
            background: var(--msg-sent);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg-bubble.received {
            align-self: flex-start;
            background: var(--msg-received-bg);
            border: 1px solid var(--msg-received-border);
            color: var(--msg-received-color);
            border-bottom-left-radius: 4px;
        }

        .msg-time {
            font-size: 0.68rem;
            margin-top: 4px;
            text-align: right;
            opacity: 0.55;
        }

        /* Input area */
        .chat-input-area {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-glass);
            display: flex;
            gap: 0.8rem;
            align-items: flex-end;
            background: var(--bg-header);
        }

        .msg-textarea {
            flex: 1;
            background: var(--bg-input);
            border: 1px solid var(--border-glass);
            border-radius: 14px;
            color: var(--text-primary);
            padding: 0.7rem 1rem;
            font-size: 0.97rem;
            resize: none;
            min-height: 44px;
            max-height: 140px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
            line-height: 1.45;
        }

        .msg-textarea:focus { border-color: var(--accent); }

        .send-btn {
            width: 44px; height: 44px;
            background: var(--accent-gradient);
            border: none;
            border-radius: 50%;
            color: var(--avatar-color);
            font-size: 1.2rem;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .send-btn:hover { transform: scale(1.08); box-shadow: 0 0 16px var(--accent-glow); }

        /* Empty state */
        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            color: var(--partner-time);
        }

        .empty-chat-icon { font-size: 3.5rem; }
        .empty-chat-text { font-size: 1rem; }

        /* Mobile */
        @media (max-width: 640px) {
            .messages-layout { grid-template-columns: 1fr; }
            .sidebar { display: <?php echo $withUserId > 0 ? 'none' : 'flex'; ?>; height: calc(100vh - 54px); }
            .chat-area { display: <?php echo $withUserId > 0 ? 'flex' : 'none'; ?>; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--accent) 30%, transparent); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--accent) 50%, transparent); }
    </style>
</head>
<body>
    <div class="top-bar">
        <a href="main.php" class="back-btn">← Vissza</a>
        <div class="page-title">
            💬 Üzenetek
            <?php if ($totalUnread > 0): ?>
                <span style="font-size:0.8rem;background:var(--accent);color:var(--avatar-color);border-radius:50px;padding:1px 8px;margin-left:6px;"><?php echo $totalUnread; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="messages-layout">

        <!-- Sidebar: conversation list -->
        <div class="sidebar">
            <div class="sidebar-header">Beszélgetések</div>
            <?php if (empty($partners)): ?>
                <div class="no-partners">
                    Még nincsenek üzeneteid.<br>
                    Kattints egy eladóra a főoldalon az üzenetküldéshez.
                </div>
            <?php else: ?>
                <?php foreach ($partners as $p): ?>
                    <a href="uzenetek.php?with=<?php echo $p['id']; ?>"
                       class="partner-item <?php echo ($withUserId == $p['id']) ? 'active' : ''; ?>">
                        <div class="partner-avatar">
                            <?php echo strtoupper(substr($p['username'], 0, 1)); ?>
                        </div>
                        <div class="partner-info">
                            <div class="partner-name"><?php echo htmlspecialchars($p['username']); ?></div>
                            <div class="partner-time">
                                <?php
                                    $ts = strtotime($p['last_message_at']);
                                    $diff = time() - $ts;
                                    if ($diff < 60) echo 'Az imént';
                                    elseif ($diff < 3600) echo round($diff/60) . ' perce';
                                    elseif ($diff < 86400) echo round($diff/3600) . ' órája';
                                    else echo date('Y.m.d', $ts);
                                ?>
                            </div>
                        </div>
                        <?php if ($p['unread_count'] > 0): ?>
                            <div class="unread-badge"><?php echo $p['unread_count']; ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Chat area -->
        <div class="chat-area">
            <?php if ($withUser): ?>
                <div class="chat-header">
                    <div class="chat-partner-avatar">
                        <?php echo strtoupper(substr($withUser['username'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="chat-partner-name"><?php echo htmlspecialchars($withUser['username']); ?></div>
                        <div class="chat-partner-member">
                            Tag azóta: <?php echo date('Y.m.d', strtotime($withUser['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <div class="messages-list" id="messagesList">
                    <?php if (empty($messages)): ?>
                        <div style="text-align:center;color:var(--partner-time);margin-top:2rem;font-size:0.9rem;">
                            Még nincs üzenet. Küldj egyet!
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <div class="msg-bubble <?php echo ($msg['sender_id'] == $currentUserId) ? 'sent' : 'received'; ?>">
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                <div class="msg-time">
                                    <?php echo date('H:i', strtotime($msg['sent_at'])); ?>
                                    <?php if ($msg['sender_id'] == $currentUserId && $msg['is_read']): ?>
                                        &nbsp;✓✓
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form class="chat-input-area" method="post">
                    <input type="hidden" name="receiver_id" value="<?php echo $withUserId; ?>">
                    <input type="hidden" name="send_message" value="1">
                    <textarea class="msg-textarea" name="message" id="msgInput"
                        placeholder="Írj üzenetet..." rows="1" required
                        onkeydown="handleEnter(event)"></textarea>
                    <button type="submit" class="send-btn" title="Küldés">➤</button>
                </form>

            <?php else: ?>
                <div class="empty-chat">
                    <div class="empty-chat-icon">💬</div>
                    <div class="empty-chat-text">Válassz ki egy beszélgetést a bal oldali listából</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-scroll to bottom of messages
        const msgList = document.getElementById('messagesList');
        if (msgList) msgList.scrollTop = msgList.scrollHeight;

        // Send on Enter (Shift+Enter = new line)
        function handleEnter(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                e.target.closest('form').submit();
            }
        }

        // Auto-resize textarea
        const ta = document.getElementById('msgInput');
        if (ta) {
            ta.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 140) + 'px';
            });
        }

        // Theme handling
        const saved = localStorage.getItem('theme');
        const themeStylesheet = document.getElementById('themeStylesheet');
        if (saved === 'light') {
            document.body.setAttribute('data-theme', 'light');
            themeStylesheet.href = 'theme-light.css';
        } else {
            document.body.setAttribute('data-theme', 'dark');
            themeStylesheet.href = 'theme-dark.css';
        }
    </script>
</body>
</html>