<?php
require_once 'config.php'; // 設定ファイルを読み込み

// レスポンスのヘッダーを設定
// JSON形式で返すためのヘッダー
header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];

// Strip out query parameters by getting only the path
$requestUri = $_SERVER['REQUEST_URI']; // This strips out the query string
global $pdo;

$routes = [
    'GET' => [
        '#^/todos$#' => 'handleGetTodos',  // Match only `/todos` with no query params or other path
        '#^/health$#' => 'handleHealthCheck',
        // TODO: 他のエンドポイントを追加
        ///todos/{id}（IDでTodoを取得）エンドポイントを追加
        '#^/todos/(\d+)$#' => 'handleGetTodoById',

    ],
    'POST' => [
        // TODO: 他のエンドポイントを追加
        //http://localhost/todos?title="hello"
        '#^/todos$#' => 'handleCreateTodo',  // Todo作成エンドポイントを追加
        
    ],
    'PUT' => [
        '#^/todos(?:\?.*)?$#' => 'handleUpdateTodo',  // クエリパラメータを許可するように修正
    ],

    'DELETE' => [
        '#^/todos(?:\?.*)?$#' => 'handleDeleteTodo',  // クエリパラメータを許可するように修正
    ]
];

if (isset($routes[$method])) {
    foreach ($routes[$method] as $pattern => $handler) {
        if (preg_match($pattern, $requestUri, $matches)) {
            array_shift($matches);
            call_user_func_array($handler, array_merge([$pdo], $matches));
            exit;
        }
    }
}

http_response_code(404);
echo json_encode(['error' => 'Not Found']);
exit;

/**
 * `/health` エンドポイントを処理します。
 *
 * @param PDO $pdo データベース接続のためのPDOインスタンス
 * @return void
 */
function handleHealthCheck(PDO $pdo): void
{
    try {
        // データベース接続を確認
        $stmt = $pdo->query("SELECT 1");
        $result = $stmt->fetchColumn();

        if ($result == 1) {
            // データベース接続が正常の場合のレスポンス
            echo json_encode(['status' => 'ok', 'database' => 'connected']);
        } else {
            // データベース応答なしの場合のエラーレスポンス
            throw new RuntimeException('Database connection failed');
        }
    } catch (Exception $e) {
        // クエリエラー時のレスポンス
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

/**
 * `/todos` エンドポイントを処理します。
 *
 * @param PDO $pdo データベース接続のためのPDOインスタンス
 * @return void
 */
function handleGetTodos(PDO $pdo): void
{
    try {
        // データベースからTodoリストを取得
        $stmt = $pdo->query("SELECT todos.id, todos.title, statuses.name FROM todos JOIN statuses ON todos.status_id = statuses.id;");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // レスポンスを返却
        echo json_encode(['status' => 'ok', 'data' => $result]);
    } catch (Exception $e) {
        // クエリエラー時のレスポンス
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to get todos',
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

/**
 * `/todos/id` エンドポイントを処理します。
 *
 * 指定されたIDのTodoを取得します。  
 * ・Todoが存在すればHTTP 200で該当Todoを返します。  
 * ・Todoが存在しなければHTTP 404でエラーメッセージを返します。  
 * ・エラー時はHTTP 500でエラーメッセージを返します。
 *
 * @param PDO $pdo
 * @param int $id
 * @return void
 */
function handleGetTodoById(PDO $pdo, int $id): void
{
    try {
        $stmt = $pdo->prepare("SELECT todos.title FROM todos JOIN statuses ON todos.status_id = statuses.id WHERE todos.id = ?");
        $stmt->execute([$id]);
        //"Todo 1"という値だけ返す
        $todo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$todo) {
            http_response_code(404);
            echo json_encode(['error' => 'Todo not found']);
            exit;
        }
        http_response_code(200);
        echo json_encode($todo);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get todo',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

/**
 * `/todos` エンドポイントを処理します。（PUTリクエスト）
 *
 * クエリパラメータからidを取得し、リクエストボディからtitleまたはcompletedの更新内容を取得します。  
 * ・更新されたTodoが存在すればHTTP 200で更新されたTodoを返します。  
 * ・idが見つからなければHTTP 404でエラーメッセージを返します。  
 * ・エラー時はHTTP 500でエラーメッセージを返します。
 *
 * @param PDO $pdo
 * @return void
 */
function handleUpdateTodo(PDO $pdo): void
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "有効なIDが必要です"]);
        exit;
    }
    
    $id = (int)$_GET['id'];
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "無効なJSON"]);
        exit;
    }
    
    $fields = [];
    $params = [];
    
    if (isset($data['title'])) {
        $fields[] = "title = ?";
        $params[] = $data['title'];
    }
    if (isset($data['status_id'])) {
        $fields[] = "status_id = ?";
        $params[] = $data['status_id'];
    }
    
    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(["error" => "更新するフィールドが提供されていません"]);
        exit;
    }
    
    try {
        $sql = "UPDATE todos SET " . implode(", ", $fields) . " WHERE id = ?";
        $params[] = $id;
    
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Todoが見つかりません"]);
            exit;
        }
    
        // 更新後のTodoを取得して返却
        $stmt = $pdo->prepare("SELECT todos.id, todos.title, statuses.name FROM todos JOIN statuses ON todos.status_id = statuses.id WHERE todos.id = ?");
        $stmt->execute([$id]);
        $updatedTodo = $stmt->fetch(PDO::FETCH_ASSOC);
    
        http_response_code(200);
        echo json_encode($updatedTodo);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "エラーが発生しました",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

