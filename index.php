<?php
/**
 * REST API для мобильных приложений Android и iOS
 * Единая точка входа для всех API запросов
 * 
 * Формат запроса: POST /api-app/index.php
 * Content-Type: application/json
 * 
 * Body: {
 *   "action": "название_действия",
 *   "params": { ... параметры ... }
 * }
 */

// Буферизация вывода для предотвращения преждевременного вывода
ob_start();

// Логирование в собственный файл для удобной отладки
ini_set('error_log', __DIR__ . '/api_debug.log');

// Отключение вывода ошибок напрямую (они будут в JSON)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Обработчик ошибок - возвращаем JSON вместо HTML
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (ob_get_level()) ob_end_clean();
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Внутренняя ошибка сервера',
        'error' => $errstr,
        'file' => basename($errfile),
        'line' => $errline,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Обработчик исключений
set_exception_handler(function($exception) {
    if (ob_get_level()) ob_end_clean();
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Внутренняя ошибка сервера',
        'error' => $exception->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// CORS заголовки для мобильных приложений (устанавливаем ДО любого вывода)
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Device-ID, X-Device-Type, X-Device-Name');
    header('Content-Type: application/json; charset=utf-8');
}

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit;
}

// Обработка GET запросов - перенаправляем на визуальный тест
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Location: test_api_visual.php');
    exit;
}

// Подключение конфигурации (используем отдельный конфиг для API)
try {
    require_once __DIR__ . '/config.php';
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка конфигурации',
        'error' => 'Не удалось подключить config.php: ' . $e->getMessage(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// API настройки
define('API_VERSION', '1.0.0');
define('API_TOKEN_EXPIRY', 86400 * 30); // 30 дней
define('API_RATE_LIMIT', 200); // запросов в минуту
define('API_RATE_PERIOD', 60); // секунд

/**
 * Создание таблицы API токенов (вызывается по требованию)
 */
function ensureAPITokensTable() {
    static $created = false;
    if ($created) return true;
    
    try {
        $conn = getDBConnection();
        
        // Сначала проверим, существует ли таблица
        $check = @$conn->query("SHOW TABLES LIKE 'api_tokens'");
        if ($check && $check->num_rows > 0) {
            $created = true;
            return true;
        }
        
        // Создаём таблицу - УПРОЩЁННАЯ версия без индексов
        $sql = "CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            device_type VARCHAR(20) DEFAULT 'android',
            device_id VARCHAR(255) DEFAULT NULL,
            device_name VARCHAR(255) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $result = $conn->query($sql);
        if (!$result) {
            error_log("CRITICAL: Failed to create api_tokens table: " . $conn->error);
            
            // Пробуем создать ещё более простую версию
            $sql_simple = "CREATE TABLE IF NOT EXISTS api_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL,
                device_type VARCHAR(20),
                device_id VARCHAR(255),
                device_name VARCHAR(255),
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $result = $conn->query($sql_simple);
            if (!$result) {
                error_log("CRITICAL: Even simple table creation failed: " . $conn->error);
                return false;
            }
        }
        
        // Добавляем уникальный индекс для токена отдельно
        @$conn->query("ALTER TABLE api_tokens ADD UNIQUE KEY unique_token (token)");
        
        $created = true;
        return true;
        
    } catch (Exception $e) {
        error_log("EXCEPTION creating api_tokens table: " . $e->getMessage());
        return false;
    }
}

/**
 * Создание таблицы корзины (вызывается по требованию)
 */
function ensureCartItemsTable() {
    static $created = false;
    if ($created) return;
    
    try {
        $conn = getDBConnection();
        $sql = "CREATE TABLE IF NOT EXISTS cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            dish_id INT NOT NULL,
            size_id INT NULL,
            quantity INT NOT NULL DEFAULT 1,
            weight INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_dish (dish_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $result = @$conn->query($sql);
        if (!$result) {
            error_log("Error creating cart_items table: " . $conn->error);
        }
        // Don't close the connection - it's a singleton used throughout the request
        $created = true;
    } catch (Exception $e) {
        error_log("Error creating cart_items table: " . $e->getMessage());
    }
}

/**
 * Создание/проверка таблицы order_items
 */
function ensureOrderItemsTable() {
    static $created = false;
    if ($created) return true;
    
    try {
        $conn = getDBConnection();
        
        // Проверяем существование таблицы order_items
        $check = @$conn->query("SHOW TABLES LIKE 'order_items'");
        if ($check && $check->num_rows > 0) {
            $created = true;
            return true;
        }
        
        // Создаем таблицу order_items
        $sql = "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            dish_id INT NOT NULL,
            size_id INT NULL,
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            weight INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id),
            INDEX idx_dish (dish_id),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $result = @$conn->query($sql);
        if (!$result) {
            error_log("Error creating order_items table: " . $conn->error);
            return false;
        }
        
        error_log("SUCCESS: order_items table created successfully");
        $created = true;
        return true;
    } catch (Exception $e) {
        error_log("Error creating order_items table: " . $e->getMessage());
        return false;
    }
}

/**
 * Проверка существования таблиц заказов
 */
function ensureOrderTablesExist() {
    static $checked = false;
    if ($checked) return true;
    
    try {
        $conn = getDBConnection();
        
        // Проверяем существование таблицы orders
        $check = @$conn->query("SHOW TABLES LIKE 'orders'");
        if (!$check || $check->num_rows === 0) {
            error_log("WARNING: orders table does not exist");
            return false;
        }
        
        // Создаем таблицу order_items если её нет
        ensureOrderItemsTable();
        
        $checked = true;
        return true;
    } catch (Exception $e) {
        error_log("Error checking order tables: " . $e->getMessage());
        return false;
    }
}

/**
 * Отправка JSON ответа
 */
function apiResponse($success, $data = null, $message = '', $code = 200) {
    // Очищаем буфер вывода если что-то там было
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($code);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => time(),
        'version' => API_VERSION
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Ошибка API
 */
function apiError($message, $code = 400, $details = null) {
    $data = $details ? ['details' => $details] : null;
    apiResponse(false, $data, $message, $code);
}

/**
 * Успех API
 */
function apiSuccess($data = null, $message = 'Success', $code = 200) {
    apiResponse(true, $data, $message, $code);
}

// ==============================
// Image URL helper utilities
// ==============================
/**
 * Returns canonical base URL for building absolute links
 */
function getBaseUrl() {
    // Force HTTPS canonical host to avoid mixed content issues in apps
    return 'https://ryabokonov.site';
}

/**
 * Returns project root path segment (leading slash, no trailing slash)
 */
function getProjectPath() {
    return '/tokio';
}

/**
 * Normalizes a filename coming from DB and applies a default placeholder
 */
function normalizeImageFilename($filename, $default = 'placeholder.jpg') {
    if (!$filename) return $default;
    $trimmed = trim($filename);
    if ($trimmed === '') return $default;
    // Prevent directory traversal and remove any path prefixes
    $basename = basename($trimmed);
    return $basename ?: $default;
}

/**
 * Builds an absolute image URL
 * $type: 'product' | 'promotion'
 * $size: 'full' | 'thumbnail' (promotions currently only support 'full')
 */
function buildImageUrl($filename, $type = 'product', $size = 'full') {
    // Allow passing absolute URLs directly (stored in DB)
    if (is_string($filename) && preg_match('~^https?://~i', $filename)) {
        return $filename;
    }

    $name = normalizeImageFilename($filename);
    $effectiveName = chooseImageFilenameForUrl($name, $type, $size);

    $segment = 'images';
    if ($type === 'promotion') {
        $segment = 'images/promotions';
    } else { // product images
        if ($size === 'thumbnail') {
            $segment = 'images/thumbnails';
        }
    }

    // rawurlencode for safe transport (keeps spaces as %20 etc.)
    return getBaseUrl() . getProjectPath() . '/' . $segment . '/' . rawurlencode($effectiveName);
}

/**
 * Convenience wrapper that returns both full and thumbnail URLs for products
 */
function buildProductImageUrls($filename) {
    // If thumbnail is missing on disk, gracefully fall back to full
    $full = buildImageUrl($filename, 'product', 'full');
    $thumbCandidate = normalizeImageFilename($filename);
    $thumbExists = imageFileExistsOnDisk($thumbCandidate, 'product', 'thumbnail');
    $thumb = $thumbExists ? buildImageUrl($filename, 'product', 'thumbnail') : $full;
    return [
        'image_url' => $full,
        'thumbnail_url' => $thumb
    ];
}

// ==============================
// Filesystem helpers for images
// ==============================
function getImagesBaseDir() {
    // api-app is sibling to images directory
    $path = __DIR__ . '/../images';
    return $path;
}

function getImagesDirFor($type = 'product', $size = 'full') {
    $base = rtrim(getImagesBaseDir(), '/');
    if ($type === 'promotion') {
        return $base . '/promotions';
    }
    if ($size === 'thumbnail') {
        return $base . '/thumbnails';
    }
    return $base; // products full
}

function imageFileExistsOnDisk($filename, $type = 'product', $size = 'full') {
    $dir = getImagesDirFor($type, $size);
    $path = $dir . '/' . basename($filename);
    // Suppress open_basedir warnings if configured; just return false in that case
    return @is_file($path);
}

function chooseImageFilenameForUrl($filename, $type = 'product', $size = 'full') {
    $name = normalizeImageFilename($filename);
    // If the requested variant exists, use it
    if (imageFileExistsOnDisk($name, $type, $size)) {
        return $name;
    }
    // If a thumbnail was requested but missing, try full-size
    if ($type !== 'promotion' && $size === 'thumbnail' && imageFileExistsOnDisk($name, 'product', 'full')) {
        return $name;
    }
    // Try placeholder (prefer matching size, then full)
    $placeholder = 'placeholder.jpg';
    if (imageFileExistsOnDisk($placeholder, $type, $size)) {
        return $placeholder;
    }
    if (imageFileExistsOnDisk($placeholder, $type, 'full')) {
        return $placeholder;
    }
    // Fall back to original name even if missing to avoid breaking schema
    return $name;
}

/**
 * Генерация API токена
 */
function generateAPIToken($user_id, $device_type = 'android', $device_id = null, $device_name = null) {
    error_log("generateAPIToken: START for user_id=$user_id, device_type=$device_type");
    
    // Создаем таблицу токенов если не существует
    if (!ensureAPITokensTable()) {
        error_log("CRITICAL: Cannot generate token - api_tokens table creation failed");
        return null;
    }
    
    error_log("generateAPIToken: api_tokens table ensured");
    
    $conn = getDBConnection();
    
    // Генерация уникального токена
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + API_TOKEN_EXPIRY);
    
    error_log("generateAPIToken: Token generated, expires_at=$expires_at");
    
    // Удаление старых токенов этого устройства
    if ($device_id) {
        $stmt = $conn->prepare("DELETE FROM api_tokens WHERE user_id = ? AND device_id = ?");
        if ($stmt === false) {
            error_log("WARNING: Failed to prepare DELETE statement: " . $conn->error);
        } else {
            $stmt->bind_param("is", $user_id, $device_id);
            $deleted = $stmt->execute();
            $affected = $stmt->affected_rows;
            error_log("generateAPIToken: Deleted old tokens for device, affected rows: $affected");
            $stmt->close();
        }
    }
    
    // Создание нового токена
    error_log("generateAPIToken: Preparing INSERT statement...");
    $stmt = $conn->prepare("INSERT INTO api_tokens (user_id, token, device_type, device_id, device_name, expires_at) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt === false) {
        error_log("CRITICAL: Failed to prepare INSERT token statement: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("isssss", $user_id, $token, $device_type, $device_id, $device_name, $expires_at);
    
    error_log("generateAPIToken: Executing INSERT...");
    if ($stmt->execute()) {
        $insert_id = $conn->insert_id;
        error_log("generateAPIToken: SUCCESS! Token saved to DB with ID=$insert_id");
        
        // Проверяем что токен действительно сохранился
        $verify_stmt = $conn->prepare("SELECT id FROM api_tokens WHERE token = ? LIMIT 1");
        $verify_stmt->bind_param("s", $token);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        if ($verify_result->num_rows > 0) {
            error_log("generateAPIToken: VERIFIED - Token found in DB");
        } else {
            error_log("generateAPIToken: WARNING - Token NOT found in DB after insert!");
        }
        $verify_stmt->close();
        
        logSecurityEvent($user_id, 'api_token_created', "Device: $device_type ($device_name)");
        $stmt->close();
        // Don't close the connection - it's a singleton used throughout the request
        return [
            'token' => $token,
            'expires_at' => $expires_at,
            'expires_in' => API_TOKEN_EXPIRY
        ];
    }
    
    error_log("ERROR: Failed to execute INSERT token: " . $stmt->error);
    $stmt->close();
    // Don't close the connection - it's a singleton used throughout the request
    return null;
}

/**
 * Проверка API токена
 */
function verifyAPIToken($token) {
    if (empty($token)) {
        error_log("verifyAPIToken: Token is empty");
        return null;
    }
    
    error_log("verifyAPIToken: Checking token: " . substr($token, 0, 20) . "...");
    
    $conn = getDBConnection();
    
    // Проверяем существование таблицы api_tokens
    $check = @$conn->query("SHOW TABLES LIKE 'api_tokens'");
    if (!$check || $check->num_rows === 0) {
        error_log("verifyAPIToken: Table api_tokens does not exist!");
        return null;
    }
    
    // Проверка токена и срока действия
    $stmt = $conn->prepare("SELECT t.user_id, u.username, u.email, u.full_name, u.phone, u.address, u.is_admin 
                           FROM api_tokens t
                           JOIN users u ON t.user_id = u.id
                           WHERE t.token = ? AND t.expires_at > NOW()
                           LIMIT 1");
    
    if (!$stmt) {
        error_log("verifyAPIToken: Failed to prepare statement: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        error_log("verifyAPIToken: Token valid, user_id=" . $user['user_id']);
        $stmt->close();
        // Don't close the connection - it's a singleton used throughout the request
        return $user;
    }
    
    error_log("verifyAPIToken: Token not found or expired");
    $stmt->close();
    // Don't close the connection - it's a singleton used throughout the request
    return null;
}

/**
 * Получение всех заголовков для API (совместимая версия)
 */
function getAPIHeaders() {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    
    // Fallback для серверов без getallheaders()
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$header_name] = $value;
        }
    }
    return $headers;
}

/**
 * Получение токена из заголовка
 */
function getAPIToken() {
    $headers = getAPIHeaders();
    
    error_log("getAPIToken: Headers received: " . json_encode(array_keys($headers)));
    
    // НОВОЕ: Проверка токена в параметрах (workaround для серверов которые не передают Authorization)
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (isset($data['token'])) {
        error_log("getAPIToken: Found token in request params");
        return $data['token'];
    }
    
    // Проверка Authorization заголовка
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        error_log("getAPIToken: Found Authorization header: " . substr($auth, 0, 30) . "...");
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            error_log("getAPIToken: Extracted token from Authorization");
            return $matches[1];
        }
    }
    
    // Проверка X-API-Key заголовка
    if (isset($headers['X-Api-Key'])) {
        error_log("getAPIToken: Found X-Api-Key header");
        return $headers['X-Api-Key'];
    }
    
    // Проверка X-API-Token заголовка
    if (isset($headers['X-Api-Token'])) {
        error_log("getAPIToken: Found X-Api-Token header");
        return $headers['X-Api-Token'];
    }
    
    // Альтернативная проверка через $_SERVER
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        error_log("getAPIToken: Found HTTP_AUTHORIZATION in _SERVER");
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            error_log("getAPIToken: Extracted token from _SERVER");
            return $matches[1];
        }
    }
    
    // Проверка через X-API-KEY в $_SERVER
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        error_log("getAPIToken: Found HTTP_X_API_KEY in _SERVER");
        return $_SERVER['HTTP_X_API_KEY'];
    }
    
    // Проверка через X-API-TOKEN в $_SERVER
    if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
        error_log("getAPIToken: Found HTTP_X_API_TOKEN in _SERVER");
        return $_SERVER['HTTP_X_API_TOKEN'];
    }
    
    error_log("getAPIToken: No token found in any header or params");
    return null;
}

