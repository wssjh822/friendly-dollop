<?php
/**
 * 单文件无需登录聊天室 - 网络错误修复版
 */

// ============================================
// 配置文件部分
// ============================================

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 检查并设置上传目录
$upload_dir = dirname(__FILE__) . '/uploads/';
if (!file_exists($upload_dir)) {
    if (@mkdir($upload_dir, 0777, true)) {
        @chmod($upload_dir, 0777);
    }
}

// 数据库文件
$db_file = dirname(__FILE__) . '/chat.db';

// 创建数据库
function initDB() {
    global $db_file;
    if (!file_exists($db_file)) {
        $db = new SQLite3($db_file);
        $db->exec("CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            user_color TEXT NOT NULL,
            message_type TEXT NOT NULL,
            content TEXT,
            file_name TEXT,
            file_size INTEGER,
            ip_address TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_timestamp ON messages(timestamp)");
        $db->close();
    }
}

// 生成用户颜色
function generateUserColor($username = '') {
    $colors = ['#3366cc', '#dc3912', '#ff9900', '#109618', '#990099'];
    if (!empty($username)) {
        $hash = crc32($username);
        return $colors[abs($hash) % count($colors)];
    }
    return $colors[array_rand($colors)];
}

// 获取客户端IP
function getClientIP() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// 初始化数据库
initDB();

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// ============================================
// API处理部分
// ============================================

// 如果是API请求
if (isset($_GET['api']) || isset($_POST['action'])) {
    // 设置JSON头
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'send_message':
            handleSendMessage();
            break;
        case 'get_messages':
            handleGetMessages();
            break;
        case 'get_online_users':
            handleGetOnlineUsers();
            break;
        case 'clear_chat':
            handleClearChat();
            break;
        case 'upload':
            handleUpload();
            break;
        case 'ping':
            // 网络连接测试
            echo json_encode(['success' => true, 'message' => '服务器连接正常']);
            break;
        default:
            echo json_encode(['success' => false, 'error' => '未知操作', 'received_action' => $action]);
    }
    exit;
}

// 发送消息处理
function handleSendMessage() {
    global $db_file;
    try {
        $db = new SQLite3($db_file);
        
        $username = trim($_POST['username'] ?? '游客');
        $user_color = $_POST['user_color'] ?? generateUserColor($username);
        $message = trim($_POST['message'] ?? '');
        
        if (empty($username)) {
            echo json_encode(['success' => false, 'error' => '用户名不能为空']);
            return;
        }
        
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => '消息不能为空']);
            return;
        }
        
        $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $ip = getClientIP();
        
        $stmt = $db->prepare("INSERT INTO messages (username, user_color, message_type, content, ip_address) VALUES (:username, :color, 'text', :content, :ip)");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':color', $user_color, SQLITE3_TEXT);
        $stmt->bindValue(':content', $message, SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => '发送成功']);
        } else {
            echo json_encode(['success' => false, 'error' => '保存失败']);
        }
        
        $db->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '数据库错误: ' . $e->getMessage()]);
    }
}

// 获取消息处理
function handleGetMessages() {
    global $db_file;
    try {
        $db = new SQLite3($db_file);
        $last_id = intval($_GET['last_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT id, username, user_color, message_type, content, file_name, file_size, timestamp FROM messages WHERE id > :last_id ORDER BY timestamp ASC LIMIT 50");
        $stmt->bindValue(':last_id', $last_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        $db->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '数据库错误']);
    }
}

// 获取在线用户
function handleGetOnlineUsers() {
    global $db_file;
    try {
        $db = new SQLite3($db_file);
        $result = $db->query("SELECT DISTINCT username, user_color FROM messages WHERE timestamp > datetime('now', '-2 minutes') ORDER BY timestamp DESC LIMIT 50");
        
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
        $db->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '数据库错误']);
    }
}

