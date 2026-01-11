<?php
// ---------------------------------------------------------
// BOT TELEGRAM - INTEGRAÇÃO VIA API (SEM BANCO DIRETO)
// ---------------------------------------------------------

require_once __DIR__ . '/../../config.php';

// CONFIGURAÇÕES
$botToken = $bot_config['token'];
$telegramApi = "https://api.telegram.org/bot$botToken";

// URL da API
$minhaApiUrl = "https://www.jhownnyprojects.com.br/api"; 

// Recebe e decodifica o JSON do Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Se não houver mensagem válida, encerra silenciosamente
if (!$update || !isset($update["message"])) exit;

$chatId = $update["message"]["chat"]["id"];
$texto = trim($update["message"]["text"] ?? ''); // Garante que não dê erro se for nulo
$nomeTelegram = $update["message"]["from"]["first_name"] ?? 'Usuário';

$resposta = "";

// ---------------------------------------------------------
// 2. VERIFICAÇÃO DE IDENTIDADE
// ---------------------------------------------------------
// Consulta a API para saber quem é o dono deste Chat ID
$usuarios = chamarApi('GET', "$minhaApiUrl/usuarios?telegram_chat_id=$chatId");

// Se a API retornar erro ou array vazio, o usuário é null
$meuUsuario = (!empty($usuarios) && isset($usuarios[0])) ? $usuarios[0] : null;

// ---------------------------------------------------------
// 3. ROTEAMENTO DE COMANDOS
// ---------------------------------------------------------

// --- COMANDO: /conectar email senha ---
if (strpos($texto, "/conectar") === 0) {
    $partes = preg_split('/\s+/', $texto); // Divide por espaços
    
    if (count($partes) < 3) {
        $resposta = "⚠️ Formato inválido.\nUse: `/conectar seu@email.com sua_senha`";
    } else {
        $loginData = [
            "email" => $partes[1],
            "senha" => $partes[2],
            "telegram_chat_id" => $chatId
        ];
        
        $loginResult = chamarApi('POST', "$minhaApiUrl/login", $loginData);

        if (isset($loginResult['status']) && $loginResult['status'] == 'logado') {
            $resposta = "✅ *Conectado com sucesso!*\nBem-vindo, " . $loginResult['nome'] . ".";
        } else {
            $resposta = "❌ Email ou senha incorretos.";
        }
    }
}

// --- COMANDO: /sair (Deslogar) ---
elseif ($texto == "/sair") {
    // Para deslogar, poderíamos criar uma rota na API, 
    // mas por enquanto vamos apenas avisar como trocar.
    // O ideal seria limpar o telegram_chat_id no banco via API.
    $resposta = "Para desconectar, peça ao administrador ou use /conectar com outra conta.";
}

// --- COMANDO: /start ou /ajuda ---
elseif ($texto == "/start" || $texto == "/ajuda") {
    $resposta = "Olá, $nomeTelegram! Eu sou o Market List 🤖\n\n" .
                "📌 *Comandos:*\n" .
                "• `Comprei Item Preço` (Ex: Comprei Pão 5.90)\n" .
                "• `Comprei Item Preço x Qtd` (Ex: Comprei Leite 4.00 x 3)\n" .
                "• `/finalizar` (Fecha a lista)\n" .
                "• `/conectar` (Login)";
}

// --- VERIFICAÇÃO DE LOGIN ---
elseif (!$meuUsuario) {
    $resposta = "🔒 *Acesso Bloqueado*\n\nEu não sei quem você é.\n" .
                "Por favor, conecte-se digitando:\n" .
                "`/conectar seu@email.com sua_senha`";
}

