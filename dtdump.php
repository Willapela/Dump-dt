<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

class DTunnelMod
{
    private string $api;

    public function __construct($telegramToken)
    {
        $this->api = "https://api.telegram.org/bot{$telegramToken}";
    }

    private function sendError(int $chatId, string $errorMsg)
    {
        $this->sendMessage($chatId, "Erro: $errorMsg");
    }

    private function sendMessage(int $chatId, string $text, string $parseMode = "HTML")
    {
        $url = "{$this->api}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true
        ];
        $this->executeCurl($url, $data);
    }

    private function requestData(string $url, array $headers)
    {
        $ch = curl_init($url);
        if ($ch === false) return "Erro: Não foi possível inicializar o cURL.";
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return "Erro ao realizar requisição GET: $error";
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? $response : "Erro na API. Código HTTP: $httpCode. Resposta: " . ($response ?: 'Resposta vazia');
    }

    private function sendResponseFile(int $chatId, string $filePath, string $filename, string $username)
    {
        $url = "{$this->api}/sendDocument";
        $data = [
            'chat_id'  => $chatId,
            'document' => new CURLFile($filePath, 'application/zip', $filename),
            'caption'  => "@$username seu dump foi realizado com sucesso!"
        ];
        return $this->executeCurl($url, $data);
    }

    private function executeCurl(string $url, array $data)
    {
        $ch = curl_init($url);
        if ($ch === false) return false;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    public function processRequest()
    {
        $update = file_get_contents("php://input");
        if (!$update) exit;

        $updateData = json_decode($update, true);
        if (json_last_error() !== JSON_ERROR_NONE) exit;

        if (!isset($updateData['message'])) exit;

        $chatId = $updateData['message']['chat']['id'];
        $messageText = trim($updateData['message']['text'] ?? '');
        $username = $updateData['message']['from']['username'] ?? 'desconhecido';

        if (strpos($messageText, '/') !== 0) {
            exit;
        }

        $parts = explode(' ', $messageText, 2);
        $command = strtolower($parts[0]);
        $argument = $parts[1] ?? '';
        
        if ($chatId != -1002005067008) {
            $msg = "🚫 <b>Acesso Negado</b>\n\n";
            $msg .= "Eu só posso responder neste grupo:\n";
            $msg .= "👉 <a href='https://t.me/DTunnelMod_Group'>DTunnel Mod</a>";
            $this->sendMessage($chatId, $msg, "HTML");
            exit;
        }

        switch ($command) {
            case '/dump':
                $this->handleDumpCommand($chatId, $argument, $username);
                break;
            case '/ajuda':
                $this->sendHelpMessage($chatId);
                break;
            case '/status':
                $this->sendMessage($chatId, "✅ O bot está online!", "HTML");
                break;
        }
    }

    private function handleDumpCommand(int $chatId, string $argument, string $username)
    {
        if (empty($argument)) {
            $this->sendError($chatId, "Nenhum argumento fornecido. Use: /dump dominio.com/<user_id>");
            return;
        }

        $argument = preg_replace('/^https?:\/\//', '', $argument);
        $parts = explode('/', $argument, 2);

        if (count($parts) !== 2) {
            $this->sendError($chatId, "Formato inválido. Use: /dump dominio.com/<user_id>");
            return;
        }

        list($dtunnelUrl, $dtunnelToken) = $parts;
        $dtunnelUrl = trim($dtunnelUrl);
        $dtunnelToken = trim($dtunnelToken);

        if (empty($dtunnelUrl) || empty($dtunnelToken)) {
            $this->sendError($chatId, "Domínio ou token estão vazios.");
            return;
        }

        $apiUrl = "https://$dtunnelUrl/api/dtunnelmod";

        $headers_base = [
            "User-Agent: DTunnelMod (@DTunnelMod, @DTunnelModGroup, @LightXVD)",
            "Dtunnel-Token: $dtunnelToken",
            "Password: DTunnelModSecret-API-9c69a0b72b442ccac3e6aaaa7630d12f2b351fe395e9fe667efa0907cde90da5"
        ];

        $responses = [];
        foreach (["app_config", "app_layout"] as $type) {
            $headers = $headers_base;
            $headers[] = "Dtunnel-Update: $type";
            $response = $this->requestData($apiUrl, $headers);
            if (strpos($response, "Erro") === 0) {
                $this->sendError($chatId, "Erro ao obter $type: $response");
                return;
            }
            $responses[$type] = $response;
        }

        $zip = new ZipArchive();
        $zipFilePath = tempnam(sys_get_temp_dir(), 'zip') . '.zip';
        if ($zip->open($zipFilePath, ZipArchive::CREATE) !== TRUE) {
            $this->sendError($chatId, "Não foi possível criar o arquivo ZIP.");
            return;
        }

        foreach ($responses as $key => $content) {
            $decoded = json_decode($content, true);
            $zip->addFromString("$key.json", json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if ($key === "app_layout") {
                foreach ($decoded as $item) {
                    if (isset($item['name']) && in_array($item['name'], ['APP_LAYOUT_WEBVIEW', 'APP_SUPPORT_BUTTON', 'APP_WEB_VIEW'])) {
                        $zip->addFromString("{$item['name']}.html", $item['value'] ?? '');
                    }
                }
            }
        }

        $zip->close();
        $this->sendResponseFile($chatId, $zipFilePath, "dump.zip", $username);
        unlink($zipFilePath);
    }

    private function sendHelpMessage(int $chatId)
    {
        $msg  = "📌 <b>Comandos disponíveis:</b>\n\n";
        $msg .= "🔹 <b>/ajuda</b> - Lista de comandos\n";
        $msg .= "🔹 <b>/status</b> - Verifica se o bot está online\n";
        $msg .= "🔹 <b>/dump dominio.com/user_id</b> - Dumpa Configurações e Temas";

        $this->sendMessage($chatId, $msg, "HTML");
    }
}

$bot = new DTunnelMod("");
$bot->processRequest();