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
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                packages TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    public function saveRequest(string $requestId, string $start, string $end, string $status = 'pending'): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO requests (request_id, period_start, period_end, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$requestId, $start, $end, $status]);
        return (int)$this->pdo->lastInsertId();
    }
    
    public function updateRequestStatus(string $requestId, string $status, ?string $packages = null): void
    {
        $stmt = $this->pdo->prepare("UPDATE requests SET status = ?, packages = ? WHERE request_id = ?");
        $stmt->execute([$status, $packages, $requestId]);
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
}