/**
 * Требование аутентификации
 */
function requireAuth() {
    $token = getAPIToken();
    $user = verifyAPIToken($token);
    
    if (!$user) {
        apiError('Требуется авторизация. Пожалуйста, войдите в систему.', 401);
    }
    
    return $user;
}

/**
 * Получение JSON из запроса
 */
function getJSONInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE && !empty($input)) {
        apiError('Неверный формат JSON', 400);
    }
    
    return $data ? $data : [];
}

/**
 * Получение информации об устройстве из заголовков
 */
function getDeviceInfo() {
    $headers = getAPIHeaders();
    
    $device_type = 'unknown';
    if (isset($headers['X-Device-Type'])) {
        $device_type = $headers['X-Device-Type'];
    } elseif (isset($_SERVER['HTTP_X_DEVICE_TYPE'])) {
        $device_type = $_SERVER['HTTP_X_DEVICE_TYPE'];
    }
    
    $device_id = null;
    if (isset($headers['X-Device-Id'])) {
        $device_id = $headers['X-Device-Id'];
    } elseif (isset($_SERVER['HTTP_X_DEVICE_ID'])) {
        $device_id = $_SERVER['HTTP_X_DEVICE_ID'];
    }
    
    $device_name = null;
    if (isset($headers['X-Device-Name'])) {
        $device_name = $headers['X-Device-Name'];
    } elseif (isset($_SERVER['HTTP_X_DEVICE_NAME'])) {
        $device_name = $_SERVER['HTTP_X_DEVICE_NAME'];
    }
    
    return [
        'device_type' => $device_type,
        'device_id' => $device_id,
        'device_name' => $device_name
    ];
}

/**
 * API Rate Limiting (упрощенная версия без сессий)
 */
function checkAPIRateLimit() {
    // Для упрощения - пропускаем rate limiting
    // В production можно использовать Redis или Memcached
    return true;
}

// Получение входных данных
$input = getJSONInput();
$action = '';
if (isset($input['action'])) {
    $action = $input['action'];
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];
}
$params = isset($input['params']) ? $input['params'] : [];

// ============================================
// ОБРАБОТЧИКИ ДЕЙСТВИЙ
// ============================================

