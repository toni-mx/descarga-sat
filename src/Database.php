<?php
namespace PhpCfdi\SatWsDescargaMasiva;

use PDO;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private string $dbPath;

    private function __construct()
    {
        $this->dbPath = __DIR__ . '/../data';
        if (!is_dir($this->dbPath)) {
            mkdir($this->dbPath, 0777, true);
        }
        
        $this->pdo = new PDO('sqlite:' . $this->dbPath . '/db.sqlite');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    private function init(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                request_type TEXT NOT NULL DEFAULT 'Desconocida',
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                packages TEXT,
                xml_count INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Add columns if they don't exist
        try { $this->pdo->exec("ALTER TABLE requests ADD COLUMN request_type TEXT DEFAULT 'Desconocida'"); } catch (\Exception $e) {}
        try { $this->pdo->exec("ALTER TABLE requests ADD COLUMN xml_count INTEGER DEFAULT 0"); } catch (\Exception $e) {}

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ftp_stats (
                month TEXT PRIMARY KEY,
                xml_count INTEGER NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    public function saveRequest(string $requestId, string $start, string $end, string $status = 'pending', string $requestType = 'Desconocida'): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO requests (request_id, request_type, period_start, period_end, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$requestId, $requestType, $start, $end, $status]);
        
        // Log to file
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . '/requests.log';
        $date = date('Y-m-d H:i:s');
        $logLine = "[$date] TIPO: $requestType | ID: $requestId | PERIODO: $start al $end | ESTADO: $status\n";
        file_put_contents($logFile, $logLine, FILE_APPEND);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    public function clearRequests(): void
    {
        $this->pdo->exec("DELETE FROM requests");
        
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . '/requests.log';
        $date = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$date] --- HISTORIAL LIMPIADO POR EL USUARIO ---\n", FILE_APPEND);
    }
    
    public function updateRequestStatus(string $requestId, string $status, ?string $packages = null, int $xmlCount = 0): void
    {
        $stmt = $this->pdo->prepare("UPDATE requests SET status = ?, packages = ?, xml_count = ? WHERE request_id = ?");
        $stmt->execute([$status, $packages, $xmlCount, $requestId]);
        
        // Log status update
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . '/requests.log';
        $date = date('Y-m-d H:i:s');
        $logLine = "[$date] UPDATE | ID: $requestId | ESTADO: $status | XMLS: $xmlCount\n";
        file_put_contents($logFile, $logLine, FILE_APPEND);
    }
    
    public function getPendingRequests(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM requests WHERE status IN ('pending', 'accepted')");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getAllRequests(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM requests ORDER BY created_at DESC LIMIT 50");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getFtpStats(): array
    {
        $stmt = $this->pdo->query("SELECT month, xml_count FROM ftp_stats ORDER BY month ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function saveFtpStat(string $month, int $count): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ftp_stats (month, xml_count, updated_at) 
            VALUES (?, ?, CURRENT_TIMESTAMP) 
            ON CONFLICT(month) DO UPDATE SET 
            xml_count = excluded.xml_count, 
            updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$month, $count]);
    }
}
