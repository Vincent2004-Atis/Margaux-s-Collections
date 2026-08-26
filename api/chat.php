<?php
require_once __DIR__ . '/../middleware/auth.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db      = getDB();
$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

$rawBody = '';
$input   = [];
if (!empty($_POST)) {
    $input = $_POST; 
} else {
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $input = $decoded;
        }
    }
}
$action = $input['action'] ?? $_GET['action'] ?? '';

// ── AUTO-CREATE tables ────────────────────────────────────────────
$db->query("CREATE TABLE IF NOT EXISTS conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    subject         VARCHAR(255) NOT NULL DEFAULT 'General Inquiry',
    order_id        INT DEFAULT NULL,
    status          ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS messages (
    message_id      INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_type     ENUM('customer','admin') NOT NULL,
    sender_id       INT NOT NULL,
    message         TEXT NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");



// ── GET conversations ─────────────────────────────────────────────
// NOTE: last_message is the text of the most recent message in the
// thread. The frontend now shows this instead of `subject`, so the
// list no longer displays a leftover "General Inquiry" / category label.
if ($action === 'get_conversations') {
    if ($isAdmin) {
        $stmt = $db->prepare("
            SELECT c.*, u.name AS customer_name, u.email AS customer_email,
                   u.user_id, u.profile_photo,
                   SUM(CASE WHEN m.is_read=0 AND m.sender_type='customer' THEN 1 ELSE 0 END) AS unread_count,
                   MAX(m.created_at) AS last_message_at,
                   (SELECT lm.message FROM messages lm
                    WHERE lm.conversation_id = c.conversation_id
                    ORDER BY lm.created_at DESC LIMIT 1) AS last_message
            FROM conversations c
            JOIN users u ON u.user_id = c.user_id
            LEFT JOIN messages m ON m.conversation_id = c.conversation_id
            GROUP BY c.conversation_id
            ORDER BY last_message_at DESC, c.created_at DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT c.*,
                   SUM(CASE WHEN m.is_read=0 AND m.sender_type='admin' THEN 1 ELSE 0 END) AS unread_count,
                   MAX(m.created_at) AS last_message_at,
                   (SELECT lm.message FROM messages lm
                    WHERE lm.conversation_id = c.conversation_id
                    ORDER BY lm.created_at DESC LIMIT 1) AS last_message
            FROM conversations c
            LEFT JOIN messages m ON m.conversation_id = c.conversation_id
            WHERE c.user_id = ?
            GROUP BY c.conversation_id
            ORDER BY last_message_at DESC, c.created_at DESC
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }
    $convos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'conversations' => $convos]);
    exit;
}

// ── GET messages ──────────────────────────────────────────────────
if ($action === 'get_messages') {
    $cid = (int)($input['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'message'=>'Missing conversation_id']); exit; }

    if (!$isAdmin) {
        $check = $db->prepare("SELECT conversation_id FROM conversations WHERE conversation_id=? AND user_id=?");
        $check->bind_param('ii', $cid, $userId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        $check->close();
        $mark = $db->prepare("UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_type='admin'");
        $mark->bind_param('i', $cid); $mark->execute(); $mark->close();
    } else {
        $mark = $db->prepare("UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_type='customer'");
        $mark->bind_param('i', $cid); $mark->execute(); $mark->close();
    }

    $stmt = $db->prepare("SELECT * FROM messages WHERE conversation_id=? ORDER BY created_at ASC");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Include profile_photo and user_id in conversation info
    $info = $db->prepare("
        SELECT c.*, u.name AS customer_name, u.email AS customer_email,
               u.user_id, u.profile_photo
        FROM conversations c
        JOIN users u ON u.user_id = c.user_id
        WHERE c.conversation_id = ?
    ");
    $info->bind_param('i', $cid);
    $info->execute();
    $convo = $info->get_result()->fetch_assoc();
    $info->close();

    echo json_encode(['success' => true, 'messages' => $messages, 'conversation' => $convo]);
    exit;
}

// ── START conversation (customer only) ───────────────────────────
if ($action === 'start_conversation') {
    if ($isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Admins cannot start conversations.']);
        exit;
    }

    $subject = trim($input['subject'] ?? 'General Inquiry');
    $orderId = !empty($input['order_id']) ? (int)$input['order_id'] : null;
    $message = trim($input['message'] ?? '');

    if (!$message) { echo json_encode(['success'=>false,'message'=>'Message cannot be empty.']); exit; }

    if ($orderId) {
        $chk = $db->prepare("SELECT order_id FROM orders WHERE order_id=? AND user_id=?");
        $chk->bind_param('ii', $orderId, $userId);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) $orderId = null;
        $chk->close();
    }

    if ($orderId) {
        $existing = $db->prepare("SELECT conversation_id FROM conversations WHERE user_id=? AND order_id=? AND status='open' ORDER BY created_at DESC LIMIT 1");
        $existing->bind_param('ii', $userId, $orderId);
    } else {
        $existing = $db->prepare("SELECT conversation_id FROM conversations WHERE user_id=? AND status='open' ORDER BY created_at DESC LIMIT 1");
        $existing->bind_param('i', $userId);
    }
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    $existing->close();

    if ($row) {
        $cid = (int)$row['conversation_id'];
    } else {
        if ($orderId) {
            $stmt = $db->prepare("INSERT INTO conversations (user_id, subject, order_id) VALUES (?,?,?)");
            $stmt->bind_param('isi', $userId, $subject, $orderId);
        } else {
            $stmt = $db->prepare("INSERT INTO conversations (user_id, subject) VALUES (?,?)");
            $stmt->bind_param('is', $userId, $subject);
        }
        if (!$stmt->execute()) { echo json_encode(['success'=>false,'message'=>'DB error: '.$stmt->error]); exit; }
        $cid = $db->insert_id;
        $stmt->close();
    }

    $senderType = 'customer';
    $msg = $db->prepare("INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?,?,?,?)");
    $msg->bind_param('isis', $cid, $senderType, $userId, $message);
    if (!$msg->execute()) { echo json_encode(['success'=>false,'message'=>'DB error: '.$msg->error]); exit; }
    $msg->close();

    $upd = $db->prepare("UPDATE conversations SET updated_at=NOW() WHERE conversation_id=?");
    $upd->bind_param('i', $cid); $upd->execute(); $upd->close();

    echo json_encode(['success' => true, 'conversation_id' => $cid]);
    exit;
}

// ── SEND message ──────────────────────────────────────────────────
if ($action === 'send_message') {
    $cid     = (int)($input['conversation_id'] ?? 0);
    $message = trim($input['message'] ?? '');

    if (!$message) { echo json_encode(['success'=>false,'message'=>'Message cannot be empty.']); exit; }
    if (!$cid)     { echo json_encode(['success'=>false,'message'=>'Invalid conversation.']); exit; }

    if ($isAdmin) {
        $check = $db->prepare("SELECT conversation_id FROM conversations WHERE conversation_id=?");
        $check->bind_param('i', $cid);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            echo json_encode(['success'=>false,'message'=>'Conversation not found.']); exit;
        }
        $check->close();
    } else {
        $check = $db->prepare("SELECT conversation_id FROM conversations WHERE conversation_id=? AND user_id=?");
        $check->bind_param('ii', $cid, $userId);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Forbidden']); exit;
        }
        $check->close();
    }

    $senderType = $isAdmin ? 'admin' : 'customer';
    $stmt = $db->prepare("INSERT INTO messages (conversation_id, sender_type, sender_id, message) VALUES (?,?,?,?)");
    $stmt->bind_param('isis', $cid, $senderType, $userId, $message);
    if (!$stmt->execute()) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$stmt->error]); exit;
    }
    $stmt->close();

    $upd = $db->prepare("UPDATE conversations SET updated_at=NOW() WHERE conversation_id=?");
    $upd->bind_param('i', $cid); $upd->execute(); $upd->close();

    echo json_encode(['success' => true, 'sender_type' => $senderType]);
    exit;
}

// ── Unread count ──────────────────────────────────────────────────
if ($action === 'unread_count') {
    if ($isAdmin) {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM messages m JOIN conversations c ON c.conversation_id=m.conversation_id WHERE m.sender_type='customer' AND m.is_read=0");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM messages m JOIN conversations c ON c.conversation_id=m.conversation_id WHERE c.user_id=? AND m.sender_type='admin' AND m.is_read=0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['success' => true, 'unread_count' => (int)$row['cnt']]);
    exit;
}

http_response_code(400);
echo json_encode([
    'success'      => false,
    'message'      => 'Unknown action.',
    'debug_action' => $action,
    'debug_isAdmin'=> $isAdmin,
    'debug_role'   => $_SESSION['role'] ?? 'not set',
    'debug_post'   => $_POST,
]);