switch ($action) {
    
    // ========== ИНФОРМАЦИЯ ОБ API ==========
    case 'info':
        apiSuccess([
            'name' => 'Tokio Food Delivery API',
            'version' => API_VERSION,
            'description' => 'REST API для мобильных приложений Android и iOS',
            'server_time' => date('Y-m-d H:i:s'),
            'endpoints' => [
                'auth' => ['login', 'register', 'logout', 'verify'],
                'catalog' => ['categories', 'dishes', 'dish_details'],
                'cart' => ['get_cart', 'add_to_cart', 'update_cart', 'remove_from_cart', 'clear_cart'],
                'orders' => ['create_order', 'get_orders', 'order_details', 'cancel_order'],
                'profile' => ['get_profile', 'update_profile', 'change_password'],
                'promo' => ['validate_promo', 'get_active_promotions', 'promotion_details'],
                'delivery' => ['get_delivery_types', 'calculate_delivery']
            ]
        ], 'Добро пожаловать в Tokio Food Delivery API');
        break;

    // ========== АУТЕНТИФИКАЦИЯ ==========
    
    case 'login':
        // Логин пользователя
        $username = isset($params['username']) ? trim($params['username']) : '';
        $password = isset($params['password']) ? $params['password'] : '';
        $device_info = getDeviceInfo();
        
        if (empty($username) || empty($password)) {
            apiError('Укажите логин и пароль', 400);
        }
        
        // Проверка блокировки
        if (!checkLoginAttempts($username)) {
            apiError('Аккаунт временно заблокирован из-за многократных неудачных попыток входа. Попробуйте позже.', 423);
        }
        
        $conn = getDBConnection();
        
        // Простой запрос как в login.php
        $stmt = $conn->prepare("SELECT id, username, email, full_name, phone, address, password, is_admin 
                               FROM users 
                               WHERE username = ? OR email = ? 
                               LIMIT 1");
        
        if (!$stmt) {
            $error = $conn->error;
            // Don't close the connection - it's a singleton used throughout the request
            apiError('Ошибка БД: ' . $error, 500);
        }
        
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                // Успешный вход
                resetLoginAttempts($username);
                
                // Генерация токена
                $token_data = generateAPIToken(
                    $user['id'], 
                    $device_info['device_type'],
                    $device_info['device_id'],
                    $device_info['device_name']
                );
                
                // Проверка успешности создания токена (с fallback)
                if ($token_data === null) {
                    // Если не удалось сохранить в БД, используем временный токен
                    error_log("WARNING: Failed to save token to DB for user " . $user['id'] . ", using temporary token");
                    $temp_token = bin2hex(random_bytes(32));
                    $token_data = [
                        'token' => $temp_token,
                        'expires_at' => date('Y-m-d H:i:s', time() + API_TOKEN_EXPIRY),
                        'expires_in' => API_TOKEN_EXPIRY
                    ];
                }
                
                logSecurityEvent($user['id'], 'api_login_success', "Device: {$device_info['device_type']}");
                
                $stmt->close();
                // Don't close the connection - it's a singleton used throughout the request
                
                apiSuccess([
                    'user' => [
                        'id' => (int)$user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'full_name' => $user['full_name'],
                        'phone' => $user['phone'],
                        'address' => $user['address'],
                        'is_admin' => (bool)$user['is_admin']
                    ],
                    'token' => $token_data['token'],
                    'expires_at' => $token_data['expires_at'],
                    'expires_in' => $token_data['expires_in']
                ], 'Вход выполнен успешно');
            }
        }
        
        // Неудачная попытка
        registerFailedLogin($username);
        $stmt->close();
        // Don't close the connection - it's a singleton used throughout the request
        apiError('Неверный логин или пароль', 401);
        break;

    case 'register':
        // Регистрация нового пользователя
        $username = isset($params['username']) ? trim($params['username']) : '';
        $email = isset($params['email']) ? trim($params['email']) : '';
        $password = isset($params['password']) ? $params['password'] : '';
        $full_name = isset($params['full_name']) ? trim($params['full_name']) : '';
        $phone = isset($params['phone']) ? trim($params['phone']) : '';
        $device_info = getDeviceInfo();
        
        // Валидация
        if (empty($username) || empty($email) || empty($password)) {
            apiError('Заполните все обязательные поля: логин, email, пароль', 400);
        }
        
        if (!validateEmail($email)) {
            apiError('Неверный формат email', 400);
        }
        
        if (strlen($username) < 3) {
            apiError('Логин должен содержать минимум 3 символа', 400);
        }
        
        if (strlen($password) < 6) {
            apiError('Пароль должен содержать минимум 6 символов', 400);
        }
        
        $conn = getDBConnection();
        
        // Проверка существования - как в register.php
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        
        if (!$stmt) {
            $error = $conn->error;
            // Connection is a singleton, don't close it
            apiError('Ошибка БД: ' . $error, 500);
        }
        
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Пользователь с таким логином или email уже существует', 409);
        }
        $stmt->close();
        
        // Создание пользователя - как в register.php
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, phone) 
                               VALUES (?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            $error = $conn->error;
            // Connection is a singleton, don't close it
            apiError('Ошибка БД: ' . $error, 500);
        }
        
        $stmt->bind_param("sssss", $username, $email, $password_hash, $full_name, $phone);
        
        if ($stmt->execute()) {
            $user_id = $conn->insert_id;
            
            // Генерация токена
            $token_data = generateAPIToken(
                $user_id,
                $device_info['device_type'],
                $device_info['device_id'],
                $device_info['device_name']
            );
            
            logSecurityEvent($user_id, 'user_registered_api', "Username: $username, Device: {$device_info['device_type']}");
            
            $stmt->close();
            // Connection is a singleton, don't close it
            
            apiSuccess([
                'user' => [
                    'id' => $user_id,
                    'username' => $username,
                    'email' => $email,
                    'full_name' => $full_name,
                    'phone' => $phone
                ],
                'token' => $token_data['token'],
                'expires_at' => $token_data['expires_at'],
                'expires_in' => $token_data['expires_in']
            ], 'Регистрация успешна', 201);
        } else {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Ошибка при создании аккаунта', 500);
        }
        break;

    case 'logout':
        // Выход из системы
        $user = requireAuth();
        $token = getAPIToken();
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM api_tokens WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $stmt->close();
        // Connection is a singleton, don't close it
        
        logSecurityEvent($user['user_id'], 'api_logout', 'User logged out');
        apiSuccess(null, 'Выход выполнен успешно');
        break;

    case 'verify':
        // Проверка токена
        $user = requireAuth();
        apiSuccess([
            'user' => [
                'id' => (int)$user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'phone' => $user['phone'],
                'address' => $user['address'],
                'is_admin' => (bool)$user['is_admin']
            ]
        ], 'Токен действителен');
        break;

    // ========== КАТАЛОГ ==========
    
    case 'categories':
        // Получение списка категорий
        $conn = getDBConnection();
        
        $query = "SELECT c.id, c.name, c.description, c.parent_id,
                  COUNT(DISTINCT d.id) as dishes_count,
                  COUNT(DISTINCT CASE WHEN d.is_available = 1 THEN d.id END) as available_dishes
                  FROM categories c
                  LEFT JOIN dishes d ON c.id = d.category_id
                  GROUP BY c.id
                  ORDER BY c.parent_id IS NULL DESC, c.name";
        
        $result = $conn->query($query);
        $categories = [];
        
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'parent_id' => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'dishes_count' => (int)$row['dishes_count'],
                'available_dishes' => (int)$row['available_dishes']
            ];
        }
        
        // Connection is a singleton, don't close it
        apiSuccess(['categories' => $categories]);
        break;

    case 'dishes':
        // Получение списка блюд с фильтрацией
        $category_id = isset($params['category_id']) ? (int)$params['category_id'] : null;
        $search = isset($params['search']) ? trim($params['search']) : null;
        $available_only = isset($params['available_only']) ? (bool)$params['available_only'] : true;
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $limit = isset($params['limit']) ? min(100, max(1, (int)$params['limit'])) : 20;
        $offset = ($page - 1) * $limit;
        
        $conn = getDBConnection();
        
        // Построение WHERE условий
        $where = [];
        $query_params = [];
        $types = '';
        
        if ($category_id) {
            $where[] = "d.category_id = ?";
            $query_params[] = $category_id;
            $types .= 'i';
        }
        
        if ($available_only) {
            $where[] = "d.is_available = 1";
        }
        
        if ($search) {
            $where[] = "(d.name LIKE ? OR d.description LIKE ?)";
            $search_term = "%$search%";
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $types .= 'ss';
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Подсчет общего количества
        $count_query = "SELECT COUNT(*) as total FROM dishes d $where_sql";
        
        if (!empty($query_params)) {
            $stmt = $conn->prepare($count_query);
            $stmt->bind_param($types, ...$query_params);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $total = $conn->query($count_query)->fetch_assoc()['total'];
        }
        
        // Получение блюд
        $dishes_query = "SELECT d.id, d.name, d.description, d.price, d.weight, d.image, d.is_available,
                         u.name as unit_name, u.abbreviation as unit_abbr,
                         c.id as category_id, c.name as category_name
                         FROM dishes d
                         LEFT JOIN categories c ON d.category_id = c.id
                         LEFT JOIN units u ON d.unit_id = u.id
                         $where_sql
                         ORDER BY d.category_id, d.name
                         LIMIT $limit OFFSET $offset";
        
        if (!empty($query_params)) {
            $stmt = $conn->prepare($dishes_query);
            $stmt->bind_param($types, ...$query_params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conn->query($dishes_query);
        }
        
        $dishes = [];
        
        while ($row = $result->fetch_assoc()) {
            $image = !empty($row['image']) ? $row['image'] : 'placeholder.jpg';
            
            // Получение размеров блюда
            $sizes_stmt = $conn->prepare("SELECT s.id, s.name, ds.weight, ds.price_modifier 
                                          FROM dish_sizes ds 
                                          JOIN sizes s ON ds.size_id = s.id 
                                          WHERE ds.dish_id = ?
                                          ORDER BY s.sort_order, s.name");
            $sizes_stmt->bind_param("i", $row['id']);
            $sizes_stmt->execute();
            $sizes_result = $sizes_stmt->get_result();
            $sizes = [];
            while ($size = $sizes_result->fetch_assoc()) {
                $sizes[] = [
                    'id' => (int)$size['id'],
                    'name' => $size['name'],
                    'weight' => $size['weight'] ? (int)$size['weight'] : null,
                    'price_modifier' => (float)$size['price_modifier'],
                    'final_price' => (float)($row['price'] + $size['price_modifier'])
                ];
            }
            $sizes_stmt->close();
            
            // Получение ингредиентов
            $ing_stmt = $conn->prepare("SELECT i.id, i.name, di.quantity 
                                        FROM dish_ingredients di 
                                        JOIN ingredients i ON di.ingredient_id = i.id 
                                        WHERE di.dish_id = ?
                                        ORDER BY i.name");
            $ing_stmt->bind_param("i", $row['id']);
            $ing_stmt->execute();
            $ing_result = $ing_stmt->get_result();
            $ingredients = [];
            while ($ing = $ing_result->fetch_assoc()) {
                $ingredients[] = [
                    'id' => (int)$ing['id'],
                    'name' => $ing['name'],
                    'quantity' => $ing['quantity'] ? (int)$ing['quantity'] : null
                ];
            }
            $ing_stmt->close();
            
            $img = normalizeImageFilename($image);
            $imgUrls = buildProductImageUrls($img);
            $dishes[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => (float)$row['price'],
                'weight' => $row['weight'] ? (int)$row['weight'] : null,
                'unit' => $row['unit_name'] ? [
                    'name' => $row['unit_name'],
                    'abbreviation' => $row['unit_abbr']
                ] : null,
                'sizes' => $sizes,
                'ingredients' => $ingredients,
                'image' => $imgUrls['image_url'],
                'image_url' => $imgUrls['image_url'],
                'thumbnail_url' => $imgUrls['thumbnail_url'],
                'is_available' => (bool)$row['is_available'],
                'category' => [
                    'id' => $row['category_id'] ? (int)$row['category_id'] : null,
                    'name' => $row['category_name']
                ]
            ];
        }
        
        if (isset($stmt)) $stmt->close();
        // Connection is a singleton, don't close it
        
        apiSuccess([
            'dishes' => $dishes,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => (int)$total,
                'total_pages' => ceil($total / $limit),
                'has_more' => $page < ceil($total / $limit)
            ]
        ]);
        break;

    case 'dish_details':
        // Детальная информация о блюде
        $dish_id = isset($params['dish_id']) ? (int)$params['dish_id'] : 0;
        
        if ($dish_id <= 0) {
            apiError('Укажите ID блюда', 400);
        }
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT d.id, d.name, d.description, d.price, d.weight, d.image, d.is_available,
                               u.name as unit_name, u.abbreviation as unit_abbr,
                               c.id as category_id, c.name as category_name
                               FROM dishes d
                               LEFT JOIN categories c ON d.category_id = c.id
                               LEFT JOIN units u ON d.unit_id = u.id
                               WHERE d.id = ?
                               LIMIT 1");
        $stmt->bind_param("i", $dish_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($dish = $result->fetch_assoc()) {
            $image = !empty($dish['image']) ? $dish['image'] : 'placeholder.jpg';
            $img = normalizeImageFilename($image);
            $imgUrls = buildProductImageUrls($img);
            
            // Получение размеров
            $sizes_stmt = $conn->prepare("SELECT s.id, s.name, s.description, ds.weight, ds.price_modifier 
                                          FROM dish_sizes ds 
                                          JOIN sizes s ON ds.size_id = s.id 
                                          WHERE ds.dish_id = ?
                                          ORDER BY s.sort_order, s.name");
            $sizes_stmt->bind_param("i", $dish['id']);
            $sizes_stmt->execute();
            $sizes_result = $sizes_stmt->get_result();
            $sizes = [];
            while ($size = $sizes_result->fetch_assoc()) {
                $sizes[] = [
                    'id' => (int)$size['id'],
                    'name' => $size['name'],
                    'description' => $size['description'],
                    'weight' => $size['weight'] ? (int)$size['weight'] : null,
                    'price_modifier' => (float)$size['price_modifier'],
                    'final_price' => (float)($dish['price'] + $size['price_modifier'])
                ];
            }
            $sizes_stmt->close();
            
            // Получение ингредиентов
            $ing_stmt = $conn->prepare("SELECT i.id, i.name, i.description, di.quantity 
                                        FROM dish_ingredients di 
                                        JOIN ingredients i ON di.ingredient_id = i.id 
                                        WHERE di.dish_id = ?
                                        ORDER BY i.name");
            $ing_stmt->bind_param("i", $dish['id']);
            $ing_stmt->execute();
            $ing_result = $ing_stmt->get_result();
            $ingredients = [];
            while ($ing = $ing_result->fetch_assoc()) {
                $ingredients[] = [
                    'id' => (int)$ing['id'],
                    'name' => $ing['name'],
                    'description' => $ing['description'],
                    'quantity' => $ing['quantity'] ? (int)$ing['quantity'] : null
                ];
            }
            $ing_stmt->close();
            
            $data = [
                'id' => (int)$dish['id'],
                'name' => $dish['name'],
                'description' => $dish['description'],
                'price' => (float)$dish['price'],
                'weight' => $dish['weight'] ? (int)$dish['weight'] : null,
                'unit' => $dish['unit_name'] ? [
                    'name' => $dish['unit_name'],
                    'abbreviation' => $dish['unit_abbr']
                ] : null,
                'sizes' => $sizes,
                'ingredients' => $ingredients,
                'image' => $imgUrls['image_url'],
                'image_url' => $imgUrls['image_url'],
                'thumbnail_url' => $imgUrls['thumbnail_url'],
                'is_available' => (bool)$dish['is_available'],
                'category' => [
                    'id' => $dish['category_id'] ? (int)$dish['category_id'] : null,
                    'name' => $dish['category_name']
                ]
            ];
            
            $stmt->close();
            // Connection is a singleton, don't close it
            apiSuccess(['dish' => $data]);
        } else {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Блюдо не найдено', 404);
        }
        break;

    // ========== КОРЗИНА (СЕРВЕРНАЯ) ==========
    
    case 'get_cart':
        // Получение корзины пользователя
        // Проверяем авторизацию, но не требуем её обязательно
        $token = getAPIToken();
        $user = verifyAPIToken($token);
        
        // Если пользователь не авторизован - возвращаем пустую корзину
        if (!$user) {
            apiSuccess([
                'cart' => [],
                'total' => 0,
                'items_count' => 0,
                'requires_auth' => true,
                'message' => 'Для синхронизации корзины необходимо авторизоваться'
            ]);
        }
        
        // Создаем таблицу корзины если не существует
        ensureCartItemsTable();
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT ci.*, d.name, d.price, d.image, d.is_available, d.weight as dish_weight,
                               u.abbreviation as unit_abbr, s.name as size_name
                               FROM cart_items ci
                               JOIN dishes d ON ci.dish_id = d.id
                               LEFT JOIN units u ON d.unit_id = u.id
                               LEFT JOIN sizes s ON ci.size_id = s.id
                               WHERE ci.user_id = ?
                               ORDER BY ci.created_at DESC");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        
        $cart_items = [];
        $total = 0;
        
        while ($row = $result->fetch_assoc()) {
            // Пропускаем недоступные блюда
            if (!$row['is_available']) {
                continue;
            }
            
            $price = (float)$row['price'];
            
            // Добавляем модификатор цены если есть размер
            if ($row['size_id']) {
                $size_stmt = $conn->prepare("SELECT price_modifier FROM dish_sizes WHERE dish_id = ? AND size_id = ? LIMIT 1");
                $size_stmt->bind_param("ii", $row['dish_id'], $row['size_id']);
                $size_stmt->execute();
                $size_data = $size_stmt->get_result()->fetch_assoc();
                $size_stmt->close();
                
                if ($size_data) {
                    $price += (float)$size_data['price_modifier'];
                }
            }
            
            $subtotal = $price * $row['quantity'];
            $total += $subtotal;
            
            $image = !empty($row['image']) ? $row['image'] : 'placeholder.jpg';
            $img = normalizeImageFilename($image);
            $imgUrls = buildProductImageUrls($img);
            
            $cart_items[] = [
                'id' => (int)$row['id'],
                'dish_id' => (int)$row['dish_id'],
                'dish_name' => $row['name'],
                'size_id' => $row['size_id'] ? (int)$row['size_id'] : null,
                'size_name' => $row['size_name'],
                'quantity' => (int)$row['quantity'],
                'price' => $price,
                'weight' => $row['weight'] ? (int)$row['weight'] : ($row['dish_weight'] ? (int)$row['dish_weight'] : null),
                'unit_abbr' => $row['unit_abbr'],
                'subtotal' => $subtotal,
                'image' => $imgUrls['image_url'],
                'image_url' => $imgUrls['image_url'],
                'thumbnail_url' => $imgUrls['thumbnail_url'],
                'created_at' => $row['created_at']
            ];
        }
        
        $stmt->close();
        // Connection is a singleton, don't close it
        
        apiSuccess([
            'cart' => $cart_items,
            'total' => $total,
            'items_count' => count($cart_items)
        ]);
        break;

    case 'add_to_cart':
        // Добавление товара в корзину
        $user = requireAuth();
        
        $dish_id = isset($params['dish_id']) ? (int)$params['dish_id'] : 0;
        $quantity = isset($params['quantity']) ? (int)$params['quantity'] : 1;
        $size_id = isset($params['size_id']) ? (int)$params['size_id'] : null;
        $weight = isset($params['weight']) ? (int)$params['weight'] : null;
        
        if ($dish_id <= 0) {
            apiError('Укажите ID блюда', 400);
        }
        
        if ($quantity <= 0) {
            apiError('Количество должно быть больше 0', 400);
        }
        
        $conn = getDBConnection();
        
        // Проверка существования блюда
        $stmt = $conn->prepare("SELECT id, is_available FROM dishes WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $dish_id);
        $stmt->execute();
        $dish = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$dish) {
            // Connection is a singleton, don't close it
            apiError('Блюдо не найдено', 404);
        }
        
        if (!$dish['is_available']) {
            // Connection is a singleton, don't close it
            apiError('Блюдо недоступно для заказа', 400);
        }
        
        // Проверка существования товара в корзине
        $check_stmt = $conn->prepare("SELECT id, quantity FROM cart_items 
                                      WHERE user_id = ? AND dish_id = ? AND 
                                      (size_id = ? OR (size_id IS NULL AND ? IS NULL))
                                      LIMIT 1");
        $check_stmt->bind_param("iiii", $user['user_id'], $dish_id, $size_id, $size_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($existing) {
            // Обновляем количество
            $new_quantity = $existing['quantity'] + $quantity;
            $stmt = $conn->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() 
                                   WHERE id = ?");
            $stmt->bind_param("ii", $new_quantity, $existing['id']);
            $stmt->execute();
            $stmt->close();
            
            $message = 'Количество товара обновлено';
        } else {
            // Добавляем новый товар
            $stmt = $conn->prepare("INSERT INTO cart_items (user_id, dish_id, size_id, quantity, weight) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iiiii", $user['user_id'], $dish_id, $size_id, $quantity, $weight);
            $stmt->execute();
            $stmt->close();
            
            $message = 'Товар добавлен в корзину';
        }
        
        // Connection is a singleton, don't close it
        
        apiSuccess(null, $message);
        break;

    case 'update_cart':
        // Обновление количества товара в корзине
        $user = requireAuth();
        
        $cart_item_id = isset($params['cart_item_id']) ? (int)$params['cart_item_id'] : 0;
        $quantity = isset($params['quantity']) ? (int)$params['quantity'] : 1;
        
        if ($cart_item_id <= 0) {
            apiError('Укажите ID позиции корзины', 400);
        }
        
        if ($quantity <= 0) {
            apiError('Количество должно быть больше 0', 400);
        }
        
        $conn = getDBConnection();
        
        // Проверка что товар принадлежит пользователю
        $stmt = $conn->prepare("SELECT id FROM cart_items WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $cart_item_id, $user['user_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Товар не найден в корзине', 404);
        }
        $stmt->close();
        
        // Обновление количества
        $stmt = $conn->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $quantity, $cart_item_id);
        $stmt->execute();
        $stmt->close();
        // Connection is a singleton, don't close it
        
        apiSuccess(null, 'Количество обновлено');
        break;

    case 'remove_from_cart':
        // Удаление товара из корзины
        $user = requireAuth();
        
        $cart_item_id = isset($params['cart_item_id']) ? (int)$params['cart_item_id'] : 0;
        
        if ($cart_item_id <= 0) {
            apiError('Укажите ID позиции корзины', 400);
        }
        
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $cart_item_id, $user['user_id']);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Товар не найден в корзине', 404);
        }
        
        $stmt->close();
        // Connection is a singleton, don't close it
        
        apiSuccess(null, 'Товар удален из корзины');
        break;

    case 'clear_cart':
        // Очистка всей корзины
        $user = requireAuth();
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $deleted_count = $stmt->affected_rows;
        $stmt->close();
        // Connection is a singleton, don't close it
        
        apiSuccess(['deleted_count' => $deleted_count], 'Корзина очищена');
        break;

    // ========== ЗАКАЗЫ ==========
    
    case 'create_order':
        // Создание заказа
        $user = requireAuth();
        
        $items = isset($params['items']) ? $params['items'] : [];
        $delivery_type_id = isset($params['delivery_type_id']) ? (int)$params['delivery_type_id'] : null;
        $delivery_address = isset($params['delivery_address']) ? trim($params['delivery_address']) : '';
        $phone_param = isset($params['phone']) ? trim($params['phone']) : (isset($user['phone']) ? $user['phone'] : '');
        $phone = trim($phone_param);
        $comment = isset($params['comment']) ? trim($params['comment']) : '';
        $promo_code = isset($params['promo_code']) ? trim($params['promo_code']) : '';
        
        if (empty($items)) {
            apiError('Корзина пуста', 400);
        }
        
        if (empty($phone)) {
            apiError('Укажите номер телефона', 400);
        }
        
        $conn = getDBConnection();
        
        // Расчет стоимости
        $total = 0;
        $order_items = [];
        
        foreach ($items as $item) {
            $dish_id = isset($item['dish_id']) ? (int)$item['dish_id'] : 0;
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            $size_id = isset($item['size_id']) ? (int)$item['size_id'] : null;
            
            if ($dish_id <= 0 || $quantity <= 0) {
                continue;
            }
            
            // Получение блюда
            $stmt = $conn->prepare("SELECT id, name, price, is_available FROM dishes WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $dish_id);
            $stmt->execute();
            $dish = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$dish || !$dish['is_available']) {
                continue;
            }
            
            $price = (float)$dish['price'];
            
            // Если указан размер - добавляем модификатор цены
            if ($size_id) {
                $stmt = $conn->prepare("SELECT price_modifier FROM dish_sizes WHERE dish_id = ? AND size_id = ? LIMIT 1");
                $stmt->bind_param("ii", $dish_id, $size_id);
                $stmt->execute();
                $size_data = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if ($size_data) {
                    $price += (float)$size_data['price_modifier'];
                }
            }
            
            $subtotal = $price * $quantity;
            $total += $subtotal;
            
            $order_items[] = [
                'dish_id' => $dish_id,
                'dish_name' => $dish['name'],
                'size_id' => $size_id,
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }
        
        if (empty($order_items)) {
            // Connection is a singleton, don't close it
            apiError('Нет доступных блюд для заказа', 400);
        }
        
        // Проверка промокода
        $discount = 0;
        $promo_id = null;
        if ($promo_code) {
            $stmt = $conn->prepare("SELECT id, discount_type, discount_value, min_order_amount, max_discount 
                                   FROM promo_codes 
                                   WHERE code = ? AND is_active = 1 
                                   AND (start_date IS NULL OR start_date <= NOW()) 
                                   AND (end_date IS NULL OR end_date >= NOW())
                                   AND (usage_limit IS NULL OR usage_count < usage_limit)
                                   LIMIT 1");
            $stmt->bind_param("s", $promo_code);
            $stmt->execute();
            $promo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $min_amount = isset($promo['min_order_amount']) ? $promo['min_order_amount'] : 0;
            if ($promo && $total >= $min_amount) {
                $promo_id = $promo['id'];
                if ($promo['discount_type'] === 'percent') {
                    $discount = $total * ($promo['discount_value'] / 100);
                    if ($promo['max_discount']) {
                        $discount = min($discount, $promo['max_discount']);
                    }
                } else {
                    $discount = $promo['discount_value'];
                }
            }
        }
        
        // Расчет доставки
        $delivery_cost = 0;
        if ($delivery_type_id) {
            $stmt = $conn->prepare("SELECT price, free_from FROM delivery_types WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $delivery_type_id);
            $stmt->execute();
            $delivery = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($delivery) {
                if ($delivery['free_from'] && $total >= $delivery['free_from']) {
                    $delivery_cost = 0;
                } else {
                    $delivery_cost = $delivery['price'];
                }
            }
        }
        
        $final_total = $total - $discount + $delivery_cost;
        
        // Создание заказа
        $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, delivery_type_id, delivery_address, 
                               phone, comment, promo_code_id, discount_amount, delivery_cost, status) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("idiissidi", $user['user_id'], $final_total, $delivery_type_id, 
                         $delivery_address, $phone, $comment, $promo_id, $discount, $delivery_cost);
        
        if ($stmt->execute()) {
            $order_id = $conn->insert_id;
            $stmt->close();
            
            // Создаем таблицу order_items если не существует
            ensureOrderItemsTable();
            
            // Добавление позиций заказа
            $stmt = $conn->prepare("INSERT INTO order_items (order_id, dish_id, size_id, quantity, price) 
                                   VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                foreach ($order_items as $item) {
                    $stmt->bind_param("iiiii", $order_id, $item['dish_id'], $item['size_id'], 
                                     $item['quantity'], $item['price']);
                    $stmt->execute();
                }
                $stmt->close();
            } else {
                error_log("Failed to prepare order_items insert statement: " . $conn->error);
            }
            
            // Обновление использования промокода
            if ($promo_id) {
                $conn->query("UPDATE promo_codes SET usage_count = usage_count + 1 WHERE id = $promo_id");
            }
            
            logSecurityEvent($user['user_id'], 'order_created_api', "Order ID: $order_id, Total: $final_total");
            
            // Connection is a singleton, don't close it
            
            apiSuccess([
                'order_id' => $order_id,
                'total' => $total,
                'discount' => $discount,
                'delivery_cost' => $delivery_cost,
                'final_total' => $final_total,
                'status' => 'pending'
            ], 'Заказ успешно создан', 201);
        } else {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Ошибка при создании заказа', 500);
        }
        break;

    case 'get_orders':
        error_log("========== get_orders: START ==========");
        
        try {
            // Получение списка заказов пользователя
            // Проверяем авторизацию, но не требуем её обязательно
            error_log("get_orders: Getting token...");
            $token = getAPIToken();
            error_log("get_orders: Token received: " . ($token ? "YES" : "NO"));
            
            error_log("get_orders: Verifying token...");
            $user = verifyAPIToken($token);
            error_log("get_orders: User verified: " . ($user ? "YES" : "NO"));
            
            // Если пользователь не авторизован - возвращаем пустой список
            if (!$user) {
                error_log("get_orders: User not authenticated, returning empty list");
                apiSuccess([
                    'orders' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 10,
                        'total' => 0,
                        'total_pages' => 0
                    ],
                    'requires_auth' => true,
                    'message' => 'Для просмотра заказов необходимо авторизоваться'
                ]);
            }
            
            // Логирование для отладки
            error_log("get_orders: User authenticated, user_id = " . (isset($user['user_id']) ? $user['user_id'] : 'NOT SET'));
            
            // Проверяем существование таблиц заказов
            error_log("get_orders: Checking if order tables exist...");
            if (!ensureOrderTablesExist()) {
                $user_id_log = isset($user['user_id']) ? $user['user_id'] : 'unknown';
                error_log("API ERROR: Order tables do not exist for user " . $user_id_log);
                apiSuccess([
                    'orders' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 10,
                        'total' => 0,
                        'total_pages' => 0
                    ],
                    'message' => 'У вас пока нет заказов'
                ]);
            }
            error_log("get_orders: Order tables exist");
            
            $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
            $limit = isset($params['limit']) ? min(50, max(1, (int)$params['limit'])) : 10;
            $offset = ($page - 1) * $limit;
            
            error_log("get_orders: page=$page, limit=$limit, offset=$offset");
            
            error_log("get_orders: Getting DB connection...");
            $conn = getDBConnection();
            error_log("get_orders: DB connection established");
            
        } catch (Exception $e) {
            error_log("get_orders: EXCEPTION in initialization: " . $e->getMessage());
            error_log("get_orders: Stack trace: " . $e->getTraceAsString());
            apiError('Ошибка инициализации: ' . $e->getMessage(), 500);
        }
        
        // Оборачиваем в try-catch для детальной отладки
        try {
            // Проверяем наличие user_id
            if (!isset($user['user_id'])) {
                error_log("get_orders: user_id not found in user array. Keys: " . implode(', ', array_keys($user)));
                apiError('Ошибка авторизации: user_id не найден', 500);
            }
            
            $user_id = (int)$user['user_id'];
            error_log("get_orders: Starting count query for user_id = $user_id");
            
            // Подсчет заказов
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
            if (!$stmt) {
                error_log("get_orders: Failed to prepare count query: " . $conn->error);
                apiError('Ошибка БД при подсчете заказов: ' . $conn->error, 500);
            }
            
            $stmt->bind_param("i", $user_id);
            if (!$stmt->execute()) {
                error_log("get_orders: Failed to execute count query: " . $stmt->error);
                apiError('Ошибка выполнения запроса подсчета: ' . $stmt->error, 500);
            }
            
            $total_result = $stmt->get_result();
            $total = 0;
            if ($total_result) {
                $total_row = $total_result->fetch_assoc();
                $total = $total_row ? (int)$total_row['total'] : 0;
            }
            $stmt->close();
            
            error_log("get_orders: Found $total orders");
            
            // Получение заказов - только основные поля из таблицы orders
            // Используем * чтобы получить все колонки (адаптируется к структуре БД)
            $query = "SELECT *
                      FROM orders
                      WHERE user_id = ?
                      ORDER BY created_at DESC
                      LIMIT ? OFFSET ?";
            
            error_log("get_orders: Preparing query: $query");
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                error_log("get_orders: Failed to prepare select query: " . $conn->error);
                apiError('Ошибка БД при получении заказов: ' . $conn->error, 500);
            }
            
            $stmt->bind_param("iii", $user_id, $limit, $offset);
            if (!$stmt->execute()) {
                error_log("get_orders: Failed to execute select query: " . $stmt->error);
                apiError('Ошибка выполнения запроса заказов: ' . $stmt->error, 500);
            }
            
            $result = $stmt->get_result();
            if (!$result) {
                error_log("get_orders: Failed to get result: " . $stmt->error);
                apiError('Ошибка получения результатов: ' . $stmt->error, 500);
            }
            
            $orders = [];
            
            while ($row = $result->fetch_assoc()) {
                error_log("get_orders: Processing order ID: " . $row['id']);
                
                // Адаптируемся к структуре таблицы
                $order = [
                    'id' => (int)$row['id']
                ];
                
                // Добавляем сумму заказа (разные варианты названий колонок)
                if (isset($row['total_amount'])) {
                    $order['total_amount'] = (float)$row['total_amount'];
                } elseif (isset($row['total'])) {
                    $order['total_amount'] = (float)$row['total'];
                } elseif (isset($row['amount'])) {
                    $order['total_amount'] = (float)$row['amount'];
                } elseif (isset($row['price'])) {
                    $order['total_amount'] = (float)$row['price'];
                } elseif (isset($row['total_price'])) {
                    $order['total_amount'] = (float)$row['total_price'];
                }
                
                // Добавляем остальные поля если они есть
                if (isset($row['status'])) {
                    $order['status'] = $row['status'];
                }
                if (isset($row['delivery_address'])) {
                    $order['delivery_address'] = $row['delivery_address'];
                }
                if (isset($row['phone'])) {
                    $order['phone'] = $row['phone'];
                }
                if (isset($row['comment'])) {
                    $order['comment'] = $row['comment'];
                }
                if (isset($row['created_at'])) {
                    $order['created_at'] = $row['created_at'];
                }
                if (isset($row['updated_at'])) {
                    $order['updated_at'] = $row['updated_at'];
                }
                
                // НОВОЕ: Получаем товары заказа с размерами из dish_sizes (как в check_order_items.php)
                $order_items = [];
                $items_query = "SELECT oi.*, d.name, d.image, d.weight as dish_weight, 
                               s.name as size_name, ds.weight as size_weight, ds.price_modifier,
                               u.abbreviation as unit_abbr 
                               FROM order_items oi 
                               JOIN dishes d ON oi.dish_id = d.id 
                               LEFT JOIN units u ON d.unit_id = u.id
                               LEFT JOIN dish_sizes ds ON d.id = ds.dish_id AND ds.weight = oi.weight
                               LEFT JOIN sizes s ON ds.size_id = s.id
                               WHERE oi.order_id = ?";
                $items_stmt = $conn->prepare($items_query);
                if ($items_stmt) {
                    $items_stmt->bind_param("i", $row['id']);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();
                    
                    while ($item = $items_result->fetch_assoc()) {
                        $image = !empty($item['image']) ? $item['image'] : 'placeholder.jpg';
                        $img = normalizeImageFilename($image);
                        $imgUrls = buildProductImageUrls($img);
                        
                        $weight = isset($item['weight']) && $item['weight'] > 0 ? 
                                 (int)$item['weight'] : 
                                 (isset($item['dish_weight']) && $item['dish_weight'] > 0 ? (int)$item['dish_weight'] : null);
                        
                        $order_items[] = [
                            'id' => (int)$item['id'],
                            'dish_id' => (int)$item['dish_id'],
                            'dish_name' => $item['name'],
                            'size_name' => $item['size_name'] ?? null,
                            'size_weight' => isset($item['size_weight']) && $item['size_weight'] > 0 ? (int)$item['size_weight'] : null,
                            'price_modifier' => isset($item['price_modifier']) ? (float)$item['price_modifier'] : null,
                            'quantity' => (int)$item['quantity'],
                            'price' => (float)$item['price'],
                            'weight' => $weight,
                            'unit_abbr' => $item['unit_abbr'] ?? 'г',
                            'subtotal' => (float)$item['price'] * (int)$item['quantity'],
                            'image' => $img,
                            'image_url' => $imgUrls['image_url'],
                            'thumbnail_url' => $imgUrls['thumbnail_url']
                        ];
                    }
                    $items_stmt->close();
                }
                
                $order['items'] = $order_items;
                $order['items_count'] = count($order_items);
                
                $orders[] = $order;
            }
            
            $stmt->close();
            // Connection is a singleton, don't close it
            
            error_log("get_orders: Successfully fetched " . count($orders) . " orders");
            
            apiSuccess([
                'orders' => $orders,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => $total > 0 ? ceil($total / $limit) : 0
                ]
            ]);
        } catch (Exception $e) {
            error_log("get_orders: Exception caught: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            apiError('Внутренняя ошибка сервера: ' . $e->getMessage(), 500);
        }
        break;

   case 'order_details':
    // Детали заказа с полной информацией о товарах
    error_log("========== order_details: START ==========");
    error_log("order_details: Received params: " . json_encode($params));
    
    // Проверяем авторизацию
    $token = getAPIToken();
    error_log("order_details: Token received: " . ($token ? substr($token, 0, 20) . "..." : "NONE"));
    
    $user = verifyAPIToken($token);
    error_log("order_details: User verified: " . ($user ? "YES (user_id=" . $user['user_id'] . ")" : "NO"));
    
    $order_id = isset($params['order_id']) ? (int)$params['order_id'] : 0;
    error_log("order_details: order_id = $order_id");
    
    if ($order_id <= 0) {
        error_log("order_details: Invalid order_id, returning 400");
        apiError('Укажите ID заказа', 400);
    }
    
    // Если пользователь не авторизован - требуем авторизацию
    if (!$user) {
        error_log("order_details: User NOT authenticated, returning 401");
        apiError('Для просмотра деталей заказа необходимо авторизоваться', 401);
    }
    
    error_log("order_details: User authenticated, proceeding with user_id=" . $user['user_id'] . ", order_id=$order_id");
    
    $conn = getDBConnection();
    
    // Получение заказа
    error_log("order_details: Executing SQL: SELECT * FROM orders WHERE id=$order_id AND user_id=" . $user['user_id']);
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $order_id, $user['user_id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$order) {
        error_log("order_details: Order NOT FOUND! Checking if order exists at all...");
        
        // Проверяем существует ли заказ вообще (может принадлежит другому пользователю)
        $check_stmt = $conn->prepare("SELECT id, user_id FROM orders WHERE id = ? LIMIT 1");
        $check_stmt->bind_param("i", $order_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $check_order = $check_result->fetch_assoc();
            error_log("order_details: Order exists but belongs to user_id=" . $check_order['user_id'] . ", not " . $user['user_id']);
            $check_stmt->close();
            apiError('Заказ не найден или принадлежит другому пользователю', 404);
        } else {
            error_log("order_details: Order with id=$order_id does not exist in DB at all");
            $check_stmt->close();
            apiError('Заказ с таким ID не существует', 404);
        }
    }
    
    error_log("order_details: Order found! Order data: " . json_encode($order));
    
    // URL для изображений - используем хелперы
    $base_url = getBaseUrl();
    $project_path = getProjectPath();
    // Логируем для отладки
    error_log("order_details: Using base_url: $base_url, project_path: $project_path");
    
    // ==========================================
    // НОВОЕ: Получаем товары заказа (order_items) - КАК В orders.php
    // ==========================================
    $order_items = [];
    $check_oi = @$conn->query("SHOW TABLES LIKE 'order_items'");
    if ($check_oi && $check_oi->num_rows > 0) {
        error_log("order_details: Table order_items exists, fetching items...");
        
        // Используем запрос с размерами из dish_sizes и sizes (как в check_order_items.php)
        $items_stmt = $conn->prepare("SELECT oi.*, d.name, d.image, d.description, d.weight as dish_weight,
                                      s.name as size_name, ds.weight as size_weight, ds.price_modifier,
                                      u.abbreviation as unit_abbr
                                      FROM order_items oi 
                                      JOIN dishes d ON oi.dish_id = d.id 
                                      LEFT JOIN units u ON d.unit_id = u.id
                                      LEFT JOIN dish_sizes ds ON d.id = ds.dish_id AND ds.weight = oi.weight
                                      LEFT JOIN sizes s ON ds.size_id = s.id
                                      WHERE oi.order_id = ?
                                      ORDER BY oi.id");
        if ($items_stmt) {
            $items_stmt->bind_param("i", $order_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            
            error_log("order_details: Query executed, fetching rows...");
            
            while ($item = $items_result->fetch_assoc()) {
                $image = !empty($item['image']) ? $item['image'] : 'placeholder.jpg';
                $img = normalizeImageFilename($image);
                $image_url = buildImageUrl($img, 'product', 'full');
                $thumbnail_url = buildImageUrl($img, 'product', 'thumbnail');
                error_log("order_details: Item '{$item['name']}' - image_url: $image_url");
                
                // Вес: используем вес из order_items, если нет - из dishes
                $weight = isset($item['weight']) && $item['weight'] > 0 ? 
                         (int)$item['weight'] : 
                         (isset($item['dish_weight']) && $item['dish_weight'] > 0 ? (int)$item['dish_weight'] : null);
                
                $order_items[] = [
                    'id' => (int)$item['id'],
                    'dish_id' => (int)$item['dish_id'],
                    'dish_name' => $item['name'],
                    'dish_description' => $item['description'] ?? '',
                    'size_name' => $item['size_name'] ?? null,
                    'size_weight' => isset($item['size_weight']) && $item['size_weight'] > 0 ? (int)$item['size_weight'] : null,
                    'price_modifier' => isset($item['price_modifier']) ? (float)$item['price_modifier'] : null,
                    'quantity' => (int)$item['quantity'],
                    'price' => (float)$item['price'],
                    'weight' => $weight,
                    'unit_abbr' => $item['unit_abbr'] ?? 'г',
                    'subtotal' => (float)$item['price'] * (int)$item['quantity'],
                    'image' => $img,
                    'image_url' => $image_url,
                    'thumbnail_url' => $thumbnail_url
                ];
                
                error_log("order_details: Added item: " . $item['name'] . " (dish_id=" . $item['dish_id'] . ", qty=" . $item['quantity'] . ")");
            }
            
            $items_stmt->close();
            error_log("order_details: Successfully fetched " . count($order_items) . " items from order_items table");
        } else {
            error_log("order_details: Failed to prepare order_items query: " . $conn->error);
        }
    } else {
        error_log("order_details: WARNING - Table order_items does not exist or is not accessible");
        
        // Пробуем альтернативный способ - прямой запрос без prepared statement (с размерами из dish_sizes)
        try {
            $direct_query = "SELECT oi.*, d.name, d.image, d.description, d.weight as dish_weight,
                            s.name as size_name, ds.weight as size_weight, ds.price_modifier,
                            u.abbreviation as unit_abbr
                            FROM order_items oi 
                            JOIN dishes d ON oi.dish_id = d.id 
                            LEFT JOIN units u ON d.unit_id = u.id
                            LEFT JOIN dish_sizes ds ON d.id = ds.dish_id AND ds.weight = oi.weight
                            LEFT JOIN sizes s ON ds.size_id = s.id
                            WHERE oi.order_id = " . (int)$order_id . "
                            ORDER BY oi.id";
            
            $items_result = @$conn->query($direct_query);
            if ($items_result && $items_result->num_rows > 0) {
                error_log("order_details: Direct query successful, found " . $items_result->num_rows . " items");
                
                while ($item = $items_result->fetch_assoc()) {
                    $image = !empty($item['image']) ? $item['image'] : 'placeholder.jpg';
                    $img = normalizeImageFilename($image);
                    $image_url = buildImageUrl($img, 'product', 'full');
                    $thumbnail_url = buildImageUrl($img, 'product', 'thumbnail');
                    error_log("order_details (direct): Item '{$item['name']}' - image_url: $image_url");
                    
                    $weight = isset($item['weight']) && $item['weight'] > 0 ? 
                             (int)$item['weight'] : 
                             (isset($item['dish_weight']) && $item['dish_weight'] > 0 ? (int)$item['dish_weight'] : null);
                    
                    $order_items[] = [
                        'id' => (int)$item['id'],
                        'dish_id' => (int)$item['dish_id'],
                        'dish_name' => $item['name'],
                        'dish_description' => $item['description'] ?? '',
                        'size_name' => $item['size_name'] ?? null,
                        'size_weight' => isset($item['size_weight']) && $item['size_weight'] > 0 ? (int)$item['size_weight'] : null,
                        'price_modifier' => isset($item['price_modifier']) ? (float)$item['price_modifier'] : null,
                        'quantity' => (int)$item['quantity'],
                        'price' => (float)$item['price'],
                        'weight' => $weight,
                        'unit_abbr' => $item['unit_abbr'] ?? 'г',
                        'subtotal' => (float)$item['price'] * (int)$item['quantity'],
                        'image' => $img,
                        'image_url' => $image_url,
                        'thumbnail_url' => $thumbnail_url
                    ];
                }
                
                error_log("order_details: Direct query fetched " . count($order_items) . " items");
            } else {
                error_log("order_details: Direct query failed or returned no results");
            }
        } catch (Exception $e) {
            error_log("order_details: Exception in direct query: " . $e->getMessage());
        }
    }
    
    // ==========================================
    // НОВОЕ: Получаем информацию о типе доставки
    // ==========================================
    $delivery_type_info = null;
    if (isset($order['delivery_type_id']) && $order['delivery_type_id']) {
        $check_dt = @$conn->query("SHOW TABLES LIKE 'delivery_types'");
        if ($check_dt && $check_dt->num_rows > 0) {
            $dt_stmt = $conn->prepare("SELECT name, description, price, estimated_time FROM delivery_types WHERE id = ? LIMIT 1");
            if ($dt_stmt) {
                $dt_stmt->bind_param("i", $order['delivery_type_id']);
                $dt_stmt->execute();
                $dt_result = $dt_stmt->get_result()->fetch_assoc();
                if ($dt_result) {
                    $delivery_type_info = [
                        'id' => (int)$order['delivery_type_id'],
                        'name' => $dt_result['name'],
                        'description' => $dt_result['description'],
                        'price' => (float)$dt_result['price'],
                        'estimated_time' => $dt_result['estimated_time']
                    ];
                }
                $dt_stmt->close();
            }
        }
    }
    
    // ==========================================
    // НОВОЕ: Получаем информацию о промокоде
    // ==========================================
    $promo_info = null;
    if (isset($order['promo_code_id']) && $order['promo_code_id']) {
        $check_pc = @$conn->query("SHOW TABLES LIKE 'promo_codes'");
        if ($check_pc && $check_pc->num_rows > 0) {
            $pc_stmt = $conn->prepare("SELECT code, discount_type, discount_value FROM promo_codes WHERE id = ? LIMIT 1");
            if ($pc_stmt) {
                $pc_stmt->bind_param("i", $order['promo_code_id']);
                $pc_stmt->execute();
                $pc_result = $pc_stmt->get_result()->fetch_assoc();
                if ($pc_result) {
                    $promo_info = [
                        'code' => $pc_result['code'],
                        'discount_type' => $pc_result['discount_type'],
                        'discount_value' => (float)$pc_result['discount_value']
                    ];
                }
                $pc_stmt->close();
            }
        }
    }
    
    // Connection is a singleton, don't close it
    
    // ==========================================
    // Формируем полный ответ с проверкой наличия полей
    // ==========================================
    $response_order = [
        'id' => (int)$order['id']
    ];
    
    // НОВОЕ: Статус заказа с русским переводом
    if (isset($order['status'])) {
        $response_order['status'] = $order['status'];
        
        // Русские названия статусов для UI
        $status_labels = [
            'pending' => 'Ожидает подтверждения',
            'confirmed' => 'Подтвержден',
            'preparing' => 'Готовится',
            'ready' => 'Готов',
            'delivering' => 'В пути',
            'delivered' => 'Доставлен',
            'completed' => 'Завершен',
            'cancelled' => 'Отменен'
        ];
        $response_order['status_label'] = isset($status_labels[$order['status']]) ? $status_labels[$order['status']] : $order['status'];
    }
    
    // Суммы (поддержка разных названий полей)
    if (isset($order['total_price'])) {
        $response_order['total_amount'] = (float)$order['total_price'];
    } elseif (isset($order['total_amount'])) {
        $response_order['total_amount'] = (float)$order['total_amount'];
    } elseif (isset($order['total'])) {
        $response_order['total_amount'] = (float)$order['total'];
    } elseif (isset($order['amount'])) {
        $response_order['total_amount'] = (float)$order['amount'];
    }
    
    // НОВОЕ: Промежуточный итог (сумма товаров без доставки и скидок)
    if (isset($order['subtotal'])) {
        $response_order['subtotal'] = (float)$order['subtotal'];
    } elseif (!empty($order_items)) {
        // Рассчитываем из товаров если нет в БД
        $calculated_subtotal = 0;
        foreach ($order_items as $item) {
            $calculated_subtotal += $item['subtotal'];
        }
        $response_order['subtotal'] = $calculated_subtotal;
    }
    
    // Скидка
    if (isset($order['promo_discount'])) {
        $response_order['discount_amount'] = (float)$order['promo_discount'];
    } elseif (isset($order['discount_amount'])) {
        $response_order['discount_amount'] = (float)$order['discount_amount'];
    }
    
    // Стоимость доставки
    if (isset($order['delivery_price'])) {
        $response_order['delivery_cost'] = (float)$order['delivery_price'];
    } elseif (isset($order['delivery_cost'])) {
        $response_order['delivery_cost'] = (float)$order['delivery_cost'];
    }
    
    // НОВОЕ: Способ оплаты с русским переводом
    if (isset($order['payment_method'])) {
        $response_order['payment_method'] = $order['payment_method'];
        
        // Русские названия способов оплаты
        $payment_labels = [
            'cash' => 'Наличными',
            'card' => 'Картой',
            'online' => 'Онлайн оплата',
            'card_courier' => 'Картой курьеру'
        ];
        $response_order['payment_method_label'] = isset($payment_labels[$order['payment_method']]) ? 
            $payment_labels[$order['payment_method']] : $order['payment_method'];
    }
    
    // НОВОЕ: Информация о доставке
    if ($delivery_type_info) {
        $response_order['delivery_type'] = $delivery_type_info;
    }
    
    if (isset($order['delivery_address'])) {
        $response_order['delivery_address'] = $order['delivery_address'];
    }
    
    // Контактная информация
    if (isset($order['phone'])) {
        $response_order['phone'] = $order['phone'];
    }
    
    // Комментарий к заказу
    if (isset($order['comment'])) {
        $response_order['comment'] = $order['comment'];
    }
    
    if (isset($order['notes'])) {
        $response_order['notes'] = $order['notes'];
    }
    
    // НОВОЕ: Информация о промокоде
    if ($promo_info) {
        $response_order['promo_code'] = $promo_info;
    }
    
    // Даты
    if (isset($order['created_at'])) {
        $response_order['created_at'] = $order['created_at'];
    }
    
    if (isset($order['updated_at'])) {
        $response_order['updated_at'] = $order['updated_at'];
    }
    
    // НОВОЕ: Товары заказа
    $response_order['items'] = $order_items;
    $response_order['items_count'] = count($order_items);
    
    // НОВОЕ: Возможность отмены заказа
    $can_cancel = false;
    if (isset($order['status'])) {
        $can_cancel = in_array($order['status'], ['pending', 'confirmed']);
    }
    $response_order['can_cancel'] = $can_cancel;
    
    error_log("order_details: Sending response with " . count($response_order) . " fields and " . count($order_items) . " items");
    error_log("order_details: Response order keys: " . json_encode(array_keys($response_order)));
    error_log("========== order_details: SUCCESS ==========");
    
    apiSuccess(['order' => $response_order]);
    break;

    case 'cancel_order':
        // Отмена заказа
        $user = requireAuth();
        $order_id = isset($params['order_id']) ? (int)$params['order_id'] : 0;
        
        if ($order_id <= 0) {
            apiError('Укажите ID заказа', 400);
        }
        
        $conn = getDBConnection();
        
        // Проверка заказа
        $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $order_id, $user['user_id']);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$order) {
            // Connection is a singleton, don't close it
            apiError('Заказ не найден', 404);
        }
        
        if ($order['status'] === 'cancelled') {
            // Connection is a singleton, don't close it
            apiError('Заказ уже отменен', 400);
        }
        
        if ($order['status'] === 'delivered' || $order['status'] === 'completed') {
            // Connection is a singleton, don't close it
            apiError('Нельзя отменить доставленный заказ', 400);
        }
        
        // Отмена заказа
        $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $order_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            // Connection is a singleton, don't close it
            logSecurityEvent($user['user_id'], 'order_cancelled_api', "Order ID: $order_id");
            apiSuccess(null, 'Заказ успешно отменен');
        } else {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Ошибка при отмене заказа', 500);
        }
        break;

    // ========== ПРОФИЛЬ ==========
    
    case 'get_profile':
        // Получение профиля пользователя
        // Проверяем авторизацию, но не требуем её обязательно
        $token = getAPIToken();
        $user = verifyAPIToken($token);
        
        // Если пользователь не авторизован - возвращаем null
        if (!$user) {
            apiSuccess([
                'user' => null,
                'requires_auth' => true,
                'message' => 'Для просмотра профиля необходимо авторизоваться'
            ]);
        }
        
        apiSuccess([
            'user' => [
                'id' => (int)$user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'phone' => $user['phone'],
                'address' => $user['address']
            ]
        ]);
        break;

    case 'update_profile':
        // Обновление профиля
        $user = requireAuth();
        
        $full_name = isset($params['full_name']) ? trim($params['full_name']) : $user['full_name'];
        $phone = isset($params['phone']) ? trim($params['phone']) : $user['phone'];
        $address = isset($params['address']) ? trim($params['address']) : $user['address'];
        $email = isset($params['email']) ? trim($params['email']) : $user['email'];
        
        if (!validateEmail($email)) {
            apiError('Неверный формат email', 400);
        }
        
        $conn = getDBConnection();
        
        // Проверка email (если изменился)
        if ($email !== $user['email']) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $stmt->bind_param("si", $email, $user['user_id']);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                // Connection is a singleton, don't close it
                apiError('Email уже используется', 409);
            }
            $stmt->close();
        }
        
        // Обновление профиля
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $full_name, $phone, $address, $email, $user['user_id']);
        
        if ($stmt->execute()) {
            $stmt->close();
            // Connection is a singleton, don't close it
            logSecurityEvent($user['user_id'], 'profile_updated_api', 'Profile updated');
            apiSuccess([
                'user' => [
                    'id' => (int)$user['user_id'],
                    'username' => $user['username'],
                    'email' => $email,
                    'full_name' => $full_name,
                    'phone' => $phone,
                    'address' => $address
                ]
            ], 'Профиль успешно обновлен');
        } else {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Ошибка при обновлении профиля', 500);
        }
        break;

    case 'change_password':
        // Смена пароля
        $user = requireAuth();
        
        $old_password = isset($params['old_password']) ? $params['old_password'] : '';
        $new_password = isset($params['new_password']) ? $params['new_password'] : '';
        
        if (empty($old_password) || empty($new_password)) {
            apiError('Укажите старый и новый пароль', 400);
        }
        
        if (strlen($new_password) < 6) {
            apiError('Новый пароль должен содержать минимум 6 символов', 400);
        }
        
        $conn = getDBConnection();
        
        // Проверка старого пароля
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!password_verify($old_password, $result['password'])) {
            // Connection is a singleton, don't close it
            apiError('Неверный текущий пароль', 401);
        }
        
        // Обновление пароля
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $new_hash, $user['user_id']);
        
        if ($stmt->execute()) {
            $stmt->close();
            // Connection is a singleton, don't close it
            logSecurityEvent($user['user_id'], 'password_changed_api', 'Password changed');
            apiSuccess(null, 'Пароль успешно изменен');
        } else {
            $stmt->close();
            // Connection is a singleton, don't close it
            apiError('Ошибка при смене пароля', 500);
        }
        break;

    // ========== ПРОМОКОДЫ ==========
    
    case 'validate_promo':
        // Проверка промокода (доступно без авторизации)
        $promo_code = isset($params['promo_code']) ? trim($params['promo_code']) : '';
        $order_amount = isset($params['order_amount']) ? (float)$params['order_amount'] : 0;
        
        if (empty($promo_code)) {
            apiError('Укажите промокод', 400);
        }
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id, code, discount_type, discount_value, min_order_amount, max_discount,
                               usage_limit, usage_count, start_date, end_date
                               FROM promo_codes 
                               WHERE code = ? AND is_active = 1
                               LIMIT 1");
        $stmt->bind_param("s", $promo_code);
        $stmt->execute();
        $promo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        // Connection is a singleton, don't close it
        
        if (!$promo) {
            apiError('Промокод не найден или неактивен', 404);
        }
        
        // Проверка даты начала
        if ($promo['start_date'] && strtotime($promo['start_date']) > time()) {
            apiError('Промокод еще не активен', 400);
        }
        
        // Проверка даты окончания
        if ($promo['end_date'] && strtotime($promo['end_date']) < time()) {
            apiError('Срок действия промокода истек', 400);
        }
        
        // Проверка лимита использований
        if ($promo['usage_limit'] && $promo['usage_count'] >= $promo['usage_limit']) {
            apiError('Промокод больше недоступен', 400);
        }
        
        // Проверка минимальной суммы заказа
        if ($order_amount < $promo['min_order_amount']) {
            apiError("Минимальная сумма заказа для этого промокода: {$promo['min_order_amount']} ₽", 400);
        }
        
        // Расчет скидки
        $discount = 0;
        if ($promo['discount_type'] === 'percent') {
            $discount = $order_amount * ($promo['discount_value'] / 100);
            if ($promo['max_discount']) {
                $discount = min($discount, $promo['max_discount']);
            }
        } else {
            $discount = $promo['discount_value'];
        }
        
        apiSuccess([
            'promo' => [
                'code' => $promo['code'],
                'discount_type' => $promo['discount_type'],
                'discount_value' => (float)$promo['discount_value'],
                'discount_amount' => round($discount, 2),
                'min_order_amount' => (float)$promo['min_order_amount'],
                'max_discount' => $promo['max_discount'] ? (float)$promo['max_discount'] : null
            ]
        ], 'Промокод действителен');
        break;

    case 'get_active_promotions':
        // Получение активных акций
        $conn = getDBConnection();
        $query = "SELECT id, title, description, image, start_date, end_date, discount_percent
                  FROM promotions
                  WHERE is_active = 1
                  AND (start_date IS NULL OR start_date <= NOW())
                  AND (end_date IS NULL OR end_date >= NOW())
                  ORDER BY created_at DESC";
        
        $result = $conn->query($query);
        $promotions = [];
        
        while ($row = $result->fetch_assoc()) {
            $image = !empty($row['image']) ? $row['image'] : 'placeholder.jpg';
            $img = normalizeImageFilename($image);
            $promotions[] = [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'discount_percent' => $row['discount_percent'] ? (int)$row['discount_percent'] : null,
                'image_url' => buildImageUrl($img, 'promotion', 'full'),
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date']
            ];
        }
        
        // Connection is a singleton, don't close it
        apiSuccess(['promotions' => $promotions]);
        break;

    case 'promotion_details':
        // Детали конкретной акции
        $promotion_id = isset($params['promotion_id']) ? (int)$params['promotion_id'] : 0;
        
        if ($promotion_id <= 0) {
            apiError('Укажите ID акции', 400);
        }
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id, title, description, content, image, start_date, end_date, 
                               discount_percent, is_active, created_at
                               FROM promotions 
                               WHERE id = ? AND is_active = 1
                               LIMIT 1");
        $stmt->bind_param("i", $promotion_id);
        $stmt->execute();
        $promotion = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        // Connection is a singleton, don't close it
        
        if (!$promotion) {
            apiError('Акция не найдена или неактивна', 404);
        }
        
        $image = !empty($promotion['image']) ? $promotion['image'] : 'placeholder.jpg';
        $img = normalizeImageFilename($image);
        
        // Проверка статуса акции
        $is_upcoming = $promotion['start_date'] && strtotime($promotion['start_date']) > time();
        $is_expired = $promotion['end_date'] && strtotime($promotion['end_date']) < time();
        $is_active = !$is_upcoming && !$is_expired;
        
        apiSuccess([
            'promotion' => [
                'id' => (int)$promotion['id'],
                'title' => $promotion['title'],
                'description' => $promotion['description'],
                'content' => $promotion['content'],
                'discount_percent' => $promotion['discount_percent'] ? (int)$promotion['discount_percent'] : null,
                'image_url' => buildImageUrl($img, 'promotion', 'full'),
                'start_date' => $promotion['start_date'],
                'end_date' => $promotion['end_date'],
                'created_at' => $promotion['created_at'],
                'status' => [
                    'is_active' => $is_active,
                    'is_upcoming' => $is_upcoming,
                    'is_expired' => $is_expired
                ]
            ]
        ]);
        break;

    // ========== ДОСТАВКА ==========
    
    case 'get_delivery_types':
        // Получение типов доставки
        $conn = getDBConnection();
        $query = "SELECT id, name, description, price, estimated_time, free_from
                  FROM delivery_types
                  WHERE is_active = 1
                  ORDER BY sort_order, price";
        
        $result = $conn->query($query);
        $delivery_types = [];
        
        while ($row = $result->fetch_assoc()) {
            $delivery_types[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => (float)$row['price'],
                'estimated_time' => $row['estimated_time'],
                'free_from' => $row['free_from'] ? (float)$row['free_from'] : null
            ];
        }
        
        // Connection is a singleton, don't close it
        apiSuccess(['delivery_types' => $delivery_types]);
        break;

    case 'calculate_delivery':
        // Расчет стоимости доставки
        $delivery_type_id = isset($params['delivery_type_id']) ? (int)$params['delivery_type_id'] : 0;
        $order_amount = isset($params['order_amount']) ? (float)$params['order_amount'] : 0;
        
        if ($delivery_type_id <= 0) {
            apiError('Укажите тип доставки', 400);
        }
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT name, price, free_from FROM delivery_types WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("i", $delivery_type_id);
        $stmt->execute();
        $delivery = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        // Connection is a singleton, don't close it
        
        if (!$delivery) {
            apiError('Тип доставки не найден', 404);
        }
        
        $delivery_cost = $delivery['price'];
        $is_free = false;
        
        if ($delivery['free_from'] && $order_amount >= $delivery['free_from']) {
            $delivery_cost = 0;
            $is_free = true;
        }
        
        apiSuccess([
            'delivery' => [
                'name' => $delivery['name'],
                'cost' => (float)$delivery_cost,
                'is_free' => $is_free,
                'free_from' => $delivery['free_from'] ? (float)$delivery['free_from'] : null
            ]
        ]);
        break;

    case 'debug_image':
        // Диагностика изображений: по dish_id или filename
        $dish_id = isset($params['dish_id']) ? (int)$params['dish_id'] : 0;
        $filename = isset($params['filename']) ? trim($params['filename']) : '';
        $type = isset($params['type']) ? $params['type'] : 'product'; // product|promotion
        $size = isset($params['size']) ? $params['size'] : 'full'; // full|thumbnail

        $source = 'params';
        if ($dish_id > 0 && $type === 'product') {
            $conn = getDBConnection();
            $stmt = $conn->prepare("SELECT image FROM dishes WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $dish_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['image'])) {
                $filename = $row['image'];
                $source = 'dishes.image';
            }
        }

        $normalized = normalizeImageFilename($filename);
        $exists_full = imageFileExistsOnDisk($normalized, $type, 'full');
        $exists_thumb = ($type === 'promotion') ? null : imageFileExistsOnDisk($normalized, 'product', 'thumbnail');

        $built_full = buildImageUrl($normalized, $type, 'full');
        $built_thumb = ($type === 'promotion') ? null : buildImageUrl($normalized, 'product', 'thumbnail');

        $images_dir = getImagesBaseDir();
        $dir_full = getImagesDirFor($type, 'full');
        $dir_thumb = ($type === 'promotion') ? null : getImagesDirFor('product', 'thumbnail');

        apiSuccess([
            'input' => [
                'dish_id' => $dish_id,
                'filename_param' => $filename,
                'type' => $type,
                'size' => $size,
                'source' => $source
            ],
            'normalized' => $normalized,
            'exists' => [
                'full' => $exists_full,
                'thumbnail' => $exists_thumb
            ],
            'urls' => [
                'full' => $built_full,
                'thumbnail' => $built_thumb
            ],
            'fs' => [
                'images_base_dir' => $images_dir,
                'full_dir' => $dir_full,
                'thumbnail_dir' => $dir_thumb
            ],
            'hints' => [
                'upload_full_to' => $dir_full . '/' . $normalized,
                'upload_thumb_to' => $dir_thumb ? ($dir_thumb . '/' . $normalized) : null
            ]
        ], 'debug');
        break;

    // ========== НЕИЗВЕСТНОЕ ДЕЙСТВИЕ ==========
    
    default:
        apiError('Неизвестное действие. Используйте action=info для просмотра доступных эндпоинтов.', 404);
}
?>

https://ryabokonov.site/tokio/api-app/test_order_items.php