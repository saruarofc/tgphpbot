<?php
/**
 * Telegram PHP Bot Hosting Bot - Enhanced Version with Arbitrary Filenames, Improved Security, and Rate Limiting
 *
 * This script allows users to upload, list, delete their files,
 * set, get information about, and delete webhooks for their Telegram bots.
 *
 * **Important Security Notice:**
 * Hosting and executing user-provided scripts poses significant security risks.
 * Ensure you understand the implications and have implemented robust security measures.
 * It's highly recommended to consult with a security professional before deploying this script.
 */

// ---------------------------
// Configuration and Settings
// ---------------------------

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log errors to a file with improved logging format
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// **IMPORTANT:** Replace with your **hosting** Telegram bot token obtained from @BotFather
define('HOSTING_BOT_TOKEN', '8152559...'); // Replace with your actual hosting bot token

// Define the base directory for storing user files
define('BASE_DIR', __DIR__ . '/user_bots/');

// Define the states directory for managing user states
define('STATES_DIR', __DIR__ . '/states/');

// Define the temporary directory for handling large JSON responses
define('TEMP_DIR', __DIR__ . '/temp/');

// Define maximum allowed file size (e.g., 10MB)
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB

// Define maximum number of files a user can upload
define('MAX_FILES_PER_USER', 10);

// Ensure the temporary directory exists with secure permissions
if (!file_exists(TEMP_DIR)) {
    if (!mkdir(TEMP_DIR, 0755, true)) {
        error_log("[" . date('Y-m-d H:i:s') . "] Failed to create temp directory: " . TEMP_DIR);
        exit;
    }
}

// ---------------------------
// Helper Functions
// ---------------------------

/**
 * Send a message to a Telegram user.
 *
 * @param string $chatId The chat ID to send the message to.
 * @param string $message The message text.
 * @param int|null $replyToMessageId The message ID to reply to (optional).
 * @return void
 */
function sendMessage($chatId, $message, $replyToMessageId = null) {
    $botToken = HOSTING_BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown' // Changed to Markdown for better formatting compatibility
    ];

    if ($replyToMessageId !== null) {
        $postData['reply_to_message_id'] = $replyToMessageId;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // Changed to send as multipart/form-data
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if(curl_errno($ch)){
        error_log("[" . date('Y-m-d H:i:s') . "] sendMessage cURL error: " . curl_error($ch));
    }
    
    curl_close($ch);
}

/**
 * Download a file from Telegram.
 *
 * @param string $fileId The file ID to download.
 * @return string|false The file content or false on failure.
 */
