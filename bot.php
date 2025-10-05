<?php
/**
 * Super Downloader Bot - Professional Telegram Media Downloader
 * 
 * A single-file PHP bot that downloads media from various social platforms
 * using yt-dlp with long polling (getUpdates) method.
 * 
 * Features:
 * - Multi-platform support (YouTube, TikTok, Instagram, Twitter/X, Facebook, etc.)
 * - Smart queue system with SQLite database
 * - Automatic file cleanup
 * - Rate limiting
 * - Progress tracking
 * - Legal warnings
 * - Professional UI with emojis and formatting
 * 
 * Requirements:
 * - PHP 8+ with curl, json, sqlite3 extensions
 * - yt-dlp installed on the system
 * - ffmpeg (recommended for better quality)
 * 
 * Usage:
 * 1. Set your BOT_TOKEN below
 * 2. Run: php super_downloader_bot.php > bot.log 2>&1 &
 * 
 * Legal Notice:
 * This bot is for educational purposes only. Users are responsible for
 * complying with copyright laws and terms of service of the platforms.
 */

// ======== CONFIGURATION SECTION ========
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // Replace with your actual bot token
define('POLLING_INTERVAL', 2); // Seconds between each poll
define('FILE_CLEANUP_AGE', 3); // Days to keep files
define('MAX_FILE_SIZE_MB', 50); // Maximum file size in MB
define('RATE_LIMIT_HOURLY', 10); // Max requests per hour per user
define('DOWNLOAD_TIMEOUT', 300); // Download timeout in seconds
define('DOWNLOADS_DIR', __DIR__ . '/downloads');
define('DB_FILE', __DIR__ . '/tasks.db');
define('LOG_FILE', __DIR__ . '/bot.log');

// ======== SYSTEM FUNCTIONS ========
/**
 * Log system events
 */
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    echo $logEntry; // Also output to console
}

/**
 * Initialize required directories and database
 */
function initializeSystem() {
    // Create downloads directory if not exists
    if (!is_dir(DOWNLOADS_DIR)) {
        mkdir(DOWNLOADS_DIR, 0755, true);
        logMessage("Created downloads directory");
    }
    
    // Initialize SQLite database
    $db = new SQLite3(DB_FILE);
    
    // Create tasks table if not exists
    $db->exec('
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            status TEXT DEFAULT "pending",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            file_path TEXT,
            error_message TEXT
        )
    ');
    
    // Create user_stats table if not exists
    $db->exec('
        CREATE TABLE IF NOT EXISTS user_stats (
            user_id INTEGER PRIMARY KEY,
            downloads_count INTEGER DEFAULT 0,
            last_request DATETIME,
            requests_hour INTEGER DEFAULT 0,
            last_hour_reset DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    $db->close();
    logMessage("System initialized successfully");
}

/**
 * Clean old files
 */
function cleanupOldFiles() {
    $files = glob(DOWNLOADS_DIR . '/*');
    $now = time();
    $deletedCount = 0;
    
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > (FILE_CLEANUP_AGE * 86400)) {
            if (unlink($file)) {
                $deletedCount++;
                logMessage("Deleted old file: " . basename($file));
            }
        }
    }
    
    if ($deletedCount > 0) {
        logMessage("Cleanup completed: deleted $deletedCount old files");
    }
}

/**
 * Check rate limit for user
 */
function checkRateLimit($userId) {
    $db = new SQLite3(DB_FILE);
    
    // Get user stats
    $stmt = $db->prepare('SELECT * FROM user_stats WHERE user_id = ?');
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    $now = date('Y-m-d H:i:s');
    $currentHour = date('Y-m-d H');
    
    if (!$user) {
        // New user
        $stmt = $db->prepare('INSERT INTO user_stats (user_id, last_request, requests_hour, last_hour_reset) VALUES (?, ?, 1, ?)');
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $now, SQLITE3_TEXT);
        $stmt->bindValue(3, $currentHour, SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
        return true;
    }
    
    // Check if we need to reset hourly counter
    if (substr($user['last_hour_reset'], 0, 13) !== $currentHour) {
        $user['requests_hour'] = 0;
    }
    
    // Check rate limit
    if ($user['requests_hour'] >= RATE_LIMIT_HOURLY) {
        $db->close();
        return false;
    }
    
    // Update user stats
    $stmt = $db->prepare('UPDATE user_stats SET last_request = ?, requests_hour = requests_hour + 1, last_hour_reset = ? WHERE user_id = ?');
    $stmt->bindValue(1, $now, SQLITE3_TEXT);
    $stmt->bindValue(2, $currentHour, SQLITE3_TEXT);
    $stmt->bindValue(3, $userId, SQLITE3_INTEGER);
    $stmt->execute();
    
    $db->close();
    return true;
}

