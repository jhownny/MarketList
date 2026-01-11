<?php
// CONFIGURAÇÕES
$botToken = "8406912999:AAFEdNcWGUOL3XT25MkkDY-A_pnyMQ826G8"; // Coloque o token do BotFather aqui
$apiUrl = "http://api.telegram.org/bot$botToken";

// 1. Recebe o JSON do Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Se não tiver mensagem, para por aqui
if (!$update || !isset($update["message"])) {
    exit;
}

// 2. Extrai dados importantes
$chatId = $update["message"]["chat"]["id"];
$texto = $update["message"]["text"] ?? '';
$usuarioNome = $update["message"]["from"]["first_name"];

// 3. Lógica Simplificada (Simulação da IA)
// Aqui futuramente entrará sua IA Python ou Regex complexo
// Exemplo: "Comprei Farinha 5.90"

$resposta = "";

if (stripos($texto, "Comprei") !== false || stripos($texto, "Gastei") !== false) {
    // --- LÓGICA DE SALVAR NO SEU BANCO ---
    // Vamos supor que você extraiu os dados via código (regex simples por enquanto)
    // Padrão esperado: "Comprei [Produto] [Preço]"
    
    // Regex simples para pegar: (Comprei) (Nome do Produto) (Preço)
    // Ex: Comprei Leite 4.50
    if (preg_match('/(Comprei|Gastei)\s+(.+?)\s+(\d+[.,]\d{2})/i', $texto, $matches)) {
        $produto = trim($matches[2]);
        $preco = str_replace(',', '.', $matches[3]); // Troca vírgula por ponto
        
        // CHAMA SUA PRÓPRIA API INTERNAMENTE PARA SALVAR
        // Nota: O ideal é usar cURL, mas para simplificar:
        $dadosParaSalvar = [
            'usuario_id' => 1, // Fixo por enquanto, depois vinculamos ao ChatID
            'grupo_id' => 1,   // Fixo: Mercado
            'produto' => $produto,
            'preco' => (float)$preco
        ];
        
        // Função interna para salvar (Ponte com seu código anterior)
        $resultado = salvarItemInterno($dadosParaSalvar);
        
        if ($resultado) {
            $resposta = "✅ Anotado: $produto (R$ $preco) no Mercado.";
        } else {
            $resposta = "❌ Erro ao salvar no banco.";
        }
    } else {
        $resposta = "⚠️ Não entendi. Tente: 'Comprei Arroz 20.00'";
    }

} elseif (strtolower($texto) == "/start") {
    $resposta = "Olá, $usuarioNome! Eu sou o Nexus. Diga o que comprou.";
} else {
    $resposta = "Ainda não sei o que é '$texto'. Tente 'Comprei [coisa] [preço]'.";
}

// 4. Envia a resposta de volta para o Telegram
file_get_contents($apiUrl . "/sendMessage?chat_id=$chatId&text=" . urlencode($resposta));


// --- FUNÇÃO AUXILIAR PARA CHAMAR SUA API ---
function salvarItemInterno($dados) {
    // URL da sua API que criamos no passo anterior
    $url = 'https://www.jhownnyprojects.com.br/api/itens'; 
    
    $options = [
        'https' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($dados),
            'ignore_errors' => true 
        ]
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    return $result ? true : false;
}
?>