// --- USUÁRIO LOGADO: PROCESSAR COMPRAS ---
else {
    
    // REGEX (Aceita Multiplicação)
    // Grupo 1: Ação (Comprei/Gastei)
    // Grupo 2: Nome do Produto
    // Grupo 3: Preço Unitário
    // Grupo 4: Quantidade (Opcional)
    $pattern = '/(Comprei|Gastei)\s+(.+?)\s+(\d+[.,]?\d*)(?:\s*[xX*]\s*(\d+))?$/i';

    if (preg_match($pattern, $texto, $matches)) {
        
        // Extração e Tratamento
        $produtoNome = trim($matches[2]);
        $precoUnitario = (float)str_replace(',', '.', $matches[3]);
        $quantidade = (isset($matches[4]) && $matches[4] !== '') ? (int)$matches[4] : 1;
        
        // Cálculo do Total
        $precoTotal = $precoUnitario * $quantidade;

        // Se tiver mais de 1, adiciona a qtd no nome para ficar claro na lista
        if ($quantidade > 1) {
            $produtoNome .= " ({$quantidade}x)";
        }

        // Monta o pacote para a API
        $novoItem = [
            "usuario_id" => $meuUsuario['id'],
            "grupo_id"   => 1, // Padrão: Mercado
            "produto"    => $produtoNome,
            "preco"      => $precoTotal
        ];

        // Envia para a API
        $resultado = chamarApi('POST', "$minhaApiUrl/itens", $novoItem);

        if (isset($resultado['status']) && $resultado['status'] == 'item adicionado') {
            // Resposta com formatação de moeda
            $totalFormatado = number_format($precoTotal, 2, ',', '.');
            $resposta = "📝 *Anotado:* $produtoNome\n💰 *Valor:* R$ $totalFormatado";
            
            if ($quantidade > 1) {
                $resposta .= "\n_(Calculado: $matches[3] x $quantidade)_";
            }
        } else {
            $erroMsg = $resultado['erro'] ?? 'Erro desconhecido na API';
            $resposta = "❌ Falha ao salvar: " . $erroMsg;
        }

    } 
    
    // --- FINALIZAR COMPRA ---
    elseif ($texto == "/finalizar") {
        $dados = ["usuario_id" => $meuUsuario['id'], "grupo_id" => 1];
        $res = chamarApi('POST', "$minhaApiUrl/finalizar", $dados);
        
        if (isset($res['total_gasto'])) {
            $total = number_format($res['total_gasto'], 2, ',', '.');
            $resposta = "🛒 *Lista Finalizada!*\n\n" . 
                        "📦 Itens fechados: " . $res['itens_fechados'] . "\n" .
                        "💸 *Total Gasto: R$ $total*";
        } else {
            $resposta = "⚠️ Nada pendente para finalizar ou erro no sistema.";
        }
    }
    
    // --- NÃO ENTENDEU ---
    else {
        $resposta = "Oi " . $meuUsuario['nome'] . "! Não entendi.\n" .
                    "Tente: `Comprei Café 10` ou `Comprei Café 5 x 2`";
    }
}

// ---------------------------------------------------------
// 4. ENVIA RESPOSTA AO TELEGRAM
// ---------------------------------------------------------
if ($resposta) {
    // Adicionei 'parse_mode' => 'Markdown' para deixar negrito/itálico funcionar
    $urlEnvio = $telegramApi . "/sendMessage?chat_id=$chatId&parse_mode=Markdown&text=" . urlencode($resposta);
    file_get_contents($urlEnvio);
}

// ---------------------------------------------------------
// FUNÇÃO AUXILIAR
// ---------------------------------------------------------
function chamarApi($metodo, $url, $dados = null) {

    $opcoes = [
        'https' => [
            'method'  => $metodo,
            'ignore_errors' => true,
            'timeout' => 10,
            'header'  => "Content-type: application/json\r\n" .
                         "Api-Key: " . API_SECRET . "\r\n"
        ]

    ];

    if ($dados) {
        $opcoes['https']['content'] = json_encode($dados);
    }

    $contexto = stream_context_create($opcoes);
    $resultado = @file_get_contents($url, false, $contexto);

    if ($resultado === FALSE) {
        return ["erro" => "Falha de conexão com a API"];
    }

    return json_decode($resultado, true);

}
?>