// 清空聊天
function handleClearChat() {
    global $db_file;
    try {
        $db = new SQLite3($db_file);
        $db->exec("DELETE FROM messages");
        $db->exec("DELETE FROM sqlite_sequence WHERE name='messages'");
        echo json_encode(['success' => true, 'message' => '聊天记录已清空']);
        $db->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '数据库错误']);
    }
}

// 文件上传处理
function handleUpload() {
    global $upload_dir;
    
    // 检查目录权限
    if (!is_writable($upload_dir)) {
        echo json_encode(['success' => false, 'error' => '上传目录不可写: ' . $upload_dir]);
        return;
    }
    
    // 检查是否有文件上传
    if (!isset($_FILES['files']) || $_FILES['files']['error'][0] == UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'error' => '没有选择文件']);
        return;
    }
    
    try {
        $db = new SQLite3($db_file);
        $username = trim($_POST['username'] ?? '游客');
        $user_color = $_POST['user_color'] ?? generateUserColor($username);
        $text_message = trim($_POST['message'] ?? '');
        $ip = getClientIP();
        
        $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $text_message = htmlspecialchars($text_message, ENT_QUOTES, 'UTF-8');
        
        $file = $_FILES['files'];
        $original_name = $file['name'][0];
        $file_size = $file['size'][0];
        $file_tmp = $file['tmp_name'][0];
        $file_type = $file['type'][0];
        $file_error = $file['error'][0];
        
        // 检查上传错误
        if ($file_error !== UPLOAD_ERR_OK) {
            $error_msg = getUploadError($file_error);
            echo json_encode(['success' => false, 'error' => '上传错误: ' . $error_msg]);
            return;
        }
        
        // 检查文件大小（限制为5MB）
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file_size > $max_size) {
            echo json_encode(['success' => false, 'error' => '文件太大，最大支持5MB']);
            return;
        }
        
        // 检查文件类型
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 
                         'application/pdf', 'text/plain'];
        
        if (!in_array($file_type, $allowed_types)) {
            echo json_encode(['success' => false, 'error' => '不支持的文件类型: ' . $file_type]);
            return;
        }
        
        // 生成安全文件名
        $safe_name = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $original_name);
        $unique_name = uniqid() . '_' . time() . '_' . $safe_name;
        $destination = $upload_dir . $unique_name;
        
        // 移动文件
        if (move_uploaded_file($file_tmp, $destination)) {
            // 确定消息类型
            $message_type = strpos($file_type, 'image/') === 0 ? 'image' : 'file';
            
            // 保存到数据库
            $stmt = $db->prepare("INSERT INTO messages (username, user_color, message_type, content, file_name, file_size, ip_address) VALUES (:username, :color, :type, :content, :file_name, :file_size, :ip)");
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':color', $user_color, SQLITE3_TEXT);
            $stmt->bindValue(':type', $message_type, SQLITE3_TEXT);
            $stmt->bindValue(':content', $text_message, SQLITE3_TEXT);
            $stmt->bindValue(':file_name', $unique_name, SQLITE3_TEXT);
            $stmt->bindValue(':file_size', $file_size, SQLITE3_INTEGER);
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => '上传成功',
                    'file_path' => 'uploads/' . $unique_name
                ]);
            } else {
                @unlink($destination);
                echo json_encode(['success' => false, 'error' => '保存到数据库失败']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => '文件移动失败，请检查目录权限']);
        }
        
        $db->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
}

// 获取上传错误信息
function getUploadError($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE: return '文件大小超过服务器限制';
        case UPLOAD_ERR_FORM_SIZE: return '文件大小超过表单限制';
        case UPLOAD_ERR_PARTIAL: return '文件只有部分被上传';
        case UPLOAD_ERR_NO_FILE: return '没有文件被上传';
        case UPLOAD_ERR_NO_TMP_DIR: return '缺少临时文件夹';
        case UPLOAD_ERR_CANT_WRITE: return '写入文件失败';
        case UPLOAD_ERR_EXTENSION: return 'PHP扩展阻止了文件上传';
        default: return '未知错误 (错误代码: ' . $code . ')';
    }
}

