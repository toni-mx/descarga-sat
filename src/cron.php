<?php
namespace PhpCfdi\SatWsDescargaMasiva;

require __DIR__ . '/../vendor/autoload.php';

echo "[Cron] Iniciando ejecución: " . date('Y-m-d H:i:s') . "\n";

try {
    $config = Config::getInstance();
    $db = Database::getInstance();
    $satService = new SatService($config);

    // 1. Check and download pending requests
    $pendingRequests = $db->getPendingRequests();
    echo "[Cron] Encontradas " . count($pendingRequests) . " solicitudes pendientes.\n";

    foreach ($pendingRequests as $req) {
        echo "[Cron] Verificando solicitud {$req['request_id']}...\n";
        try {
            $verifyResult = $satService->verifyRequest($req['request_id']);
            
            if ($verifyResult['status'] === 'finished') {
                echo "[Cron] Solicitud lista. Descargando paquetes...\n";
                $stats = $satService->downloadAndUploadPackages($verifyResult['packages'], $config);
                
                $summary = [
                    'packages' => $verifyResult['packages'],
                    'stats' => $stats
                ];
                
                $db->updateRequestStatus($req['request_id'], 'finished', json_encode($summary));
                echo "[Cron] Paquetes procesados. {$stats['total_xmls']} descargadas, {$stats['new_xmls']} nuevas.\n";
            } elseif ($verifyResult['status'] === 'failed') {
                echo "[Cron] Solicitud fallida o expirada en el SAT.\n";
                $db->updateRequestStatus($req['request_id'], 'failed');
            } else {
                echo "[Cron] Solicitud aún en proceso (aceptada).\n";
                $db->updateRequestStatus($req['request_id'], 'accepted');
            }
        } catch (\Exception $e) {
            echo "[Cron] Error verificando {$req['request_id']}: " . $e->getMessage() . "\n";
        }
    }

    // 2. Automatic daily download
    date_default_timezone_set('America/Mexico_City');
    $todayStart = date('Y-m-d 00:00:00');
    // El SAT rechaza consultas con fechas futuras.
    $todayEnd = date('Y-m-d H:i:s');
    
    // Check if we already have a request for today
    $allRequests = $db->getAllRequests();
    $hasToday = false;
    foreach ($allRequests as $r) {
        if (substr($r['period_start'], 0, 10) === date('Y-m-d')) {
            $hasToday = true;
            break;
        }
    }
    
    if (!$hasToday) {
        echo "[Cron] No se encontró solicitud para el día de hoy. Creando una nueva...\n";
        try {
            $result = $satService->requestDownload($todayStart, $todayEnd, 'received');
            $db->saveRequest($result['request_id'], $todayStart, $todayEnd);
            echo "[Cron] Solicitud automática creada: {$result['request_id']}\n";
        } catch (\Exception $e) {
            echo "[Cron] Error creando solicitud automática: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[Cron] La solicitud para el día de hoy ya existe.\n";
    }

} catch (\Exception $e) {
    echo "[Cron] Error general: " . $e->getMessage() . "\n";
}

echo "[Cron] Ejecución finalizada: " . date('Y-m-d H:i:s') . "\n";
