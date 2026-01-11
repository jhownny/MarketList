<?php
// bot.php
// NÃO TEM MAIS CONEXÃO COM BANCO DE DADOS AQUI!

$botToken = "8406912999:AAFEdNcWGUOL3XT25MkkDY-A_pnyMQ826G8"; 
$telegramApi = "https://api.telegram.org/bot$botToken";
$minhaApiUrl = "https://www.jhownnyprojects.com.br/api"; // URL da SUA API

// 1. Recebe dados do Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!$update || !isset($update["message"])) exit;

$chatId = $update["message"]["chat"]["id"];
$texto = trim($update["message"]["text"]);
$nomeTelegram = $update["message"]["from"]["first_name"];

$resposta = "";

// ---------------------------------------------------------
// VERIFICAÇÃO DE IDENTIDADE (VIA API)
// ---------------------------------------------------------
// Pergunta pra API: "Existe algum usuário com esse Chat ID?"
$usuarios = chamarApi('GET', "$minhaApiUrl/usuarios?telegram_chat_id=$chatId");
$meuUsuario = $usuarios[0] ?? null; // Pega o primeiro resultado se existir

// ---------------------------------------------------------
// LÓGICA DE COMANDOS
// ---------------------------------------------------------

// 1. TENTATIVA DE LOGIN (/conectar email senha)
if (strpos($texto, "/conectar") === 0) {
    $partes = preg_split('/\s+/', $texto);
    if (count($partes) < 3) {
        $resposta = "⚠️ Use: /conectar email senha";
    } else {
        // Envia para API validar
        $loginData = [
            "email" => $partes[1],
            "senha" => $partes[2],
            "telegram_chat_id" => $chatId // Manda o ID pra API vincular
        ];
        
        $loginResult = chamarApi('POST', "$minhaApiUrl/login", $loginData);

        if (isset($loginResult['status']) && $loginResult['status'] == 'logado') {
            $resposta = "✅ Olá, " . $loginResult['nome'] . "! Você está conectado.";
        } else {
            $resposta = "❌ Email ou senha incorretos.";
        }
    }
}

// 2. SE NÃO ESTIVER LOGADO
elseif (!$meuUsuario) {
    $resposta = "🔒 Você não está conectado.\nDigite: /conectar seu@email.com sua_senha";
}

// 3. SE JÁ ESTIVER LOGADO (Salvar Compras)
else {
    // Regex: Comprei [algo] [preço]
    if (preg_match('/(Comprei|Gastei)\s+(.+?)\s+(\d+[.,]?\d*)/i', $texto, $matches)) {
        $produto = trim($matches[2]);
        $preco = str_replace(',', '.', $matches[3]);

        // Monta o pacote para a API
        $novoItem = [
            "usuario_id" => $meuUsuario['id'], // ID que veio da consulta da API lá em cima
            "grupo_id" => 1, // Mercado (Fixo por enquanto)
            "produto" => $produto,
            "preco" => (float)$preco
        ];

        // Manda a API salvar
        $resultado = chamarApi('POST', "$minhaApiUrl/itens", $novoItem);

        if (isset($resultado['status']) && $resultado['status'] == 'item adicionado') {
            $resposta = "📝 Salvo na nuvem: $produto (R$ $preco)";
        } else {
            $resposta = "❌ A API retornou erro: " . ($resultado['erro'] ?? 'Desconhecido');
        }
    } 
    elseif ($texto == "/finalizar") {
        // Exemplo de chamar o finalizar via API
        $dados = ["usuario_id" => $meuUsuario['id'], "grupo_id" => 1];
        $res = chamarApi('POST', "$minhaApiUrl/finalizar", $dados);
        
        if (isset($res['total_gasto'])) {
            $resposta = "🛒 Lista fechada! Total: R$ " . $res['total_gasto'];
        } else {
            $resposta = "Erro ao finalizar.";
        }
    }
    else {
        $resposta = "Oi " . $meuUsuario['nome'] . "! Diga 'Comprei Café 10'";
    }
}

// ---------------------------------------------------------
// ENVIA RESPOSTA AO TELEGRAM
// ---------------------------------------------------------
if ($resposta) {
    file_get_contents($telegramApi . "/sendMessage?chat_id=$chatId&text=" . urlencode($resposta));
}

// ---------------------------------------------------------
// FUNÇÃO AUXILIAR: FAZ A PONTE HTTP
// ---------------------------------------------------------
function chamarApi($metodo, $url, $dados = null) {
    $opcoes = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => $metodo,
            'ignore_errors' => true // Para ler o corpo mesmo se der erro 400/500
        ]
    ];
    
    if ($dados) {
        $opcoes['http']['content'] = json_encode($dados);
    }

    $contexto = stream_context_create($opcoes);
    $resultado = file_get_contents($url, false, $contexto);
    
    return json_decode($resultado, true);
}
?>