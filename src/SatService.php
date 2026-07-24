<?php
namespace PhpCfdi\SatWsDescargaMasiva;

use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\FielRequestBuilder;
use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\WebClient\GuzzleWebClient;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryParameters;
use PhpCfdi\SatWsDescargaMasiva\Shared\DateTimePeriod;
use PhpCfdi\SatWsDescargaMasiva\Shared\DownloadType;
use PhpCfdi\SatWsDescargaMasiva\Shared\RequestType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentStatus;
use PhpCfdi\SatWsDescargaMasiva\PackageReader\CfdiPackageReader;

class SatService
{
    private Service $service;
    
    public function __construct(Config $config)
    {
        $cerPath = __DIR__ . '/../config/fiel.cer';
        $keyPath = __DIR__ . '/../config/fiel.key';
        
        if (!file_exists($cerPath) || !file_exists($keyPath)) {
            throw new \Exception("Los archivos de la FIEL (.cer y .key) no se encontraron en el directorio config/");
        }
        
        $password = $config->decrypt($config->get('FIEL_PASSWORD', ''));
        if (empty($password)) {
            throw new \Exception("La contraseña de la FIEL no está configurada o es inválida.");
        }
        
        $fiel = Fiel::create(
            file_get_contents($cerPath),
            file_get_contents($keyPath),
            $password
        );
        
        if (!$fiel->isValid()) {
            throw new \Exception("La FIEL no es válida o está vencida.");
        }
        
        $webClient = new GuzzleWebClient();
        $requestBuilder = new FielRequestBuilder($fiel);
        $this->service = new Service($requestBuilder, $webClient);
    }
    
    public function requestDownload(string $startDate, string $endDate, string $type = 'received', string $docStatus = 'active'): array
    {
        $downloadType = $type === 'received' ? DownloadType::received() : DownloadType::issued();
        
        $statusEnum = $docStatus === 'cancelled' ? DocumentStatus::cancelled() : DocumentStatus::active();
        
        $request = QueryParameters::create()
            ->withPeriod(DateTimePeriod::createFromValues($startDate, $endDate))
            ->withDownloadType($downloadType)
            ->withRequestType(RequestType::xml())
            ->withDocumentStatus($statusEnum); // SAT restringe descargas que incluyen XML cancelados y activos mezclados.
            
        $query = $this->service->query($request);
        
        if (!$query->getStatus()->isAccepted()) {
            throw new \Exception("Fallo al presentar la consulta: " . $query->getStatus()->getMessage());
        }
        
        return [
            'request_id' => $query->getRequestId(),
            'message' => $query->getStatus()->getMessage()
        ];
    }
    
    public function verifyRequest(string $requestId): array
    {
        $verify = $this->service->verify($requestId);
        
        if (!$verify->getStatus()->isAccepted()) {
            throw new \Exception("Fallo al verificar la consulta {$requestId}: " . $verify->getStatus()->getMessage());
        }
        
        $statusRequest = $verify->getStatusRequest();
        
        $status = 'pending';
        if ($statusRequest->isExpired() || $statusRequest->isFailure() || $statusRequest->isRejected()) {
            $status = 'failed';
        } elseif ($statusRequest->isFinished()) {
            $status = 'finished';
        } elseif ($statusRequest->isInProgress() || $statusRequest->isAccepted()) {
            $status = 'accepted';
        }
        
        return [
            'status' => $status,
            'packages' => $verify->getPackagesIds(),
            'message' => $verify->getCodeRequest()->getMessage()
        ];
    }
    
    private function getMonthName(string $monthNumber): string
    {
        $months = [
            '01' => 'enero', '02' => 'febrero', '03' => 'marzo',
            '04' => 'abril', '05' => 'mayo', '06' => 'junio',
            '07' => 'julio', '08' => 'agosto', '09' => 'septiembre',
            '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'
        ];
        return $months[$monthNumber] ?? 'desconocido';
    }

    private function ftp_mksubdirs($conn_id, $basePath, $targetFolder)
    {
        @ftp_chdir($conn_id, $basePath);
        $parts = explode('/', trim($targetFolder, '/'));
        foreach ($parts as $part) {
            if (!@ftp_chdir($conn_id, $part)) {
                ftp_mkdir($conn_id, $part);
                ftp_chdir($conn_id, $part);
            }
        }
    }

    public function downloadAndUploadPackages(array $packagesIds, Config $config): array
    {
        $ftpHost = $config->get('FTP_HOST');
        $ftpUser = $config->get('FTP_USER');
        $ftpPass = $config->decrypt($config->get('FTP_PASS', ''));
        $ftpPath = $config->get('FTP_PATH', '/');
        
        $conn_id = null;
        if (!empty($ftpHost)) {
            $conn_id = ftp_connect($ftpHost, 21, 5);
            if (!$conn_id || !ftp_login($conn_id, $ftpUser, $ftpPass)) {
                throw new \Exception("No se pudo conectar al FTP.");
            }
            ftp_pasv($conn_id, true);
        }
        
        $stats = [
            'total_xmls' => 0,
            'new_xmls' => 0,
            'new_uuids' => []
        ];
        
        foreach ($packagesIds as $packageId) {
            $download = $this->service->download($packageId);
            if (!$download->getStatus()->isAccepted()) {
                continue; // Skip failed downloads
            }
            
            // Save zip temporarily
            $zipPath = sys_get_temp_dir() . '/' . $packageId . '.zip';
            file_put_contents($zipPath, $download->getPackageContent());
            
            // Extract XMLs and upload
            try {
                $cfdiReader = CfdiPackageReader::createFromFile($zipPath);
                
                foreach ($cfdiReader->cfdis() as $uuid => $content) {
                    $stats['total_xmls']++;
                    
                    if ($conn_id) {
                        // Extract date from XML
                        $year = date('Y');
                        $month = date('m');
                        if (preg_match('/Fecha="(\d{4})-(\d{2})-\d{2}T/', $content, $matches)) {
                            $year = $matches[1];
                            $month = $matches[2];
                        }
                        
                        $monthName = $this->getMonthName($month);
                        $targetFolder = $year . '/' . $monthName;
                        
                        // Create directories and navigate to them
                        $this->ftp_mksubdirs($conn_id, $ftpPath, $targetFolder);
                        
                        // Check if file already exists
                        $size = @ftp_size($conn_id, "$uuid.xml");
                        
                        if ($size === -1) {
                            // File does not exist, so it is new!
                            $stats['new_xmls']++;
                            $stats['new_uuids'][] = [
                                'uuid' => $uuid,
                                'date' => "$year-$month"
                            ];
                            
                            $xmlPath = sys_get_temp_dir() . "/$uuid.xml";
                            file_put_contents($xmlPath, $content);
                            ftp_put($conn_id, "$uuid.xml", $xmlPath, FTP_ASCII);
                            @unlink($xmlPath);
                        }
                        
                        // Return to base path
                        @ftp_chdir($conn_id, $ftpPath);
                    }
                }
                
                // Forzar liberación del archivo ZIP para evitar Resource temporarily unavailable
                unset($cfdiReader);
                
            } catch (\Exception $e) {
                // Ignore zip errors
            }
            
            // Reintentar unlink unas cuantas veces si sigue bloqueado
            for ($i = 0; $i < 3; $i++) {
                if (@unlink($zipPath)) break;
                usleep(500000); // .5 seconds
            }
        }
        
        if ($conn_id) {
            ftp_close($conn_id);
        }
        
        return $stats;
    }
}
