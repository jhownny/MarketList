<?php
// ---------------------------------------------------------
// 1. CONFIGURAÇÕES INICIAIS E HEADERS
// ---------------------------------------------------------

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-api-key");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

header("Content-Type: application/json; charset=UTF-8");

// ---------------------------------------------------------
// 2. CONEXÃO COM BANCO DE DADOS
// ---------------------------------------------------------

require_once __DIR__ . '/../../config.php';

$chaveEnviada = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($chaveEnviada !== API_SECRET) {
    http_response_code(403);
    echo json_encode([
        "erro" => "Acesso Negado",
        "mensagem" => "Chave de API invalida ou ausente."
    ]);
    exit;
}

$mysqli = new mysqli(
    $db_config['host'], 
    $db_config['user'], 
    $db_config['pass'], 
    $db_config['db']
);

if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha na conexão DB: " . $mysqli->connect_error]);
    exit;
}

if (!$mysqli->set_charset("utf8mb4")) {
    error_log("Erro carregando utf8mb4: " . $mysqli->error);
}

// ---------------------------------------------------------
// 3. FUNÇÕES JWT (JSON WEB TOKEN) - SEGURANÇA MÁXIMA
// ---------------------------------------------------------
function base64UrlEncode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function gerar_jwt($payload, $secret) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = base64UrlEncode($signature);
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function validar_jwt($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    list($header, $payload, $signature) = $parts;
    $validSignature = base64UrlEncode(hash_hmac('sha256', $header . "." . $payload, $secret, true));
    if (hash_equals($validSignature, $signature)) {
        $dados = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);
        if (isset($dados['exp']) && $dados['exp'] < time()) return false; // Token expirado
        return $dados;
    }
    return false;
}

function getBearerToken() {
    $headers = $_SERVER['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($headers) && function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $headers = $requestHeaders['Authorization'] ?? '';
    }
    if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) return $matches[1];
    return null;
}

// ---------------------------------------------------------
// 4. ROTEAMENTO E INTERCEPTADOR JWT
// ---------------------------------------------------------

$uri = $_SERVER['REQUEST_URI'];
$parts = explode('/', trim(strtok($uri, '?'), '/'));
$endpoint = end($parts);
$method = $_SERVER['REQUEST_METHOD'];

function limpar_utf8($array) {
    return array_map(function($val) {
        return mb_convert_encoding($val ?? '', 'UTF-8', 'UTF-8');
    }, $array);
}

$uid_autenticado = null;
$rotas_privadas = ['grupos', 'itens', 'finalizar'];

if (in_array($endpoint, $rotas_privadas) && $method !== 'OPTIONS') {
    $token = getBearerToken();
    $dados_token = validar_jwt($token, API_SECRET);
    if (!$dados_token) {
        http_response_code(401);
        echo json_encode(["erro" => "Sessão expirada ou inválida. Faça login novamente."]);
        exit;
    }
    
    $uid_autenticado = $dados_token['id']; 
}

// ---------------------------------------------------------
// 5. LÓGICA DA API
// ---------------------------------------------------------

