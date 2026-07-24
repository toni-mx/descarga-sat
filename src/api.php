<?php
namespace PhpCfdi\SatWsDescargaMasiva\App;

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$config = Config::getInstance();
$db = Database::getInstance();

try {
    switch ($action) {
        case 'status':
            $requests = $db->getAllRequests();
            echo json_encode(['success' => true, 'data' => $requests]);
            break;
            
        case 'config':
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                if (isset($input['ftp_host'])) $config->set('FTP_HOST', $input['ftp_host']);
                if (isset($input['ftp_user'])) $config->set('FTP_USER', $input['ftp_user']);
                if (isset($input['ftp_pass'])) $config->set('FTP_PASS', $input['ftp_pass']);
                if (isset($input['ftp_path'])) $config->set('FTP_PATH', $input['ftp_path']);
                
                if (!empty($input['fiel_password'])) {
                    $encrypted = $config->encrypt($input['fiel_password']);
                    $config->set('FIEL_PASSWORD', $encrypted);
                }
                echo json_encode(['success' => true, 'message' => 'Configuración guardada']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid data']);
            }
            break;
            
        case 'get_config':
            echo json_encode([
                'success' => true,
                'data' => [
                    'ftp_host' => $config->get('FTP_HOST', ''),
                    'ftp_user' => $config->get('FTP_USER', ''),
                    'ftp_pass' => $config->get('FTP_PASS', ''),
                    'ftp_path' => $config->get('FTP_PATH', ''),
                    'fiel_password_set' => !empty($config->get('FIEL_PASSWORD'))
                ]
            ]);
            break;

        case 'upload_fiel':
            $cer = $_FILES['cer_file'] ?? null;
            $key = $_FILES['key_file'] ?? null;
            $configDir = __DIR__ . '/../config';
            
            if (!is_dir($configDir)) {
                mkdir($configDir, 0777, true);
            }
            
            if ($cer && $cer['error'] === UPLOAD_ERR_OK) {
                move_uploaded_file($cer['tmp_name'], $configDir . '/fiel.cer');
            }
            if ($key && $key['error'] === UPLOAD_ERR_OK) {
                move_uploaded_file($key['tmp_name'], $configDir . '/fiel.key');
            }
            echo json_encode(['success' => true, 'message' => 'Archivos de FIEL subidos correctamente']);
            break;
            
        case 'download_today':
            $satService = new SatService($config);
            // SAT requires start date < end date. So we ask for today 00:00:00 to 23:59:59
            $startDate = date('Y-m-d 00:00:00');
            $endDate = date('Y-m-d 23:59:59');
            
            $result = $satService->requestDownload($startDate, $endDate, 'received');
            
            $db->saveRequest($result['request_id'], $startDate, $endDate);
            
            echo json_encode(['success' => true, 'message' => 'Solicitud enviada al SAT', 'request_id' => $result['request_id']]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no encontrada']);
            break;
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
