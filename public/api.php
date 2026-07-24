<?php
namespace PhpCfdi\SatWsDescargaMasiva;

// Start output buffering to prevent PHP warnings from breaking JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $data = [], $code = 200) {
    $output = ob_get_clean();
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'debug_log' => $output // Send any PHP warnings/errors to frontend console
    ], $data));
    exit;
}

$action = $_GET['action'] ?? '';
$config = Config::getInstance();
$db = Database::getInstance();

try {
    switch ($action) {
        case 'status':
            $requests = $db->getAllRequests();
            sendResponse(true, '', ['data' => $requests]);
            break;
            
        case 'config':
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                if (isset($input['ftp_host'])) $config->set('FTP_HOST', $input['ftp_host']);
                if (isset($input['ftp_user'])) $config->set('FTP_USER', $input['ftp_user']);
                if (!empty($input['ftp_pass'])) {
                    $config->set('FTP_PASS', $config->encrypt($input['ftp_pass']));
                }
                if (isset($input['ftp_path'])) $config->set('FTP_PATH', $input['ftp_path']);
                
                if (!empty($input['fiel_password'])) {
                    $config->set('FIEL_PASSWORD', $config->encrypt($input['fiel_password']));
                }
                
                if (isset($input['rfc'])) $config->set('RFC', strtoupper($input['rfc']));
                
                if (!empty($input['ciec_password'])) {
                    $config->set('CIEC_PASSWORD', $config->encrypt($input['ciec_password']));
                }
                
                sendResponse(true, 'Configuración guardada correctamente.');
            } else {
                sendResponse(false, 'Datos inválidos', [], 400);
            }
            break;
            
        case 'get_config':
            sendResponse(true, '', [
                'data' => [
                    'ftp_host' => $config->get('FTP_HOST', ''),
                    'ftp_user' => $config->get('FTP_USER', ''),
                    'ftp_path' => $config->get('FTP_PATH', ''),
                    'rfc' => $config->get('RFC', ''),
                    'fiel_password_set' => !empty($config->get('FIEL_PASSWORD')),
                    'ciec_password_set' => !empty($config->get('CIEC_PASSWORD')),
                    'ftp_password_set' => !empty($config->get('FTP_PASS')),
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
            sendResponse(true, 'Archivos de e.firma subidos correctamente');
            break;

        case 'test_ftp':
            $ftpHost = $config->get('FTP_HOST');
            $ftpUser = $config->get('FTP_USER');
            $ftpPassEncrypted = $config->get('FTP_PASS', '');
            
            if (empty($ftpHost) || empty($ftpUser) || empty($ftpPassEncrypted)) {
                sendResponse(false, 'Configura el host, usuario y contraseña de FTP primero.');
            }
            
            $ftpPass = $config->decrypt($ftpPassEncrypted);
            
            // Set 5 seconds timeout to avoid freezing
            $conn_id = @ftp_connect($ftpHost, 21, 5);
            if (!$conn_id) {
                sendResponse(false, "No se pudo conectar al host: {$ftpHost} (Verifica que el IP/Host sea correcto y accesible).");
            }
            
            $login = @ftp_login($conn_id, $ftpUser, $ftpPass);
            if (!$login) {
                @ftp_close($conn_id);
                sendResponse(false, 'Conexión establecida, pero el usuario o contraseña de FTP son incorrectos.');
            }
            
            @ftp_close($conn_id);
            sendResponse(true, 'Conexión FTP exitosa.');
            break;

        case 'test_efirma':
            $cerPath = __DIR__ . '/../config/fiel.cer';
            $keyPath = __DIR__ . '/../config/fiel.key';
            
            if (!file_exists($cerPath) || !file_exists($keyPath)) {
                sendResponse(false, 'No se encontraron los archivos .cer y .key. Súbelos primero.');
            }
            
            $password = $config->decrypt($config->get('FIEL_PASSWORD', ''));
            if (empty($password)) {
                sendResponse(false, 'Ingresa la contraseña de la e.firma primero.');
            }
            
            try {
                $fiel = \PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel::create(
                    file_get_contents($cerPath),
                    file_get_contents($keyPath),
                    $password
                );
                
                if (!$fiel->isValid()) {
                    sendResponse(false, 'La e.firma no es válida (puede estar vencida).');
                } else {
                    sendResponse(true, 'e.firma válida y lista para usarse.');
                }
            } catch (\Throwable $e) {
                sendResponse(false, 'Error al validar e.firma: ' . $e->getMessage());
            }
            break;
            
        case 'test_ciec':
            try {
                $scraperService = new ScraperService($config);
                $scraperService->testCiec();
                sendResponse(true, 'Conexión CIEC y resolución de Captcha exitosa.');
            } catch (\Throwable $e) {
                sendResponse(false, 'Error al probar CIEC: ' . $e->getMessage());
            }
            break;
            
        case 'download_today':
            // Establecer zona horaria oficial del SAT
            date_default_timezone_set('America/Mexico_City');
            
            $satService = new SatService($config);
            $startDate = date('Y-m-d 00:00:00');
            // El SAT rechaza consultas con fechas futuras. Se debe usar la hora exacta actual.
            $endDate = date('Y-m-d H:i:s');
            
            $result = $satService->requestDownload($startDate, $endDate, 'received');
            $db->saveRequest($result['request_id'], $startDate, $endDate);
            
            sendResponse(true, 'Solicitud enviada al SAT', ['request_id' => $result['request_id']]);
            break;

        case 'process_pending':
            $satService = new SatService($config);
            $pendingRequests = $db->getPendingRequests();
            $results = [];
            
            foreach ($pendingRequests as $req) {
                try {
                    $verifyResult = $satService->verifyRequest($req['request_id']);
                    
                    if ($verifyResult['status'] === 'finished') {
                        $stats = $satService->downloadAndUploadPackages($verifyResult['packages'], $config);
                        
                        $summary = [
                            'packages' => $verifyResult['packages'],
                            'stats' => $stats
                        ];
                        
                        $db->updateRequestStatus($req['request_id'], 'finished', json_encode($summary));
                        $results[] = "ID {$req['request_id']}: Finalizado. {$stats['total_xmls']} descargadas, {$stats['new_xmls']} nuevas.";
                    } elseif ($verifyResult['status'] === 'failed') {
                        $db->updateRequestStatus($req['request_id'], 'failed');
                        $results[] = "ID {$req['request_id']}: Rechazado o fallido en el SAT.";
                    } else {
                        $db->updateRequestStatus($req['request_id'], 'accepted');
                        $results[] = "ID {$req['request_id']}: Aún procesándose en el SAT.";
                    }
                } catch (\Throwable $e) {
                    $results[] = "Error en ID {$req['request_id']}: " . $e->getMessage();
                }
            }
            
            sendResponse(true, 'Proceso de pendientes concluido.', ['details' => $results]);
            break;

        case 'audit_year':
            date_default_timezone_set('America/Mexico_City');
            $year = $_GET['year'] ?? date('Y');
            $type = $_GET['type'] ?? 'received';
            
            // To respect SAT constraints, we can request the whole year at once 
            // since SAT limits to 200,000 CFDIs per query, which is fine for most small/medium businesses.
            $startDate = "$year-01-01 00:00:00";
            
            // If it's current year, we can't request future dates
            $endDate = ($year == date('Y')) ? date('Y-m-d H:i:s') : "$year-12-31 23:59:59";
            
            $satService = new SatService($config);
            $result = $satService->requestDownload($startDate, $endDate, $type, 'active');
            $db->saveRequest($result['request_id'], $startDate, $endDate);
            
            sendResponse(true, "Auditoría encolada para el año $year", ['request_id' => $result['request_id']]);
            break;
            
        case 'download_cancelled':
            date_default_timezone_set('America/Mexico_City');
            
            // Default to downloading cancelled invoices for the last 30 days
            $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
            $endDate = date('Y-m-d H:i:s');
            
            $scraperService = new ScraperService($config);
            $stats = $scraperService->download($startDate, $endDate, 'cancelled');
            
            sendResponse(true, 'Facturas canceladas descargadas correctamente usando CIEC', ['stats' => $stats]);
            break;
            
        case 'download_ciec_instant':
            date_default_timezone_set('America/Mexico_City');
            
            // Descargar lo del mes actual hasta la fecha actual
            $startDate = date('Y-m-01 00:00:00');
            $endDate = date('Y-m-d H:i:s');
            
            $scraperService = new ScraperService($config);
            $stats = $scraperService->download($startDate, $endDate, 'active');
            
            // Log to database as a finished request
            $db->saveRequest('CIEC-' . uniqid(), $startDate, $endDate, 'finished');
            
            sendResponse(true, 'Facturas de este mes descargadas instantáneamente con CIEC', ['stats' => $stats]);
            break;
            
        case 'analytics':
            $ftpStats = $db->getFtpStats();
            $requests = $db->getAllRequests();
            
            // Aggregate request stats
            $requestStats = ['finished' => 0, 'failed' => 0, 'pending' => 0, 'accepted' => 0];
            foreach ($requests as $req) {
                if (isset($requestStats[$req['status']])) {
                    $requestStats[$req['status']]++;
                }
            }
            
            sendResponse(true, 'Analíticas cargadas', [
                'ftp' => $ftpStats,
                'requests' => $requestStats
            ]);
            break;
            
        case 'sync_analytics':
            $ftpHost = $config->get('FTP_HOST');
            $ftpUser = $config->get('FTP_USER');
            $ftpPassEncrypted = $config->get('FTP_PASS', '');
            
            if (empty($ftpHost) || empty($ftpUser) || empty($ftpPassEncrypted)) {
                sendResponse(false, 'Configura el FTP primero para sincronizar analíticas.');
            }
            
            $ftpPass = $config->decrypt($ftpPassEncrypted);
            $ftpPath = $config->get('FTP_PATH', '/');
            
            $conn_id = @ftp_connect($ftpHost, 21, 5);
            if (!$conn_id || !@ftp_login($conn_id, $ftpUser, $ftpPass)) {
                sendResponse(false, 'No se pudo conectar al FTP.');
            }
            
            ftp_pasv($conn_id, true);
            if ($ftpPath !== '/') {
                @ftp_chdir($conn_id, ltrim($ftpPath, '/'));
            }
            
            $years = ftp_nlist($conn_id, ".");
            if ($years === false) $years = [];
            
            $syncResults = [];
            foreach ($years as $yearDir) {
                // Ignore '.' and '..' and non-year folders
                $year = basename($yearDir);
                if (preg_match('/^20\d{2}$/', $year)) {
                    $months = ftp_nlist($conn_id, $yearDir);
                    if ($months) {
                        foreach ($months as $monthDir) {
                            $monthStr = basename($monthDir);
                            $monthNum = (int)$monthStr;
                            if ($monthNum >= 1 && $monthNum <= 12) {
                                // List files
                                $files = ftp_nlist($conn_id, $monthDir);
                                $xmlCount = 0;
                                if ($files) {
                                    foreach ($files as $file) {
                                        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xml') {
                                            $xmlCount++;
                                        }
                                    }
                                }
                                
                                $periodKey = sprintf("%04d-%02d", (int)$year, $monthNum);
                                $db->saveFtpStat($periodKey, $xmlCount);
                                $syncResults[] = "[$periodKey: $xmlCount]";
                            }
                        }
                    }
                }
            }
            
            @ftp_close($conn_id);
            sendResponse(true, 'Sincronización completada', ['synced' => count($syncResults)]);
            break;
            
        default:
            sendResponse(false, 'Acción no encontrada', [], 404);
            break;
    }
} catch (\Throwable $e) {
    sendResponse(false, $e->getMessage(), ['trace' => $e->getTraceAsString()], 500);
}
