<?php
namespace PhpCfdi\SatWsDescargaMasiva;

use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\Sessions\Ciec\CiecSessionManager;
use PhpCfdi\ImageCaptchaResolver\BoxFacturaAI\BoxFacturaAIResolver;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Filters\Options\StatesVoucherOption;

class ScraperService {
    private Config $config;
    
    public function __construct(Config $config) {
        $this->config = $config;
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

    public function testCiec() {
        $rfc = $this->config->get('RFC');
        $password = $this->config->decrypt($this->config->get('CIEC_PASSWORD', ''));
        
        if (empty($rfc) || empty($password)) {
            throw new \Exception("Configura tu RFC y contraseña CIEC en los Ajustes primero.");
        }
        
        $configsFile = __DIR__ . '/../storage/sat-captcha-ai-model/configs.yaml';
        if (!file_exists($configsFile)) {
            throw new \Exception("No se encontró el modelo de Inteligencia Artificial para resolver captchas.");
        }
        
        $captchaResolver = BoxFacturaAIResolver::createFromConfigs($configsFile);
        
        $satScraper = new SatScraper(
            CiecSessionManager::create($rfc, $password, $captchaResolver)
        );
        
        // This will attempt to login and resolve captcha
        $satScraper->confirmSessionIsAlive();
        
        return true;
    }

    public function download(string $startDate, string $endDate, string $type = 'cancelled') {
        $rfc = $this->config->get('RFC');
        $password = $this->config->decrypt($this->config->get('CIEC_PASSWORD', ''));
        
        if (empty($rfc) || empty($password)) {
            throw new \Exception("Configura tu RFC y contraseña CIEC en los Ajustes primero.");
        }
        
        $configsFile = __DIR__ . '/../storage/sat-captcha-ai-model/configs.yaml';
        if (!file_exists($configsFile)) {
            throw new \Exception("No se encontró el modelo de Inteligencia Artificial para resolver captchas.");
        }
        
        $captchaResolver = BoxFacturaAIResolver::createFromConfigs($configsFile);
        
        $satScraper = new SatScraper(
            CiecSessionManager::create($rfc, $password, $captchaResolver)
        );
        
        // El Scraper usa DateTimeImmutable
        $query = new QueryByFilters(
            \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate),
            \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate)
        );
        $query->setDownloadType(DownloadType::recibidos());
        
        if ($type === 'cancelled') {
            $query->setStateVoucher(StatesVoucherOption::cancelados());
        } else if ($type === 'active') {
            $query->setStateVoucher(StatesVoucherOption::vigentes());
        }
        
        // Conectar al SAT (aquí es donde la IA resuelve el captcha de fondo)
        $list = $satScraper->listByFilters($query);
        
        if ($list->count() === 0) {
            return ['total_xmls' => 0, 'new_xmls' => 0, 'new_uuids' => []];
        }
        
        // En lugar de descargar todo a RAM, usaremos el ResourceDownloader a un tempDir
        $tempDir = sys_get_temp_dir() . '/sat_sync_scraper_' . uniqid();
        if (!is_dir($tempDir)) mkdir($tempDir);
        
        $downloader = $satScraper->resourceDownloader();
        $downloader->setMetadataList($list);
        $downloader->saveTo($tempDir);
        
        // Proceso de FTP
        $ftpHost = $this->config->get('FTP_HOST');
        $ftpUser = $this->config->get('FTP_USER');
        $ftpPass = $this->config->decrypt($this->config->get('FTP_PASS', ''));
        $ftpPath = $this->config->get('FTP_PATH', '/');
        
        $conn_id = null;
        if (!empty($ftpHost)) {
            $conn_id = ftp_connect($ftpHost, 21, 5);
            if (!$conn_id || !ftp_login($conn_id, $ftpUser, $ftpPass)) {
                throw new \Exception("No se pudo conectar al FTP.");
            }
            ftp_pasv($conn_id, true);
        }
        
        $stats = ['total_xmls' => 0, 'new_xmls' => 0, 'new_uuids' => []];
        
        $files = glob($tempDir . '/*.xml');
        foreach($files as $file) {
            $stats['total_xmls']++;
            $uuid = basename($file, '.xml');
            $content = file_get_contents($file);
            
            // Extract date from XML
            $year = date('Y');
            $month = date('m');
            if (preg_match('/Fecha="(\d{4})-(\d{2})-\d{2}T/', $content, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
            }
            
            if ($month === '01') $monthName = 'enero';
            else if ($month === '02') $monthName = 'febrero';
            else if ($month === '03') $monthName = 'marzo';
            else if ($month === '04') $monthName = 'abril';
            else if ($month === '05') $monthName = 'mayo';
            else if ($month === '06') $monthName = 'junio';
            else if ($month === '07') $monthName = 'julio';
            else if ($month === '08') $monthName = 'agosto';
            else if ($month === '09') $monthName = 'septiembre';
            else if ($month === '10') $monthName = 'octubre';
            else if ($month === '11') $monthName = 'noviembre';
            else if ($month === '12') $monthName = 'diciembre';
            
            // 1. Local Storage Backup
            $localBase = __DIR__ . '/../data/facturas';
            $localFolder = $localBase . '/' . $year . '/' . $monthName;
            if (!is_dir($localFolder)) {
                mkdir($localFolder, 0777, true);
            }
            $localPath = $localFolder . '/' . $uuid . '.xml';
            
            // Save locally if it doesn't exist
            if (!file_exists($localPath)) {
                file_put_contents($localPath, $content);
            }
            
            // 2. FTP Upload
            if ($conn_id) {
                $targetFolder = $year . '/' . $monthName;
                
                // Create directories and navigate to them
                @ftp_chdir($conn_id, $ftpPath);
                $parts = explode('/', trim($targetFolder, '/'));
                foreach ($parts as $part) {
                    if (!@ftp_chdir($conn_id, $part)) {
                        ftp_mkdir($conn_id, $part);
                        ftp_chdir($conn_id, $part);
                    }
                }
                
                // Get existing files
                $existingFiles = [];
                $rawFiles = ftp_nlist($conn_id, ".");
                if (is_array($rawFiles)) {
                    foreach ($rawFiles as $f) {
                        $existingFiles[] = basename($f);
                    }
                }
                
                if (!in_array("$uuid.xml", $existingFiles)) {
                    $stats['new_xmls']++;
                    $stats['new_uuids'][] = [
                        'uuid' => $uuid,
                        'date' => "$year-$month"
                    ];
                    
                    ftp_put($conn_id, "$uuid.xml", $localPath, FTP_ASCII);
                }
                
                // Return to base path
                @ftp_chdir($conn_id, $ftpPath);
            }
            unlink($file);
        }
        rmdir($tempDir);
        
        if ($conn_id) {
            ftp_close($conn_id);
        }
        
        return $stats;
    }
}
