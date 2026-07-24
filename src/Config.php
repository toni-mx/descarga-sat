<?php
namespace PhpCfdi\SatWsDescargaMasiva;

use Dotenv\Dotenv;

class Config
{
    private static ?Config $instance = null;
    private array $settings = [];
    private string $envPath;
    private string $masterKeyPath;
    
    private function __construct()
    {
        $this->envPath = __DIR__ . '/../config';
        $this->masterKeyPath = $this->envPath . '/master.key';
        $this->load();
    }
    
    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new Config();
        }
        return self::$instance;
    }
    
    private function load(): void
    {
        if (file_exists($this->envPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($this->envPath);
            $this->settings = $dotenv->load();
        }
    }
    
    public function get(string $key, $default = null)
    {
        return $this->settings[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
    
    public function set(string $key, string $value): void
    {
        $this->settings[$key] = $value;
        $this->saveEnv();
    }
    
    private function saveEnv(): void
    {
        if (!is_dir($this->envPath)) {
            mkdir($this->envPath, 0777, true);
        }
        $content = "";
        foreach ($this->settings as $k => $v) {
            $content .= "{$k}={$v}\n";
        }
        file_put_contents($this->envPath . '/.env', $content);
    }
    
    private function getMasterKey(): string
    {
        if (!file_exists($this->masterKeyPath)) {
            // Generate a random 32-byte key
            $key = bin2hex(random_bytes(16));
            if (!is_dir($this->envPath)) {
                mkdir($this->envPath, 0777, true);
            }
            file_put_contents($this->masterKeyPath, $key);
        }
        return file_get_contents($this->masterKeyPath);
    }
    
    public function encrypt(string $data): string
    {
        $key = $this->getMasterKey();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    public function decrypt(string $data): string
    {
        $key = $this->getMasterKey();
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    }
}