// ============================================
// HTML界面部分
// ============================================

$defaultUsername = '游客' . rand(1000, 9999);
$defaultColor = generateUserColor($defaultUsername);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>简易聊天室</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .header {
            background: white;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo i {
            color: #667eea;
            font-size: 24px;
        }
        
        .user-setup {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-setup input {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 20px;
            font-size: 14px;
            width: 150px;
        }
        
        .color-picker {
            width: 36px;
            height: 36px;
            border: 2px solid #ddd;
            border-radius: 50%;
            cursor: pointer;
        }
        
        .main-content {
            display: flex;
            height: 600px;
        }
        
        .sidebar {
            width: 250px;
            background: #f8f9fa;
            border-right: 1px solid #e9ecef;
            padding: 20px;
            overflow-y: auto;
        }
        
        .sidebar h3 {
            margin-bottom: 15px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        #usersList {
            margin-bottom: 20px;
        }
        
        .user-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin-bottom: 5px;
            background: white;
            border-radius: 5px;
        }
        
        .user-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .clear-chat {
            background: #ff4444;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .messages-container {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: white;
        }
        
        .message {
            margin-bottom: 15px;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .message-header {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
            gap: 10px;
        }
        
        .message-user {
            font-weight: 600;
            font-size: 14px;
        }
        
        .message-time {
            color: #999;
            font-size: 12px;
        }
        
        .message-content {
            padding: 10px 15px;
            border-radius: 15px;
            max-width: 80%;
            word-wrap: break-word;
            line-height: 1.5;
        }
        
        .message-other .message-content {
            background: #f0f2f5;
            color: #333;
        }
        
        .message-self {
            text-align: right;
        }
        
        .message-self .message-content {
            background: #0084ff;
            color: white;
            margin-left: auto;
        }
        
        .message-image {
            max-width: 300px;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 5px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .message-file {
            display: inline-block;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            border: 1px solid #e9ecef;
            margin-top: 5px;
        }
        
        .input-area {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .input-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        #messageInput {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 24px;
            font-size: 14px;
            resize: none;
            height: 48px;
        }
        
        .send-btn {
            background: #667eea;
            color: white;
            border: none;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
        }
        
        .controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .upload-btn {
            background: white;
            border: 2px solid #667eea;
            color: #667eea;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .file-preview {
            padding: 6px 12px;
            background: white;
            border-radius: 15px;
            font-size: 13px;
            border: 1px solid #ddd;
        }
        
        .debug-info {
            padding: 10px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            font-size: 12px;
            color: #666;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            border-radius: 8px;
            color: white;
            z-index: 1000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s;
        }
        
        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        .notification.success {
            background: #4CAF50;
        }
        
        .notification.error {
            background: #f44336;
        }
        
        .notification.info {
            background: #2196F3;
        }
    </style>
</head>
<body>
    <div id="notification" class="notification"></div>
    
    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-comments"></i>
                <h2>简易聊天室</h2>
            </div>
            <div class="user-setup">
                <input type="text" id="username" placeholder="输入昵称..." value="<?php echo $defaultUsername; ?>">
                <div class="color-picker" id="colorPicker" title="点击更换颜色" style="background-color: <?php echo $defaultColor; ?>"></div>
            </div>
        </div>
        
        <div class="main-content">
            <div class="sidebar">
                <h3><i class="fas fa-users"></i> 在线用户 (<span id="userCount">1</span>)</h3>
                <div id="usersList"></div>
                <button class="clear-chat" onclick="clearChat()">
                    <i class="fas fa-trash"></i> 清空聊天记录
                </button>
                
                <div style="margin-top: 20px; padding: 10px; background: white; border-radius: 5px;">
                    <h4><i class="fas fa-info-circle"></i> 使用说明</h4>
                    <p style="font-size: 12px; color: #666; margin-top: 5px;">
                        1. 输入昵称开始聊天<br>
                        2. 支持文字、图片和文件<br>
                        3. 文件最大5MB<br>
                        4. 自动刷新消息
                    </p>
                </div>
            </div>
            
            <div class="chat-area">
                <div class="messages-container" id="messagesContainer">
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-comments" style="font-size: 48px; margin-bottom: 20px;"></i>
                        <h3>欢迎来到聊天室！</h3>
                        <p>输入昵称，开始聊天吧</p>
                    </div>
                </div>
                
                <div class="input-area">
                    <div class="input-row">
                        <textarea id="messageInput" placeholder="输入消息... (Enter发送，Shift+Enter换行)" rows="1"></textarea>
                        <button class="send-btn" onclick="sendMessage()" title="发送消息">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    
                    <div class="controls">
                        <div id="filePreview" style="display: none;" class="file-preview"></div>
                        <label class="upload-btn" for="fileInput">
                            <i class="fas fa-paperclip"></i>
                            选择文件
                        </label>
                        <input type="file" id="fileInput" style="display: none;">
                        <button class="upload-btn" onclick="testConnection()" style="background: #6c757d;">
                            <i class="fas fa-wifi"></i>
                            测试连接
                        </button>
                    </div>
                </div>
                
                <div class="debug-info" id="debugInfo">
                    连接状态: <span id="connectionStatus" style="color: #4CAF50;">✓ 正常</span>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let lastMessageId = 0;
        let currentUsername = document.getElementById('username').value;
        let currentColor = document.getElementById('colorPicker').style.backgroundColor;
        let selectedFile = null;
        let isConnected = true;
        
        // 初始化
        document.addEventListener('DOMContentLoaded', function() {
            console.log('聊天室初始化...');
            
            // 测试初始连接
            testConnection();
            
            // 加载消息和用户
            loadMessages();
            loadOnlineUsers();
            
            // 设置轮询
            setInterval(() => {
                if (isConnected) {
                    loadMessages();
                    loadOnlineUsers();
                }
            }, 2000);
            
            // 用户名更新
            document.getElementById('username').addEventListener('input', function() {
                currentUsername = this.value.trim() || '游客';
            });
            
            // 颜色选择
            document.getElementById('colorPicker').addEventListener('click', function() {
                const colors = ['#3366cc', '#dc3912', '#ff9900', '#109618', '#990099'];
                const randomColor = colors[Math.floor(Math.random() * colors.length)];
                this.style.backgroundColor = randomColor;
                currentColor = randomColor;
                showNotification('颜色已更换', 'success');
            });
            
            // 消息输入框
            const messageInput = document.getElementById('messageInput');
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            
            // 文件选择
            document.getElementById('fileInput').addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    selectedFile = e.target.files[0];
                    showFilePreview();
                }
            });
            
            messageInput.focus();
        });
        
        // 测试连接
        function testConnection() {
            fetch('?api=1&action=ping')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP错误: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        isConnected = true;
                        updateConnectionStatus('✓ 连接正常', '#4CAF50');
                        showNotification('服务器连接正常', 'success');
                    } else {
                        throw new Error('服务器返回错误');
                    }
                })
                .catch(error => {
                    console.error('连接测试失败:', error);
                    isConnected = false;
                    updateConnectionStatus('✗ 连接失败', '#f44336');
                    showNotification('连接失败: ' + error.message, 'error');
                });
        }
        
        // 更新连接状态
        function updateConnectionStatus(text, color) {
            const statusEl = document.getElementById('connectionStatus');
            statusEl.textContent = text;
            statusEl.style.color = color;
        }
        
        // 发送消息
        function sendMessage() {
            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            
            if (!currentUsername) {
                showNotification('请输入昵称', 'error');
                return;
            }
            
            if (!message && !selectedFile) {
                showNotification('消息不能为空', 'error');
                return;
            }
            
            if (selectedFile) {
                uploadFile(message);
            } else {
                sendTextMessage(message);
            }
            
            messageInput.value = '';
            messageInput.focus();
        }
        
        // 发送文本消息
        function sendTextMessage(message) {
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('username', currentUsername);
            formData.append('user_color', currentColor);
            formData.append('message', message);
            
            showNotification('发送中...', 'info');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP错误: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showNotification('发送成功', 'success');
                    loadMessages();
                } else {
                    showNotification('发送失败: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                console.error('发送错误:', error);
                showNotification('网络错误: ' + error.message, 'error');
                testConnection(); // 自动测试连接
            });
        }
        
        // 上传文件
        function uploadFile(textMessage) {
            if (!selectedFile) return;
            
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('username', currentUsername);
            formData.append('user_color', currentColor);
            formData.append('message', textMessage);
            formData.append('files[]', selectedFile);
            
            const sendBtn = document.querySelector('.send-btn');
            const originalHTML = sendBtn.innerHTML;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            sendBtn.disabled = true;
            
            showNotification('上传中...', 'info');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP错误: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                sendBtn.innerHTML = originalHTML;
                sendBtn.disabled = false;
                
                if (data.success) {
                    showNotification('文件上传成功', 'success');
                    loadMessages();
                    clearFilePreview();
                } else {
                    showNotification('上传失败: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                console.error('上传错误:', error);
                sendBtn.innerHTML = originalHTML;
                sendBtn.disabled = false;
                showNotification('上传失败: ' + error.message, 'error');
                testConnection(); // 自动测试连接
            });
        }
        
        // 显示文件预览
        function showFilePreview() {
            if (!selectedFile) return;
            
            const preview = document.getElementById('filePreview');
            preview.innerHTML = `
                <span>${selectedFile.name} (${formatFileSize(selectedFile.size)})</span>
                <button onclick="clearFilePreview()" style="background:none;border:none;color:red;margin-left:10px;cursor:pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;
            preview.style.display = 'inline-block';
        }
        
        // 清除文件预览
        function clearFilePreview() {
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('filePreview').style.display = 'none';
        }
        
        // 加载消息
        function loadMessages() {
            if (!isConnected) return;
            
            fetch(`?api=1&action=get_messages&last_id=${lastMessageId}&t=${Date.now()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP错误: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.messages.length > 0) {
                    const container = document.getElementById('messagesContainer');
                    const isAtBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;
                    
                    data.messages.forEach(message => {
                        addMessageToUI(message);
                        lastMessageId = Math.max(lastMessageId, message.id);
                    });
                    
                    // 移除欢迎消息
                    const welcomeMsg = container.querySelector('.welcome-message');
                    if (welcomeMsg && data.messages.length > 0) {
                        welcomeMsg.remove();
                    }
                    
                    if (isAtBottom) {
                        container.scrollTop = container.scrollHeight;
                    }
                }
            })
            .catch(error => {
                console.error('加载消息失败:', error);
                isConnected = false;
                updateConnectionStatus('✗ 连接异常', '#f44336');
            });
        }
        
        // 添加消息到界面
        function addMessageToUI(message) {
            const container = document.getElementById('messagesContainer');
            const isSelf = message.username === currentUsername;
            
            let messageHTML = '';
            
            if (message.message_type === 'text') {
                messageHTML = `
                    <div class="message ${isSelf ? 'message-self' : 'message-other'}">
                        <div class="message-header">
                            <div class="message-user" style="color: ${message.user_color}">
                                ${escapeHtml(message.username)}
                            </div>
                            <div class="message-time">${formatTime(message.timestamp)}</div>
                        </div>
                        <div class="message-content">
                            ${escapeHtml(message.content).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            } else if (message.message_type === 'image') {
                const filePath = 'uploads/' + message.file_name;
                messageHTML = `
                    <div class="message ${isSelf ? 'message-self' : 'message-other'}">
                        <div class="message-header">
                            <div class="message-user" style="color: ${message.user_color}">
                                ${escapeHtml(message.username)}
                            </div>
                            <div class="message-time">${formatTime(message.timestamp)}</div>
                        </div>
                        ${message.content ? `<div class="message-content">${escapeHtml(message.content)}</div>` : ''}
                        <img src="${filePath}" alt="图片" class="message-image" onclick="openImage('${filePath}')" onerror="this.src='data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"300\" height=\"200\"><rect width=\"300\" height=\"200\" fill=\"%23f0f0f0\"/><text x=\"150\" y=\"100\" text-anchor=\"middle\" fill=\"%23999\">图片加载失败</text></svg>'">
                    </div>
                `;
            } else if (message.message_type === 'file') {
                const filePath = 'uploads/' + message.file_name;
                const fileIcon = getFileIcon(message.file_name);
                messageHTML = `
                    <div class="message ${isSelf ? 'message-self' : 'message-other'}">
                        <div class="message-header">
                            <div class="message-user" style="color: ${message.user_color}">
                                ${escapeHtml(message.username)}
                            </div>
                            <div class="message-time">${formatTime(message.timestamp)}</div>
                        </div>
                        ${message.content ? `<div class="message-content">${escapeHtml(message.content)}</div>` : ''}
                        <a href="${filePath}" download="${escapeHtml(message.file_name)}" class="message-file">
                            <i class="fas fa-file"></i> ${escapeHtml(message.file_name)} (${formatFileSize(message.file_size)})
                        </a>
                    </div>
                `;
            }
            
            container.insertAdjacentHTML('beforeend', messageHTML);
        }
        
        // 加载在线用户
        function loadOnlineUsers() {
            if (!isConnected) return;
            
            fetch(`?api=1&action=get_online_users&t=${Date.now()}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const usersList = document.getElementById('usersList');
                    const userMap = new Map();
                    
                    // 添加当前用户
                    userMap.set(currentUsername, {username: currentUsername, color: currentColor});
                    
                    // 添加其他用户
                    data.users.forEach(user => {
                        userMap.set(user.username, user);
                    });
                    
                    // 更新列表
                    usersList.innerHTML = '';
                    const users = Array.from(userMap.values());
                    
                    users.forEach(user => {
                        const userItem = document.createElement('div');
                        userItem.className = 'user-item';
                        userItem.innerHTML = `
                            <div class="user-color" style="background-color: ${user.color}"></div>
                            <div>${escapeHtml(user.username)}</div>
                        `;
                        usersList.appendChild(userItem);
                    });
                    
                    document.getElementById('userCount').textContent = users.length;
                }
            })
            .catch(error => {
                console.error('加载用户失败:', error);
            });
        }
        
        // 清空聊天
        function clearChat() {
            if (confirm('确定要清空所有聊天记录吗？')) {
                fetch('?api=1', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=clear_chat'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('聊天记录已清空', 'success');
                        document.getElementById('messagesContainer').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: #666;">
                                <i class="fas fa-comments" style="font-size: 48px; margin-bottom: 20px;"></i>
                                <h3>聊天记录已清空</h3>
                                <p>开始新的对话吧</p>
                            </div>
                        `;
                        lastMessageId = 0;
                    }
                });
            }
        }
        
        // 打开图片
        function openImage(src) {
            window.open(src, '_blank');
        }
        
        // 获取文件图标
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return 'fas fa-image';
            if (ext === 'pdf') return 'fas fa-file-pdf';
            if (['doc', 'docx'].includes(ext)) return 'fas fa-file-word';
            if (['xls', 'xlsx'].includes(ext)) return 'fas fa-file-excel';
            if (ext === 'txt') return 'fas fa-file-alt';
            return 'fas fa-file';
        }
        
        // 格式化时间
        function formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = now - date;
            
            if (diff < 60000) return '刚刚';
            if (diff < 3600000) return Math.floor(diff / 60000) + '分钟前';
            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString('zh-CN', {hour: '2-digit', minute: '2-digit'});
            }
            return date.toLocaleDateString('zh-CN');
        }
        
        // 格式化文件大小
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // HTML转义
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 显示通知
        function showNotification(message, type) {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = `notification ${type}`;
            
            setTimeout(() => notification.classList.add('show'), 10);
            setTimeout(() => notification.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>