switch ($endpoint) {

    // ====================================================
    // ROTA: VERIFICAR ATUALIZAÇÃO (INTACTA)
    // ====================================================
    case 'atualizacao':
        if ($method == 'GET') {
            echo json_encode([
                "versao_nome" => "1.4.0",
                "build_numero" => 8, 
                "url_apk" => "https://github.com/jhownny/marketlist_mobile/releases/download/v1.4.0/MarketList_v1.4.0.apk"
            ]);
        }
        break;

    // ====================================================
    // ROTA: USUARIOS (INTACTA PARA O BOT DO TELEGRAM)
    // ====================================================
    case 'usuarios':
        if ($method == 'GET') {
            $chat_id = $_GET['telegram_chat_id'] ?? null;
            
            $sql = "SELECT id, nome, email FROM usuarios";
            if ($chat_id) {
                $sql .= " WHERE telegram_chat_id = '" . $mysqli->real_escape_string($chat_id) . "'";
            }

            $result = $mysqli->query($sql);
            $data = [];
            while ($row = $result->fetch_assoc()) { $data[] = limpar_utf8($row); }
            echo json_encode($data);
        }
        elseif ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['nome']) || empty($input['email']) || empty($input['senha'])) {
                http_response_code(400);
                echo json_encode(["erro" => "Dados incompletos."]);
                break;
            }
            $senha_hash = password_hash($input['senha'], PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $input['nome'], $input['email'], $senha_hash);
            
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(["status" => "sucesso", "id" => $mysqli->insert_id]);
            } else {
                http_response_code(500);
                echo json_encode(["erro" => $stmt->error]);
            }
        }
        break;

    // ====================================================
    // ROTA: GRUPOS (PROTEGIDA)
    // ====================================================
    case 'grupos':
        if ($method == 'GET') {
            // Ignora o ?usuario_id da URL e usa o do Token!
            $stmt = $mysqli->prepare("SELECT * FROM grupos WHERE usuario_id = ?");
            $stmt->bind_param("i", $uid_autenticado);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) { $data[] = limpar_utf8($row); }
            echo json_encode($data);
        }
        elseif ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['nome'])) {
                http_response_code(400); echo json_encode(["erro" => "Faltou o nome do grupo"]); break;
            }
            $stmt = $mysqli->prepare("INSERT INTO grupos (usuario_id, nome, icone) VALUES (?, ?, ?)");
            $icone = $input['icone'] ?? null;
            // Usa o ID do Token
            $stmt->bind_param("iss", $uid_autenticado, $input['nome'], $icone);
            
            if ($stmt->execute()) {
                http_response_code(201); echo json_encode(["id" => $mysqli->insert_id, "status" => "grupo criado"]);
            } else {
                http_response_code(500); echo json_encode(["erro" => $stmt->error]);
            }
        }
        break;

    // ====================================================
    // ROTA: ITENS (A LISTA DE COMPRAS - PROTEGIDA)
    // ====================================================
    case 'itens':
        if ($method == 'GET') {
            // Usa o ID do Token na query
            $sql = "SELECT i.*, g.nome as nome_grupo FROM itens i LEFT JOIN grupos g ON i.grupo_id = g.id WHERE i.usuario_id = ?";
            if (isset($_GET['grupo_id'])) {
                $sql .= " AND i.grupo_id = " . intval($_GET['grupo_id']);
            }
            if (isset($_GET['status'])) {
                $sql .= " AND i.status = '" . $mysqli->real_escape_string($_GET['status']) . "'";
            }
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $uid_autenticado);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) { $data[] = limpar_utf8($row); }
            echo json_encode($data);
        }
        
        elseif ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['grupo_id']) || empty($input['produto'])) {
                http_response_code(400); echo json_encode(["erro" => "Faltam dados (grupo_id, produto)"]); break;
            }
            
            $stmt = $mysqli->prepare("INSERT INTO itens (usuario_id, grupo_id, produto, preco, status) VALUES (?, ?, ?, ?, 'pendente')");
            $preco = $input['preco'] ?? null;
            // Usa o ID do Token!
            $stmt->bind_param("iisd", $uid_autenticado, $input['grupo_id'], $input['produto'], $preco);
            
            if ($stmt->execute()) {
                http_response_code(201); echo json_encode(["id" => $mysqli->insert_id, "status" => "item adicionado"]);
            } else {
                http_response_code(500); echo json_encode(["erro" => $stmt->error]);
            }
        }

        elseif ($method == 'DELETE') {
            $id = $_GET['id'] ?? null;
            if (!$id) { http_response_code(400); echo json_encode(["erro" => "Faltou ?id=X"]); break; }
            
            // Segurança: Só deleta se o item pertencer ao usuário do Token!
            $stmt = $mysqli->prepare("DELETE FROM itens WHERE id = ? AND usuario_id = ?");
            $stmt->bind_param("ii", $id, $uid_autenticado);
            if ($stmt->execute()) echo json_encode(["status" => "deletado"]);
            else echo json_encode(["erro" => $stmt->error]);
        }

        elseif ($method == 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['id']) || empty($input['produto']) || !isset($input['preco'])) {
                http_response_code(400); echo json_encode(["erro" => "Faltam dados"]); break;
            }
            
            // Segurança: Só edita se o item pertencer ao usuário do Token!
            $stmt = $mysqli->prepare("UPDATE itens SET produto = ?, preco = ? WHERE id = ? AND usuario_id = ?");
            $stmt->bind_param("sdii", $input['produto'], $input['preco'], $input['id'], $uid_autenticado);
            
            if ($stmt->execute()) {
                echo json_encode(["status" => "item atualizado"]);
            } else {
                http_response_code(500); echo json_encode(["erro" => $stmt->error]);
            }
        }
        break;

    // ====================================================
    // ROTA: FINALIZAR (PROTEGIDA)
    // ====================================================
    case 'finalizar':
        if ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['grupo_id'])) {
                http_response_code(400); echo json_encode(["erro" => "Informe o grupo_id"]); break;
            }

            // Usa o ID do Token para somar
            $stmt = $mysqli->prepare("SELECT SUM(preco) as total FROM itens WHERE usuario_id = ? AND grupo_id = ? AND status = 'pendente'");
            $stmt->bind_param("ii", $uid_autenticado, $input['grupo_id']);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $total = $res['total'] ?? 0;
            $stmt->close();

            // Usa o ID do Token para finalizar
            $stmtUpdate = $mysqli->prepare("UPDATE itens SET status = 'finalizado', data_finalizacao = NOW() WHERE usuario_id = ? AND grupo_id = ? AND status = 'pendente'");
            $stmtUpdate->bind_param("ii", $uid_autenticado, $input['grupo_id']);
            
            if ($stmtUpdate->execute()) {
                echo json_encode([
                    "mensagem" => "Lista finalizada com sucesso!",
                    "total_gasto" => $total,
                    "itens_fechados" => $stmtUpdate->affected_rows
                ]);
            } else {
                http_response_code(500); echo json_encode(["erro" => $stmtUpdate->error]);
            }
        }
        break;

    // ====================================================
    // ROTA: LOGIN (GERAÇÃO DO TOKEN)
    // ====================================================
    case 'login':
        if ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input['email']) || empty($input['senha'])) {
                http_response_code(400); echo json_encode(["erro" => "Email e senha obrigatórios"]); break;
            }

            $stmt = $mysqli->prepare("SELECT id, nome, senha FROM usuarios WHERE email = ?");
            $stmt->bind_param("s", $input['email']);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($input['senha'], $user['senha'])) {
                
                if (!empty($input['telegram_chat_id'])) {
                    $stmtUp = $mysqli->prepare("UPDATE usuarios SET telegram_chat_id = ? WHERE id = ?");
                    $stmtUp->bind_param("si", $input['telegram_chat_id'], $user['id']);
                    $stmtUp->execute();
                }

                // -> AQUI NASCE O TOKEN VÁLIDO POR 7 DIAS! <-
                $payload = [
                    'id' => $user['id'],
                    'nome' => $user['nome'],
                    'exp' => time() + (86400 * 7) 
                ];
                $token_jwt = gerar_jwt($payload, API_SECRET);

                echo json_encode([
                    "status" => "logado",
                    "id" => $user['id'],
                    "nome" => $user['nome'],
                    "token" => $token_jwt // Enviando o crachá de volta!
                ]);
            } else {
                http_response_code(401); 
                echo json_encode(["erro" => "Credenciais inválidas"]);
            }
        }
        break;
    
    // ====================================================
    // ROTA 404
    // ====================================================
    default:
        http_response_code(404);
        echo json_encode(["erro" => "Endpoint desconhecido: $endpoint"]);
        break;
}
?>