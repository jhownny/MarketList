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
// 3. ROTEAMENTO
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

// ---------------------------------------------------------
// 4. LÓGICA DA API
// ---------------------------------------------------------

switch ($endpoint) {

    // ====================================================
    // ROTA: VERIFICAR ATUALIZAÇÃO
    // ====================================================
    case 'atualizacao':
        if ($method == 'GET') {
            echo json_encode([
                "versao_nome" => "1.1.0",
                "build_numero" => 5, 
                "url_apk" => "https://github.com/jhownny/marketlist_mobile/releases/download/v1.3.0/MarketList_v1.3.0.apk"
            ]);
        }
    break;

    // ====================================================
    // ROTA: USUARIOS (ATUALIZADA)
    // ====================================================
    case 'usuarios':
        if ($method == 'GET') {
            // Permite o Bot perguntar: "Quem é o dono do chat_id 12345?"
            $chat_id = $_GET['telegram_chat_id'] ?? null;
            
            $sql = "SELECT id, nome, email FROM usuarios";
            if ($chat_id) {
                // Filtro específico para o Bot
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
    // ROTA: GRUPOS
    // ====================================================
    case 'grupos':
        // GET: Listar grupos de um usuário (ex: ?usuario_id=1)
        if ($method == 'GET') {
            $uid = $_GET['usuario_id'] ?? null;
            if (!$uid) {
                http_response_code(400); echo json_encode(["erro" => "Faltou ?usuario_id=X"]); break;
            }
            $stmt = $mysqli->prepare("SELECT * FROM grupos WHERE usuario_id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) { $data[] = limpar_utf8($row); }
            echo json_encode($data);
        }
        // POST: Criar novo grupo
        elseif ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['usuario_id']) || empty($input['nome'])) {
                http_response_code(400); echo json_encode(["erro" => "Faltou usuario_id ou nome"]); break;
            }
            $stmt = $mysqli->prepare("INSERT INTO grupos (usuario_id, nome, icone) VALUES (?, ?, ?)");
            $icone = $input['icone'] ?? null;
            $stmt->bind_param("iss", $input['usuario_id'], $input['nome'], $icone);
            
            if ($stmt->execute()) {
                http_response_code(201); echo json_encode(["id" => $mysqli->insert_id, "status" => "grupo criado"]);
            } else {
                http_response_code(500); echo json_encode(["erro" => $stmt->error]);
            }
        }
        break;

    // ====================================================
    // ROTA: ITENS (A LISTA DE COMPRAS)
    // ====================================================
    case 'itens':
        // GET: Listar itens (pode filtrar por ?grupo_id=X e ?status=pendente)
        if ($method == 'GET') {
            $uid = $_GET['usuario_id'] ?? null;
            if (!$uid) { http_response_code(400); echo json_encode(["erro" => "Faltou ?usuario_id=X"]); break; }
            
            $sql = "SELECT i.*, g.nome as nome_grupo FROM itens i LEFT JOIN grupos g ON i.grupo_id = g.id WHERE i.usuario_id = ?";
            
            // Filtro opcional por grupo
            if (isset($_GET['grupo_id'])) {
                $sql .= " AND i.grupo_id = " . intval($_GET['grupo_id']);
            }
            // Filtro opcional por status (pendente/finalizado)
            if (isset($_GET['status'])) {
                $sql .= " AND i.status = '" . $mysqli->real_escape_string($_GET['status']) . "'";
            }
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) { $data[] = limpar_utf8($row); }
            echo json_encode($data);
        }
        
        // POST: Adicionar item na lista
        elseif ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            // Validação mínima
            if (empty($input['usuario_id']) || empty($input['grupo_id']) || empty($input['produto'])) {
                http_response_code(400); echo json_encode(["erro" => "Faltam dados (usuario_id, grupo_id, produto)"]); break;
            }
            
            $stmt = $mysqli->prepare("INSERT INTO itens (usuario_id, grupo_id, produto, preco, status) VALUES (?, ?, ?, ?, 'pendente')");
            $preco = $input['preco'] ?? null;
            $stmt->bind_param("iisd", $input['usuario_id'], $input['grupo_id'], $input['produto'], $preco);
            
            if ($stmt->execute()) {
                http_response_code(201); echo json_encode(["id" => $mysqli->insert_id, "status" => "item adicionado"]);
            } else {
                http_response_code(500); echo json_encode(["erro" => $stmt->error]);
            }
        }

        // DELETE: Remover item (via ?id=X)
        elseif ($method == 'DELETE') {
            $id = $_GET['id'] ?? null;
            if (!$id) { http_response_code(400); echo json_encode(["erro" => "Faltou ?id=X"]); break; }
            
            $stmt = $mysqli->prepare("DELETE FROM itens WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) echo json_encode(["status" => "deletado"]);
            else echo json_encode(["erro" => $stmt->error]);
        }
        

        // PUT: Editar item existente (Atualização de Nome e Preço)
        elseif ($method == 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validação para garantir que recebemos o ID e os novos dados
            if (empty($input['id']) || empty($input['produto']) || !isset($input['preco'])) {
                http_response_code(400); 
                echo json_encode(["erro" => "Faltam dados (id, produto, preco)"]); 
                break;
            }
            
            $stmt = $mysqli->prepare("UPDATE itens SET produto = ?, preco = ? WHERE id = ?");
            // "sdi" significa: String (produto), Double (preco), Integer (id)
            $stmt->bind_param("sdi", $input['produto'], $input['preco'], $input['id']);
            
            if ($stmt->execute()) {
                echo json_encode(["status" => "item atualizado"]);
            } else {
                http_response_code(500); 
                echo json_encode(["erro" => $stmt->error]);
            }
        }
        break;

    // ====================================================
    // ROTA: FINALIZAR (SOMA TUDO E FECHA A LISTA)
    // ====================================================
    case 'finalizar':
        if ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['usuario_id']) || empty($input['grupo_id'])) {
                http_response_code(400); echo json_encode(["erro" => "Informe usuario_id e grupo_id"]); break;
            }

            //Calcula o total gasto
            $stmt = $mysqli->prepare("SELECT SUM(preco) as total FROM itens WHERE usuario_id = ? AND grupo_id = ? AND status = 'pendente'");
            $stmt->bind_param("ii", $input['usuario_id'], $input['grupo_id']);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $total = $res['total'] ?? 0;
            $stmt->close();

            //Marca tudo como finalizado
            $stmtUpdate = $mysqli->prepare("UPDATE itens SET status = 'finalizado', data_finalizacao = NOW() WHERE usuario_id = ? AND grupo_id = ? AND status = 'pendente'");
            $stmtUpdate->bind_param("ii", $input['usuario_id'], $input['grupo_id']);
            
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
    // ROTA: LOGIN (NOVA - Para o comando /conectar)
    // ====================================================
    case 'login':
        if ($method == 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input['email']) || empty($input['senha'])) {
                http_response_code(400); echo json_encode(["erro" => "Email e senha obrigatórios"]); break;
            }

            // Busca usuário pelo email
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

                echo json_encode([
                    "status" => "logado",
                    "id" => $user['id'],
                    "nome" => $user['nome']
                ]);
            } else {
                http_response_code(401); // Unauthorized
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