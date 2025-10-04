<?php
/**
 * Lấy "Tin Mừng ngày hôm nay" từ Vatican News (tiếng Việt)
 */

class LoiChuaFetcher {
    private $vatican_url = 'https://www.vaticannews.va/vi/loi-chua-hang-ngay.html';
    private $cache_file = 'cache/loi_chua_cache.json';
    
    public function __construct() {
        if (!file_exists('cache')) {
            mkdir('cache', 0755, true);
        }
    }
    
    /**
     * Lấy tin mừng ngày hôm nay
     */
    public function fetchTinMung() {
        // Kiểm tra cache trước
        $cached_data = $this->getCachedData();
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        try {
            $html = $this->fetchPageContent();
            if (!$html) {
                return $this->getFallbackMessage();
            }
            
            $tin_mung = $this->parseTinMung($html);
            $this->cacheData($tin_mung);
            
            return $tin_mung;
            
        } catch (Exception $e) {
            error_log("Lỗi khi lấy lời chúa: " . $e->getMessage());
            return $this->getFallbackMessage();
        }
    }
    
    /**
     * Lấy nội dung trang web bằng cURL
     */
    private function fetchPageContent() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->vatican_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Accept-Language: vi-VN,vi;q=0.9']
        ]);
        
        $html = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($http_code === 200 && $html) ? $html : false;
    }
    
    /**
     * Parse HTML để lấy nội dung tin mừng
     */
    private function parseTinMung($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Tìm section chứa "Tin Mừng ngày hôm nay"
        $sections = $xpath->query("//section[.//h2[contains(normalize-space(text()), 'Tin Mừng ngày hôm nay')]]");
        
        if ($sections->length > 0) {
            $contents = $xpath->query(".//div[contains(@class, 'section__content')]", $sections->item(0));
            if ($contents->length > 0) {
                $text = $this->cleanText($contents->item(0)->textContent);
                return "Tin Mừng ngày hôm nay\n\n" . $text;
            }
        }
        
        // Fallback: tìm bài viết đầu tiên
        $articles = $xpath->query("//article[contains(@class, 'teaser')] | //li//article | //article[contains(@class, 'article--list')]");
        if ($articles->length > 0) {
            $text = $this->cleanText($articles->item(0)->textContent);
            return "Tin Mừng ngày hôm nay\n\n" . $text;
        }
        
        return $this->getFallbackMessage();
    }
    
    /**
     * Làm sạch text
     */
    private function cleanText($text) {
        $text = preg_replace('/\s+/', ' ', trim($text));
        return (strlen($text) > 2000) ? substr($text, 0, 2000) . '...' : $text;
    }
    
    /**
     * Lấy dữ liệu từ cache
     */
    private function getCachedData() {
        if (!file_exists($this->cache_file)) {
            return null;
        }
        
        $cache_data = json_decode(file_get_contents($this->cache_file), true);
        if (!$cache_data || ($cache_data['date'] ?? '') !== date('Y-m-d')) {
            return null;
        }
        
        return $cache_data['content'];
    }
    
    /**
     * Lưu dữ liệu vào cache
     */
    private function cacheData($content) {
        file_put_contents($this->cache_file, json_encode([
            'timestamp' => time(),
            'date' => date('Y-m-d'),
            'content' => $content
        ], JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Thông báo fallback khi không lấy được dữ liệu
     */
    private function getFallbackMessage() {
        return "Tin Mừng ngày hôm nay (" . date('d/m/Y') . ")\n\n" . 
               "Xin lỗi, hiện tại không thể tải được nội dung lời chúa từ Vatican News. " .
               "Vui lòng thử lại sau hoặc truy cập trực tiếp tại: " . $this->vatican_url;
    }
}

// Test trực tiếp (chỉ khi gọi file này)
if (basename($_SERVER['PHP_SELF']) === 'loi_chua_fetcher.php') {
    echo "<h2>Lời Chúa Hàng Ngày</h2>";
    echo "<pre>" . htmlspecialchars((new LoiChuaFetcher())->fetchTinMung()) . "</pre>";
}
?>