/**
 * `/todos` エンドポイントを処理します。（DELETEリクエスト）
 *
 * クエリパラメータからidを取得し、指定されたTodoを削除します。  
 * ・削除されたTodoが存在すればHTTP 200で削除されたTodoを返します。  
 * ・Todoが存在しなければHTTP 404でエラーメッセージを返します。  
 * ・エラー時はHTTP 500でエラーメッセージを返します。
 *
 * @param PDO $pdo データベース接続のためのPDOインスタンス
 * @return void
 */
function handleDeleteTodo(PDO $pdo, int $id): void
{    
    try {
        // 削除前にTodoを取得
        $stmt = $pdo->prepare("SELECT todos.id, todos.title, todos.completed FROM todos JOIN statuses ON todos.status_id = statuses.id WHERE todos.id = ?");
        $stmt->execute([$id]);
        $todo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$todo) {
            http_response_code(404);
            echo json_encode(["error" => "Todoが見つかりません"]);
            exit;
        }
        
        // Todoを削除
        $stmt = $pdo->prepare("DELETE FROM todos WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Todoが見つかりません"]);
            exit;
        }
        
        http_response_code(200);
        echo json_encode($todo);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "エラーが発生しました",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}

/**
 * `/todos` エンドポイント (POST) を処理し、新しいTodoを作成します。
 *
 * リクエストボディ： {"title": "Todoのタイトル"}
 * 成功時：HTTP 201 で作成されたTodoを返します。
 * エラー時：HTTP 500 でエラーメッセージを返します。
 *
 * @param PDO $pdo データベース接続のためのPDOインスタンス
 * @return void
 */
function handleCreateTodo(PDO $pdo): void
{
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['title']) || empty(trim($data['title']))) {
            http_response_code(400);
            echo json_encode(['error' => 'Title is required']);
            exit;
        }

        $title = trim($data['title']);
        $defaultStatusId = 1;

        $stmt = $pdo->prepare("INSERT INTO todos (title, status_id) VALUES (?, ?)");
        $stmt->execute([$title, $defaultStatusId]);
        $newId = $pdo->lastInsertId();

        // 作成したTodoを取得
        $stmt = $pdo->prepare("SELECT todos.id, todos.title, statuses.name FROM todos JOIN statuses ON todos.status_id = statuses.id WHERE todos.id = ?");
        $stmt->execute([$newId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            http_response_code(201);
            echo json_encode($result);
        } else {
            throw new Exception("Failed to retrieve created todo");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Todoの作成に失敗しました",
            "message" => $e->getMessage()
        ]);
    }
    exit;
}