function downloadFile($fileId) {
    $botToken = HOSTING_BOT_TOKEN;
    
    // Get file path from Telegram
    $url = "https://api.telegram.org/bot{$botToken}/getFile";
    $postData = ['file_id' => $fileId];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if(curl_errno($ch)){
        error_log("[" . date('Y-m-d H:i:s') . "] downloadFile getFile cURL error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    $responseData = json_decode($response, true);

    if ($responseData['ok']) {
        $filePath = $responseData['result']['file_path'];
        $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";

        // Download the actual file content
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Set a timeout for downloading the file
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $fileContent = curl_exec($ch);
        
        if(curl_errno($ch)){
            error_log("[" . date('Y-m-d H:i:s') . "] downloadFile file download cURL error: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpStatus !== 200) {
            error_log("[" . date('Y-m-d H:i:s') . "] downloadFile HTTP status code: " . $httpStatus);
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        return $fileContent;
    }

    error_log("[" . date('Y-m-d H:i:s') . "] downloadFile: Telegram API response not OK.");
    return false;
}

/**
 * List all files in the user's directory with details.
 *
 * @param string $userDir The path to the user's directory.
 * @return string The formatted list of files or an appropriate message.
 */
function listUserFiles($userDir) {
    if (!file_exists($userDir)) {
        return "📁 No directory found.";
    }

    $files = scandir($userDir);
    $userFiles = array_filter($files, function($file) use ($userDir) {
        return is_file($userDir . $file);
    });

    if (empty($userFiles)) {
        return "📁 No files found in your directory.";
    }

    // Get the domain from configuration
    $domain = 'https://bots.abhibhai.com/botmaker/user_bots/';
    
    // Get user ID from directory path
    $userId = basename($userDir);
    
    $fileList = "📄 *Your Files:*\n";
    $fileList .= "📂 *Directory:* " . $domain . $userId . "/\n\n";
    foreach ($userFiles as $file) {
        $filePath = $userDir . $file;
        $fileSize = filesize($filePath);
        $fileList .= "- `" . htmlspecialchars($file) . "` (" . formatBytes($fileSize) . ")\n";
    }
    return $fileList;
}

/**
 * Format bytes into human-readable form.
 *
 * @param int $bytes Number of bytes.
 * @param int $decimals Number of decimal points.
 * @return string Formatted string.
 */
function formatBytes($bytes, $decimals = 2) {
    $size = ['B','KB','MB','GB','TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    if ($factor == 0) return $bytes . ' ' . $size[$factor];
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $size[$factor];
}

/**
 * Get the current state of a user.
 *
 * @param int $userId The user's Telegram ID.
 * @return string The current state.
 */
function getUserState($userId) {
    $stateFile = STATES_DIR . $userId . '.txt';
    if (file_exists($stateFile)) {
        return trim(file_get_contents($stateFile));
    }
    return 'none';
}

/**
 * Set the state of a user.
 *
 * @param int $userId The user's Telegram ID.
 * @param string $state The new state.
 * @return void
 */
function setUserState($userId, $state) {
    $stateFile = STATES_DIR . $userId . '.txt';
    file_put_contents($stateFile, $state, LOCK_EX);
}

/**
 * Generate the webhook URL for a user.
 *
 * @param int $userId The user's Telegram ID.
 * @param string $fileName The filename of the user's script.
 * @return string The webhook URL.
 */
function generateWebhookUrl($userId, $fileName) {
    // Your base domain (ensure it includes HTTPS and ends with a slash)
    $domain = 'https://bots.abhibhai.com/botmaker/user_bots/';

    // Sanitize user ID and filename to prevent any issues
    $safeUserId = intval($userId);
    $safeFileName = sanitizeFileName($fileName);

    // Path to the user's script
    $userScriptPath = BASE_DIR . $safeUserId . '/' . $safeFileName;

    // Check if the script exists
    if (!file_exists($userScriptPath)) {
        return '';
    }

    // Generate the full webhook URL
    return "{$domain}{$safeUserId}/{$safeFileName}";
}

/**
 * Sanitize the filename to prevent directory traversal and other security issues.
 *
 * @param string $fileName The original filename.
 * @return string The sanitized filename.
 */
function sanitizeFileName($fileName) {
    // Remove any path information and sanitize the filename
    $fileName = basename($fileName);
    // Replace any non-alphanumeric, underscore, hyphen, or dot characters with an underscore
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);
}

/**
 * Set the webhook for the user's bot using Telegram Bot API.
 *
 * @param string $botToken The user's Telegram bot token.
 * @param string $webhookUrl The webhook URL to set.
 * @return array The response from Telegram API.
 */
function setWebhook($botToken, $webhookUrl) {
    $url = "https://api.telegram.org/bot{$botToken}/setWebhook";
    $postData = [
        'url' => $webhookUrl
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if(curl_errno($ch)){
        error_log("[" . date('Y-m-d H:i:s') . "] setWebhook cURL error: " . curl_error($ch));
        curl_close($ch);
        return ['ok' => false, 'description' => curl_error($ch)];
    }

    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus !== 200) {
        return ['ok' => false, 'description' => "HTTP status code: {$httpStatus}"];
    }

    return json_decode($response, true);
}

/**
 * Get webhook information for the user's bot using Telegram Bot API.
 *
 * @param string $botToken The user's Telegram bot token.
 * @return array The response from Telegram API.
 */
function getWebhookInfo($botToken) {
    $url = "https://api.telegram.org/bot{$botToken}/getWebhookInfo";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if(curl_errno($ch)){
        error_log("[" . date('Y-m-d H:i:s') . "] getWebhookInfo cURL error: " . curl_error($ch));
        curl_close($ch);
        return ['ok' => false, 'description' => curl_error($ch)];
    }

    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus !== 200) {
        return ['ok' => false, 'description' => "HTTP status code: {$httpStatus}"];
    }

    return json_decode($response, true);
}

/**
 * Delete the webhook for the user's bot using Telegram Bot API.
 *
 * @param string $botToken The user's Telegram bot token.
 * @return array The response from Telegram API.
 */
function deleteWebhook($botToken) {
    $url = "https://api.telegram.org/bot{$botToken}/deleteWebhook";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    // No POST fields required
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if(curl_errno($ch)){
        error_log("[" . date('Y-m-d H:i:s') . "] deleteWebhook cURL error: " . curl_error($ch));
        curl_close($ch);
        return ['ok' => false, 'description' => curl_error($ch)];
    }

    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus !== 200) {
        return ['ok' => false, 'description' => "HTTP status code: {$httpStatus}"];
    }

    return json_decode($response, true);
}

/**
 * Sanitize the JSON response to mask sensitive information.
 *
 * @param array $response The original JSON response as an associative array.
 * @return array The sanitized JSON response.
 */
function sanitizeJsonResponse($response) {
    // Clone the response to avoid modifying the original
    $sanitized = $response;

    // Example: Mask the webhook URL if present
    if (isset($sanitized['result']['url'])) {
        $sanitized['result']['url'] = str_replace("https://", "https://[REDACTED]/", $sanitized['result']['url']);
    }

    // Add more masking rules as needed

    return $sanitized;
}

/**
 * Send a document to a Telegram user.
 *
 * @param string $chatId The chat ID to send the document to.
 * @param string $filePath The path to the file to send.
 * @param string $caption The caption of the document.
 * @param int|null $replyToMessageId The message ID to reply to (optional).
 * @return void
 */
function sendDocument($chatId, $filePath, $caption = "", $replyToMessageId = null) {
    $botToken = HOSTING_BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$botToken}/sendDocument";

    // Initialize CURLFile
    $cfile = new CURLFile(realpath($filePath));

    $postFields = [
        'chat_id' => $chatId,
        'document' => $cfile,
        'caption' => $caption,
        'parse_mode' => 'Markdown'
    ];

    if ($replyToMessageId !== null) {
        $postFields['reply_to_message_id'] = $replyToMessageId;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if(curl_errno($ch)){
        error_log("[" . date('Y-m-d H:i:s') . "] sendDocument cURL error: " . curl_error($ch));
    }
    
    curl_close($ch);
}

/**
 * Send a JSON response to the user, handling large responses by sending as a document if necessary.
 *
 * @param string $chatId The chat ID to send the JSON response to.
 * @param string $jsonResponse The JSON response string.
 * @param int|null $replyToMessageId The message ID to reply to (optional).
 * @return void
 */
function sendJsonResponse($chatId, $jsonResponse, $replyToMessageId = null) {
    // Check if the JSON response is too long for a Telegram message (~4096 characters)
    if (strlen($jsonResponse) > 4000) {
        // Ensure TEMP_DIR exists
        if (!file_exists(TEMP_DIR)) {
            if (!mkdir(TEMP_DIR, 0755, true)) {
                error_log("[" . date('Y-m-d H:i:s') . "] Failed to create temp directory: " . TEMP_DIR);
                // Fallback: Send a link to the JSON response instead of the file
                $fallbackMessage = "📄 **Telegram API Response is too large to display.**";
                sendMessage($chatId, $fallbackMessage, $replyToMessageId);
                return;
            }
        }

        // Save the JSON to a temporary file
        $tempFilePath = TEMP_DIR . "{$chatId}_response.json";
        if (file_put_contents($tempFilePath, $jsonResponse) === false) {
            error_log("[" . date('Y-m-d H:i:s') . "] Failed to save JSON response to file: {$tempFilePath}");
            // Fallback: Send a link to the JSON response instead of the file
            $fallbackMessage = "📄 **Telegram API Response is too large to display and failed to save as a file.**";
            sendMessage($chatId, $fallbackMessage, $replyToMessageId);
            return;
        }

        // Send the file to the user
        $caption = "📄 **Telegram API Response:**";
        sendDocument($chatId, $tempFilePath, $caption, $replyToMessageId);

        // Delete the temporary file after sending
        unlink($tempFilePath);
    } else {
        // Send the JSON as a message with code formatting
        $message = "📄 **Telegram API Response:**\n```json\n{$jsonResponse}\n```";
        sendMessage($chatId, $message, $replyToMessageId);
    }
}

/**
 * Sanitize and format the JSON response for better readability.
 *
 * @param array $response The original JSON response as an associative array.
 * @return string The formatted JSON string.
 */
function formatJsonResponse($response) {
    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// ---------------------------
// Main Processing Logic
// ---------------------------

// Retrieve the incoming update from Telegram
$update = json_decode(file_get_contents('php://input'), true);

// Ensure the update contains a message
if (!isset($update['message'])) {
    // Optionally log or handle other update types (e.g., callbacks)
    error_log("[" . date('Y-m-d H:i:s') . "] Update does not contain a message.");
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$userId = $message['from']['id'];
$userDir = BASE_DIR . $userId . '/';
$replyToMessageId = isset($message['message_id']) ? $message['message_id'] : null;

// Ensure the base and states directories exist with secure permissions
if (!file_exists(BASE_DIR)) {
    if (!mkdir(BASE_DIR, 0755, true)) {
        error_log("[" . date('Y-m-d H:i:s') . "] Failed to create base directory: " . BASE_DIR);
        exit;
    }
}

if (!file_exists(STATES_DIR)) {
    if (!mkdir(STATES_DIR, 0755, true)) {
        error_log("[" . date('Y-m-d H:i:s') . "] Failed to create states directory: " . STATES_DIR);
        exit;
    }
}

// Ensure the user's directory exists with secure permissions
if (!file_exists($userDir)) {
    if (!mkdir($userDir, 0755, true)) {
        sendMessage($chatId, "❌ Error: Failed to create your directory.", $replyToMessageId);
        error_log("[" . date('Y-m-d H:i:s') . "] Failed to create directory for user ID: {$userId}");
        exit;
    }
}

// Retrieve the user's current state
$userState = getUserState($userId);

// Handle text messages (commands or inputs based on state)
if (isset($message['text'])) {
    $text = trim($message['text']);

    switch ($userState) {
        case 'awaiting_webhook_token':
            // User has provided their bot token for webhook setup
            $botToken = trim($text);
            if (empty($botToken)) {
                sendMessage($chatId, "❌ Invalid bot token. Please try setting the webhook again using the `/webhook` command.", $replyToMessageId);
                setUserState($userId, 'none');
                break;
            }

            // Save the bot token securely (e.g., in a database or encrypted file)
            // For simplicity, we'll store it in a temporary file
            // **Note**: Storing tokens in plain text is insecure. Consider using encryption.
            file_put_contents(STATES_DIR . $userId . '_bot_token.txt', $botToken, LOCK_EX);

            sendMessage($chatId, "✅ Bot token received.\n\n📄 Please send me the filename of your script (e.g., `myscript.php`).", $replyToMessageId);
            setUserState($userId, 'awaiting_webhook_filename');
            break;

        case 'awaiting_webhook_filename':
            // User has provided the filename for webhook setup
            $fileName = sanitizeFileName($text);
            $filePath = $userDir . $fileName;

            // Check if the file exists
            if (!file_exists($filePath)) {
                sendMessage($chatId, "❌ File `<b>{$fileName}</b>` not found in your directory. Please ensure you've uploaded the correct file using the `/upload` command.", $replyToMessageId);
                setUserState($userId, 'none');
                // Optionally, delete the stored bot token
                unlink(STATES_DIR . $userId . '_bot_token.txt');
                break;
            }

            // Retrieve the bot token
            $botToken = trim(file_get_contents(STATES_DIR . $userId . '_bot_token.txt'));
            // Delete the temporary bot token file
            unlink(STATES_DIR . $userId . '_bot_token.txt');

            // Generate the webhook URL
            $webhookUrl = generateWebhookUrl($userId, $fileName);

            if (empty($webhookUrl)) {
                sendMessage($chatId, "❌ Error: The file `<b>{$fileName}</b>` does not exist in your directory. Please upload it using the `/upload` command.", $replyToMessageId);
                setUserState($userId, 'none');
                break;
            }

            // Set the webhook via Telegram API
            $setWebhookResponse = setWebhook($botToken, $webhookUrl);

            // Sanitize and format the JSON response
            $sanitizedResponse = sanitizeJsonResponse($setWebhookResponse);
            $formattedJson = formatJsonResponse($sanitizedResponse);
            $jsonResponse = htmlspecialchars($formattedJson, ENT_QUOTES, 'UTF-8');

            if ($setWebhookResponse['ok']) {
                $successMessage = "✅ Webhook set successfully!\n\n📄 **Telegram API Response:**\n```json\n{$jsonResponse}\n```";
                sendJsonResponse($chatId, $successMessage, $replyToMessageId);
            } else {
                $errorMessage = "❌ Failed to set webhook.\n\n🔍 **Telegram API Response:**\n```json\n{$jsonResponse}\n```";
                sendJsonResponse($chatId, $errorMessage, $replyToMessageId);
            }

            // Reset the user's state
            setUserState($userId, 'none');
            break;

        case 'awaiting_getwebhookinfo_token':
            // User has provided their bot token for getting webhook info
            $botToken = trim($text);
            if (empty($botToken)) {
                sendMessage($chatId, "❌ Invalid bot token. Please try again using the `/getwebhookinfo` command.", $replyToMessageId);
                setUserState($userId, 'none');
                break;
            }

            // Save the bot token temporarily
            file_put_contents(STATES_DIR . $userId . '_bot_token.txt', $botToken, LOCK_EX);

            sendMessage($chatId, "✅ Bot token received.\n\n📄 Please send me the filename of your script (e.g., `myscript.php`).", $replyToMessageId);
            setUserState($userId, 'awaiting_getwebhookinfo_filename');
            break;

        case 'awaiting_getwebhookinfo_filename':
            // User has provided the filename for getting webhook info
            $fileName = sanitizeFileName($text);
            $filePath = $userDir . $fileName;

            // Check if the file exists
            if (!file_exists($filePath)) {
                sendMessage($chatId, "❌ File `<b>{$fileName}</b>` not found in your directory. Please ensure you've uploaded the correct file using the `/upload` command.", $replyToMessageId);
                setUserState($userId, 'none');
                // Optionally, delete the stored bot token
                unlink(STATES_DIR . $userId . '_bot_token.txt');
                break;
            }

            // Retrieve the bot token
            $botToken = trim(file_get_contents(STATES_DIR . $userId . '_bot_token.txt'));
            // Delete the temporary bot token file
            unlink(STATES_DIR . $userId . '_bot_token.txt');

            // Get webhook info via Telegram API
            $getWebhookInfoResponse = getWebhookInfo($botToken);

            // Sanitize and format the JSON response
            $sanitizedResponse = sanitizeJsonResponse($getWebhookInfoResponse);
            $formattedJson = formatJsonResponse($sanitizedResponse);
            $jsonResponse = htmlspecialchars($formattedJson, ENT_QUOTES, 'UTF-8');

            if ($getWebhookInfoResponse['ok']) {
                $webhookInfo = $getWebhookInfoResponse['result'];
                $status = $webhookInfo['url'] ? 'Set' : 'Not Set';
                $lastErrorMessage = $webhookInfo['last_error_message'] ?? 'N/A';
                $lastErrorDate = isset($webhookInfo['last_error_date']) ? date("Y-m-d H:i:s", $webhookInfo['last_error_date']) : 'N/A';

                $messageText = "🔍 *Webhook Information:*\n\n" .
                               "*Status:* {$status}\n" .
                               "*Webhook URL:* " . ($webhookInfo['url'] ?? 'N/A') . "\n" .
                               "*Last Error Message:* {$lastErrorMessage}\n" .
                               "*Last Error Date:* {$lastErrorDate}";
                sendMessage($chatId, $messageText, $replyToMessageId);
            } else {
                $errorMessage = "❌ Failed to retrieve webhook info.\n\n🔍 **Telegram API Response:**\n```json\n{$jsonResponse}\n```";
                sendJsonResponse($chatId, $errorMessage, $replyToMessageId);
            }

            // Reset the user's state
            setUserState($userId, 'none');
            break;

        case 'awaiting_deletewebhook_token':
            // User has provided their bot token for deleting webhook
            $botToken = trim($text);
            if (empty($botToken)) {
                sendMessage($chatId, "❌ Invalid bot token. Please try again using the `/deletewebhook` command.", $replyToMessageId);
                setUserState($userId, 'none');
                break;
            }

            // Save the bot token temporarily
            file_put_contents(STATES_DIR . $userId . '_bot_token.txt', $botToken, LOCK_EX);

            sendMessage($chatId, "✅ Bot token received.\n\n📄 Please send me the filename of your script (e.g., `myscript.php`).", $replyToMessageId);
            setUserState($userId, 'awaiting_deletewebhook_filename');
            break;

        case 'awaiting_deletewebhook_filename':
            // User has provided the filename for deleting webhook
            $fileName = sanitizeFileName($text);
            $filePath = $userDir . $fileName;

            // Check if the file exists
            if (!file_exists($filePath)) {
                sendMessage($chatId, "❌ File `<b>{$fileName}</b>` not found in your directory. Please ensure you've uploaded the correct file using the `/upload` command.", $replyToMessageId);
                setUserState($userId, 'none');
                // Optionally, delete the stored bot token
                unlink(STATES_DIR . $userId . '_bot_token.txt');
                break;
            }

            // Retrieve the bot token
            $botToken = trim(file_get_contents(STATES_DIR . $userId . '_bot_token.txt'));
            // Delete the temporary bot token file
            unlink(STATES_DIR . $userId . '_bot_token.txt');

            // Delete the webhook via Telegram API
            $deleteWebhookResponse = deleteWebhook($botToken);

            // Sanitize and format the JSON response
            $sanitizedResponse = sanitizeJsonResponse($deleteWebhookResponse);
            $formattedJson = formatJsonResponse($sanitizedResponse);
            $jsonResponse = htmlspecialchars($formattedJson, ENT_QUOTES, 'UTF-8');

            if ($deleteWebhookResponse['ok']) {
                $successMessage = "✅ Webhook deleted successfully for your bot.\n\n📄 **Telegram API Response:**\n```json\n{$jsonResponse}\n```";
                sendJsonResponse($chatId, $successMessage, $replyToMessageId);
            } else {
                $errorMessage = "❌ Failed to delete webhook.\n\n🔍 **Telegram API Response:**\n```json\n{$jsonResponse}\n```";
                sendJsonResponse($chatId, $errorMessage, $replyToMessageId);
            }

            // Reset the user's state
            setUserState($userId, 'none');
            break;

        case 'awaiting_delete_filename':
            // Handle filename input for /delete command
            $fileName = sanitizeFileName($text);
            $filePath = $userDir . $fileName;

            // Check if the file exists
            if (!file_exists($filePath)) {
                sendMessage($chatId, "❌ File `<b>{$fileName}</b>` not found in your directory.", $replyToMessageId);
            } else {
                // Attempt to delete the file
                if (unlink($filePath)) {
                    sendMessage($chatId, "✅ File `<b>{$fileName}</b>` deleted successfully from your directory.", $replyToMessageId);
                } else {
                    sendMessage($chatId, "❌ Error: Unable to delete `<b>{$fileName}</b>`. Please check file permissions.", $replyToMessageId);
                    error_log("[" . date('Y-m-d H:i:s') . "] Failed to delete file: {$filePath}");
                }
            }

            // Reset the user's state
            setUserState($userId, 'none');
            break;

        default:
            // Handle commands
            switch (strtolower($text)) {
                case '/start':
                    $welcomeMessage = "👋 *Welcome to the File Hosting Bot!*\n\n" .
                                      "📂 *Your directory has been set up.*\n\n" .
                                      "🔹 *Available Commands:*\n" .
                                      "/list - List your files\n" .
                                      "/upload - Upload a file\n" .
                                      "/delete - Delete a file\n" .
                                      "/webhook - Set your bot's webhook URL\n" .
                                      "/getwebhookinfo - Get information about your bot's webhook\n" .
                                      "/deletewebhook - Delete your bot's webhook";
                    sendMessage($chatId, $welcomeMessage, $replyToMessageId);
                    break;

                case '/list':
                    $fileList = listUserFiles($userDir);
                    sendMessage($chatId, $fileList, $replyToMessageId);
                    break;

                case '/upload':
                    sendMessage($chatId, "📤 *Please send me a file to upload to your directory.*\n\n⚠️ *Ensure your file is under " . formatBytes(MAX_FILE_SIZE) . " and you have not exceeded the maximum of " . MAX_FILES_PER_USER . " files.*", $replyToMessageId);
                    break;

                case '/delete':
                    $fileList = listUserFiles($userDir);
                    if ($fileList !== "📁 No files found in your directory.") {
                        $prompt = "🗑️ *Please reply with the exact filename you want to delete from your directory:*\n\n" . $fileList;
                        sendMessage($chatId, $prompt, $replyToMessageId);
                        setUserState($userId, 'awaiting_delete_filename');
                    } else {
                        sendMessage($chatId, $fileList, $replyToMessageId);
                    }
                    break;

                case '/webhook':
                    sendMessage($chatId, "🔧 *Let's set up your webhook!*\n\n1️⃣ Please provide your Telegram Bot Token.\n\n*Your Bot Token looks like this:* `123456789:ABCdefGhIJKlmNoPQRsTuvWxYz`", $replyToMessageId);
                    setUserState($userId, 'awaiting_webhook_token');
                    break;

                case '/getwebhookinfo':
                    sendMessage($chatId, "🔍 *Let's retrieve your webhook information!*\n\n1️⃣ Please provide your Telegram Bot Token.\n\n*Your Bot Token looks like this:* `123456789:ABCdefGhIJKlmNoPQRsTuvWxYz`", $replyToMessageId);
                    setUserState($userId, 'awaiting_getwebhookinfo_token');
                    break;

                case '/deletewebhook':
                    sendMessage($chatId, "🗑️ *Let's delete your webhook!*\n\n1️⃣ Please provide your Telegram Bot Token.\n\n*Your Bot Token looks like this:* `123456789:ABCdefGhIJKlmNoPQRsTuvWxYz`", $replyToMessageId);
                    setUserState($userId, 'awaiting_deletewebhook_token');
                    break;

                default:
                    sendMessage($chatId, "❓ *Unknown command.* Please use `/list`, `/upload`, `/delete`, `/webhook`, `/getwebhookinfo`, or `/deletewebhook`.", $replyToMessageId);
                    break;
            }
            break;
    }
}

// Handle document uploads (file uploads)
if (isset($message['document'])) {
    $document = $message['document'];
    $fileName = sanitizeFileName($document['file_name']);
    $fileId = $document['file_id'];
    $fileSize = $document['file_size'];

    // Validate the file size
    if ($fileSize > MAX_FILE_SIZE) {
        sendMessage($chatId, "❌ *Error:* File too large. Maximum allowed size is " . formatBytes(MAX_FILE_SIZE) . ".", $replyToMessageId);
        exit;
    }

    // Count the current number of files the user has
    $currentFileCount = count(array_filter(scandir($userDir), function($file) use ($userDir) {
        return is_file($userDir . $file);
    }));

    if ($currentFileCount >= MAX_FILES_PER_USER) {
        sendMessage($chatId, "⚠️ *Upload limit reached.* You can only have up to " . MAX_FILES_PER_USER . " files in your directory.\n\n🗑️ Please delete some files using the `/delete` command before uploading new ones.", $replyToMessageId);
        exit;
    }

    // Download the file content
    $fileContent = downloadFile($fileId);

    if ($fileContent === false) {
        sendMessage($chatId, "❌ *Error:* Failed to download the file.", $replyToMessageId);
        exit;
    }

    // Define the path to save the uploaded file
    $filePath = $userDir . $fileName;

    // Check for filename conflicts
    if (file_exists($filePath)) {
        sendMessage($chatId, "⚠️ *A file named `<b>{$fileName}</b>` already exists in your directory.* Please delete it first before uploading.", $replyToMessageId);
        exit;
    }

    // Save the file to the user's directory with secure permissions
    if (file_put_contents($filePath, $fileContent) !== false) {
        // Set file permissions to read and write for the owner only
        chmod($filePath, 0600);
        sendMessage($chatId, "✅ *File `<b>{$fileName}</b>` uploaded successfully!*\n\n🔗 Use the `/webhook` command to set your webhook URL, specifying this filename.", $replyToMessageId);
    } else {
        sendMessage($chatId, "❌ *Error:* Failed to save the uploaded file.", $replyToMessageId);
        error_log("[" . date('Y-m-d H:i:s') . "] Failed to save file: {$filePath}");
    }
}

// Optionally, handle photo uploads or other types if needed (optional)

// Log the processed update for monitoring
if (isset($userId)) {
    error_log("[" . date('Y-m-d H:i:s') . "] [User ID: {$userId}] Processed update.");
}

exit;
?>
