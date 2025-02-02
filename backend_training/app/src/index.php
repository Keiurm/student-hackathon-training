<?php
require_once 'config.php'; // 設定ファイルを読み込み

// レスポンスのヘッダーを設定
// JSON形式で返すためのヘッダー
header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

global $pdo;

$routes = [
    'GET' => [
        '#^/todos$#' => 'handleGetTodos',
        '#^/health$#' => 'handleHealthCheck',
        // TODO: 他のエンドポイントを追加
    ],
    'POST' => [
        // TODO: 他のエンドポイントを追加
    ],
    'PUT' => [
        // TODO: 他のエンドポイントを追加
    ],
    'DELETE' => [
        // TODO: 他のエンドポイントを追加
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
echo json_encode(['error' => 'エンドポイントが見つかりません']);
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
            throw new RuntimeException('データベースが応答していません');
        }
    } catch (Exception $e) {
        // クエリエラー時のレスポンス
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'ヘルスチェックに失敗しました',
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
        $stmt = $pdo->query("SELECT * FROM todos ORDER BY id DESC");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // レスポンスを返却
        echo json_encode(['status' => 'ok', 'todos' => $result]);
    } catch (Exception $e) {
        // クエリエラー時のレスポンス
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Todoリストの取得に失敗しました',
            'error' => $e->getMessage()
        ]);
    }
    exit;
}