// ======== DOWNLOAD FUNCTIONS ========
/**
 * Detect platform from URL
 */
function detectPlatform($url) {
    $platforms = [
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'tiktok.com' => 'TikTok',
        'instagram.com' => 'Instagram',
        'twitter.com' => 'Twitter/X',
        'x.com' => 'Twitter/X',
        'facebook.com' => 'Facebook',
        'fb.watch' => 'Facebook',
        'reddit.com' => 'Reddit',
        'vimeo.com' => 'Vimeo',
        'pinterest.com' => 'Pinterest',
        'dailymotion.com' => 'Dailymotion',
        'soundcloud.com' => 'SoundCloud'
    ];
    
    foreach ($platforms as $domain => $name) {
        if (strpos($url, $domain) !== false) {
            return $name;
        }
    }
    
    return 'Unknown';
}

/**
 * Validate URL
 */
function validateUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    $platform = detectPlatform($url);
    if ($platform === 'Unknown') {
        return false;
    }
    
    return true;
}

/**
 * Download media using yt-dlp
 */
function downloadMedia($url, $taskId) {
    $db = new SQLite3(DB_FILE);
    
    try {
        // Update task status
        $stmt = $db->prepare('UPDATE tasks SET status = "downloading", updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bindValue(1, $taskId, SQLITE3_INTEGER);
        $stmt->execute();
        
        // Generate unique filename
        $filename = 'download_' . $taskId . '_' . time() . '.mp4';
        $filepath = DOWNLOADS_DIR . '/' . $filename;
        
        // Build yt-dlp command
        $command = sprintf(
            'yt-dlp -f "best[height<=720]" -o "%s" --no-playlist --max-filesize %dM %s 2>&1',
            escapeshellarg($filepath),
            MAX_FILE_SIZE_MB,
            escapeshellarg($url)
        );
        
        logMessage("Executing download command for task $taskId");
        
        // Execute command with timeout
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($command, $descriptorspec, $pipes);
        
        if (!is_resource($process)) {
            throw new Exception("Failed to start download process");
        }
        
        // Set timeout
        $startTime = time();
        $output = '';
        
        while (true) {
            $status = proc_get_status($process);
            
            // Check timeout
            if (time() - $startTime > DOWNLOAD_TIMEOUT) {
                proc_terminate($process);
                throw new Exception("Download timeout exceeded");
            }
            
            // Read output
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            
            if (stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $pipe) {
                    $output .= stream_get_contents($pipe);
                }
            }
            
            // Check if process finished
            if (!$status['running']) {
                break;
            }
        }
        
        // Close pipes
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        
        if ($exitCode !== 0) {
            throw new Exception("Download failed: " . $output);
        }
        
        // Check if file exists
        if (!file_exists($filepath)) {
            throw new Exception("Downloaded file not found");
        }
        
        // Update task with success
        $stmt = $db->prepare('UPDATE tasks SET status = "completed", file_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bindValue(1, $filepath, SQLITE3_TEXT);
        $stmt->bindValue(2, $taskId, SQLITE3_INTEGER);
        $stmt->execute();
        
        logMessage("Download completed successfully for task $taskId");
        
        // Update user stats
        $stmt = $db->prepare('UPDATE user_stats SET downloads_count = downloads_count + 1 WHERE user_id = (SELECT user_id FROM tasks WHERE id = ?)');
        $stmt->bindValue(1, $taskId, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->close();
        return $filepath;
        
    } catch (Exception $e) {
        // Update task with error
        $stmt = $db->prepare('UPDATE tasks SET status = "failed", error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bindValue(1, $e->getMessage(), SQLITE3_TEXT);
        $stmt->bindValue(2, $taskId, SQLITE3_INTEGER);
        $stmt->execute();
        
        logMessage("Download failed for task $taskId: " . $e->getMessage(), 'ERROR');
        $db->close();
        return false;
    }
}

/**
 * Process pending tasks
 */
function processPendingTasks() {
    $db = new SQLite3(DB_FILE);
    
    // Get pending tasks
    $result = $db->query('SELECT * FROM tasks WHERE status = "pending" ORDER BY created_at ASC LIMIT 1');
    $task = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($task) {
        logMessage("Processing pending task: {$task['id']}");
        downloadMedia($task['url'], $task['id']);
    }
    
    $db->close();
}

// ======== TELEGRAM INTERACTION ========
/**
 * Send message to Telegram
 */
function sendMessage($chatId, $text, $parseMode = 'Markdown', $replyToMessageId = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode
    ];
    
    if ($replyToMessageId) {
        $data['reply_to_message_id'] = $replyToMessageId;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        logMessage("Failed to send message: HTTP $httpCode", 'ERROR');
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Send document to Telegram
 */
function sendDocument($chatId, $filePath, $caption = '') {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    
    $data = [
        'chat_id' => $chatId,
        'document' => new CURLFile($filePath),
        'caption' => $caption,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        logMessage("Failed to send document: HTTP $httpCode", 'ERROR');
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Get updates from Telegram
 */
function getUpdates($offset = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getUpdates";
    
    $data = ['timeout' => 30];
    
    if ($offset) {
        $data['offset'] = $offset;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        logMessage("Failed to get updates: HTTP $httpCode", 'ERROR');
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Handle incoming message
 */
function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = trim($message['text'] ?? '');
    
    logMessage("Received message from user $userId: $text");
    
    // Handle commands
    if (strpos($text, '/') === 0) {
        handleCommand($chatId, $userId, $text);
        return;
    }
    
    // Handle URLs
    if (filter_var($text, FILTER_VALIDATE_URL)) {
        handleUrl($chatId, $userId, $text);
        return;
    }
    
    // Default response
    sendMessage($chatId, "🤔 لم أفهم طلبك. أرسل رابط فيديو أو استخدم /help للمساعدة.");
}

/**
 * Handle bot commands
 */
function handleCommand($chatId, $userId, $command) {
    switch ($command) {
        case '/start':
            $welcomeMessage = "🎬 **مرحباً بك في بوت التحميل الفائق!**\n\n"
                . "أنا بوت احترافي لتحميل الفيديوهات والوسائط من منصات التواصل الاجتماعي المختلفة.\n\n"
                . "**المنصات المدعومة:**\n"
                . "• YouTube • TikTok • Instagram • Twitter/X\n"
                . "• Facebook • Reddit • Vimeo • Pinterest\n\n"
                . "**كيفية الاستخدام:**\n"
                . "1. أرسل رابط الفيديو\n"
                . "2. انتظر حتى ينتهي التحميل\n"
                . "3. استمتع بالفيديو! 🎉\n\n"
                . "⚠️ **تنبيه قانوني:**\n"
                . "هذا البوت لأغراض تعليمية فقط. أنت مسؤول عن الامتثال لقوانين حقوق الطبع والنشر.\n\n"
                . "استخدم /help لعرض قائمة الأوامر.";
            sendMessage($chatId, $welcomeMessage);
            break;
            
        case '/help':
            $helpMessage = "📚 **قائمة الأوامر:**\n\n"
                . "/start - رسالة الترحيب والتعليمات\n"
                . "/help - عرض هذه القائمة\n"
                . "/stats - إحصائيات البوت\n"
                . "/cleanup - حذف الملفات القديمة\n"
                . "/about - معلومات عن البوت\n\n"
                . "**ملاحظات:**\n"
                . "• الحد الأقصى لحجم الملف: " . MAX_FILE_SIZE_MB . "MB\n"
                . "• الحد الأقصى للطلبات: " . RATE_LIMIT_HOURLY . " في الساعة\n"
                . "• يتم حذف الملفات تلقائياً بعد " . FILE_CLEANUP_AGE . " أيام";
            sendMessage($chatId, $helpMessage);
            break;
            
        case '/stats':
            $db = new SQLite3(DB_FILE);
            
            // Get total users
            $result = $db->query('SELECT COUNT(*) as count FROM user_stats');
            $totalUsers = $result->fetchArray()['count'];
            
            // Get total downloads
            $result = $db->query('SELECT COUNT(*) as count FROM tasks WHERE status = "completed"');
            $totalDownloads = $result->fetchArray()['count'];
            
            // Get pending tasks
            $result = $db->query('SELECT COUNT(*) as count FROM tasks WHERE status = "pending" OR status = "downloading"');
            $pendingTasks = $result->fetchArray()['count'];
            
            // Get total size of downloads directory
            $totalSize = 0;
            $files = glob(DOWNLOADS_DIR . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                }
            }
            $totalSizeMB = round($totalSize / 1048576, 2);
            
            $statsMessage = "📊 **إحصائيات البوت:**\n\n"
                . "👥 **المستخدمون:** $totalUsers\n"
                . "📥 **التحميلات الكاملة:** $totalDownloads\n"
                . "⏳ **المهام المعلقة:** $pendingTasks\n"
                . "💾 **مساحة التخزين:** $totalSizeMB MB\n"
                . "🕒 **آخر تحديث:** " . date('Y-m-d H:i:s');
            
            sendMessage($chatId, $statsMessage);
            $db->close();
            break;
            
        case '/cleanup':
            cleanupOldFiles();
            sendMessage($chatId, "🧹 تم حذف الملفات القديمة بنجاح!");
            break;
            
        case '/about':
            $aboutMessage = "ℹ️ **حول البوت:**\n\n"
                . "**الإصدار:** 1.0.0\n"
                . "**المطور:** Super Downloader Team\n"
                . "**التقنيات:** PHP 8+, yt-dlp, SQLite3\n"
                . "**الترخيص:** MIT License\n\n"
                . "**شكر خاص لـ:**\n"
                . "• فريق yt-dlp\n"
                . "• Telegram API\n"
                . "• مجتمع PHP المفتوح";
            sendMessage($chatId, $aboutMessage);
            break;
            
        default:
            sendMessage($chatId, "❌ أمر غير معروف. استخدم /help لعرض الأوامر المتاحة.");
    }
}

/**
 * Handle URL download request
 */
function handleUrl($chatId, $userId, $url) {
    // Check rate limit
    if (!checkRateLimit($userId)) {
        sendMessage($chatId, "⚠️ لقد تجاوزت الحد المسموح من الطلبات. يرجى المحاولة لاحقاً.");
        return;
    }
    
    // Validate URL
    if (!validateUrl($url)) {
        sendMessage($chatId, "❌ الرابط غير صالح أو المنصة غير مدعومة.");
        return;
    }
    
    // Detect platform
    $platform = detectPlatform($url);
    
    // Send initial message
    $initialMessage = sendMessage($chatId, "🔍 *اكتشفت رابط من:* $platform\n\n⏳ *جاري إضافة الطلب إلى الطابور...*");
    
    // Add task to database
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare('INSERT INTO tasks (user_id, url, status) VALUES (?, ?, "pending")');
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $url, SQLITE3_TEXT);
    $stmt->execute();
    
    $taskId = $db->lastInsertRowID();
    $db->close();
    
    // Send confirmation
    sendMessage($chatId, "✅ *تم إضافة الطلب إلى الطابور*\n\n🆔 *رقم المهمة:* $taskId\n🌐 *المنصة:* $platform\n\n⏳ *سيتم البدء بالتحميل قريباً...*", 'Markdown', $initialMessage['result']['message_id']);
}

/**
 * Check completed tasks and send results
 */
function checkCompletedTasks() {
    $db = new SQLite3(DB_FILE);
    
    // Get completed tasks that haven't been sent
    $result = $db->query('SELECT * FROM tasks WHERE status = "completed" AND file_path IS NOT NULL ORDER BY updated_at ASC LIMIT 5');
    
    while ($task = $result->fetchArray(SQLITE3_ASSOC)) {
        $filePath = $task['file_path'];
        $userId = $task['user_id'];
        $url = $task['url'];
        $platform = detectPlatform($url);
        
        if (file_exists($filePath)) {
            $fileSize = filesize($filePath);
            $fileSizeMB = round($fileSize / 1048576, 2);
            
            // Prepare caption
            $caption = "✅ *تم التحميل بنجاح!*\n\n"
                . "🌐 *المنصة:* $platform\n"
                . "📁 *حجم الملف:* $fileSizeMB MB\n"
                . "🆔 *رقم المهمة:* {$task['id']}\n"
                . "🕒 *وقت التحميل:* {$task['updated_at']}";
            
            // Send file if size is acceptable
            if ($fileSize <= (MAX_FILE_SIZE_MB * 1048576)) {
                sendDocument($userId, $filePath, $caption);
            } else {
                // File too large, send notification
                sendMessage($userId, $caption . "\n\n⚠️ *الملف كبير جداً للإرسال مباشرة. سيتم حذفه بعد " . FILE_CLEANUP_AGE . " أيام.*");
            }
            
            // Mark task as sent
            $stmt = $db->prepare('UPDATE tasks SET status = "sent" WHERE id = ?');
            $stmt->bindValue(1, $task['id'], SQLITE3_INTEGER);
            $stmt->execute();
            
            logMessage("Sent completed task {$task['id']} to user $userId");
        }
    }
    
    // Check failed tasks
    $result = $db->query('SELECT * FROM tasks WHERE status = "failed" ORDER BY updated_at ASC LIMIT 5');
    
    while ($task = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId = $task['user_id'];
        $url = $task['url'];
        $platform = detectPlatform($url);
        $errorMessage = $task['error_message'];
        
        $failureMessage = "❌ *فشل التحميل*\n\n"
            . "🌐 *المنصة:* $platform\n"
            . "🆔 *رقم المهمة:* {$task['id']}\n"
            . "⚠️ *الخطأ:* $errorMessage\n\n"
            . "يرجى التحقق من الرابط والمحاولة مرة أخرى.";
        
        sendMessage($userId, $failureMessage);
        
        // Mark task as notified
        $stmt = $db->prepare('UPDATE tasks SET status = "notified" WHERE id = ?');
        $stmt->bindValue(1, $task['id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        logMessage("Notified user $userId about failed task {$task['id']}");
    }
    
    $db->close();
}

/**
 * Self-monitoring function
 */
function selfMonitor() {
    static $lastMonitor = 0;
    
    $now = time();
    if ($now - $lastMonitor < 600) { // Run every 10 minutes
        return;
    }
    
    $lastMonitor = $now;
    
    $db = new SQLite3(DB_FILE);
    
    // Get stats
    $result = $db->query('SELECT COUNT(*) as count FROM user_stats');
    $totalUsers = $result->fetchArray()['count'];
    
    $result = $db->query('SELECT COUNT(*) as count FROM tasks WHERE status = "pending" OR status = "downloading"');
    $pendingTasks = $result->fetchArray()['count'];
    
    $result = $db->query('SELECT COUNT(*) as count FROM tasks WHERE status = "completed"');
    $completedTasks = $result->fetchArray()['count'];
    
    $db->close();
    
    logMessage("📊 Self-monitor: Users: $totalUsers, Pending: $pendingTasks, Completed: $completedTasks");
}

// ======== MAIN POLLING LOOP ========
function main() {
    logMessage("Starting Super Downloader Bot");
    
    // Initialize system
    initializeSystem();
    cleanupOldFiles();
    
    // Main polling loop
    $offset = null;
    $lastCleanup = time();
    
    while (true) {
        try {
            // Process pending tasks
            processPendingTasks();
            
            // Check completed tasks
            checkCompletedTasks();
            
            // Self-monitoring
            selfMonitor();
            
            // Get updates
            $updates = getUpdates($offset);
            
            if ($updates && isset($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $offset = $update['update_id'] + 1;
                    
                    if (isset($update['message'])) {
                        handleMessage($update['message']);
                    }
                }
            }
            
            // Periodic cleanup
            if (time() - $lastCleanup > 3600) { // Every hour
                cleanupOldFiles();
                $lastCleanup = time();
            }
            
            // Sleep before next poll
            sleep(POLLING_INTERVAL);
            
        } catch (Exception $e) {
            logMessage("Error in main loop: " . $e->getMessage(), 'ERROR');
            sleep(5); // Wait before retry
        }
    }
}

// ======== RUN BOT ========
if (php_sapi_name() === 'cli') {
    // Check if bot token is set
    if (BOT_TOKEN === '7914873287:AAFsOTf7xhunpULNtOgwrDDdtFUe7Ulr6_E') {
        echo "ERROR: Please set your BOT_TOKEN in the configuration section.\n";
        exit(1);
    }
    
    // Run the bot
    main();
} else {
    echo "This script can only be run from the command line.\n";
    exit(1);
}