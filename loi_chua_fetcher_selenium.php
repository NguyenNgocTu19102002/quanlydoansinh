<?php
/**
 * Lấy "Tin Mừng ngày hôm nay" từ Vatican News (tiếng Việt)
 * Mô phỏng logic của Selenium WebDriver
 */

class LoiChuaFetcherSelenium {
    private $vatican_url = 'https://www.vaticannews.va/vi/loi-chua-hang-ngay.html';
    private $cache_file = 'cache/loi_chua_cache.json';
    
    public function __construct() {
        if (!file_exists('cache')) {
            mkdir('cache', 0755, true);
        }
    }
    
    /**
     * Lấy tin mừng ngày hôm nay (mô phỏng Selenium)
     */
    public function fetchTinMung() {
        // Kiểm tra cache trước
        $cached_data = $this->getCachedData();
        if ($cached_data !== null) {
            return $cached_data;
        }
        
        try {
            // Mô phỏng Selenium: thử nhiều lần với delay
            $html = $this->fetchWithRetry();
            if (!$html) {
                return $this->getFallbackMessage();
            }
            
            // Parse theo logic của Selenium
            $tin_mung = $this->parseLikeSelenium($html);
            
            // Cache kết quả
            $this->cacheData($tin_mung);
            
            return $tin_mung;
            
        } catch (Exception $e) {
            error_log("Lỗi khi lấy lời chúa: " . $e->getMessage());
            return $this->getFallbackMessage();
        }
    }
    
    /**
     * Mô phỏng Selenium: thử nhiều lần với delay
     */
    private function fetchWithRetry() {
        // Thử 3 lần như Selenium với WebDriverWait
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            error_log("Selenium simulation - Attempt $attempt");
            
            $html = $this->fetchPageContent();
            if (!$html) {
                if ($attempt < 3) {
                    sleep(2); // Delay như Selenium
                    continue;
                }
                return false;
            }
            
            // Kiểm tra xem có nội dung lời chúa không (như Selenium check visibility)
            if ($this->hasGospelContent($html)) {
                error_log("Found gospel content on attempt $attempt");
                return $html;
            }
            
            // Nếu không tìm thấy, thử lại
            if ($attempt < 3) {
                error_log("No gospel content found on attempt $attempt, retrying...");
                sleep(3); // Delay 3 giây như Selenium
            }
        }
        
        return false;
    }
    
    /**
     * Lấy nội dung trang web (mô phỏng Selenium)
     */
    private function fetchPageContent() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->vatican_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25, // Như Selenium Duration.ofSeconds(25)
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: vi-VN,vi;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Cache-Control: no-cache',
                'Pragma: no-cache'
            ],
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_COOKIEJAR => tempnam(sys_get_temp_dir(), 'vatican_cookies'),
            CURLOPT_COOKIEFILE => tempnam(sys_get_temp_dir(), 'vatican_cookies')
        ]);
        
        $html = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $http_code !== 200 || !$html) {
            error_log("cURL failed: HTTP $http_code, Error: $error");
            return false;
        }
        
        return $html;
    }
    
    /**
     * Kiểm tra xem HTML có chứa nội dung lời chúa không (mô phỏng Selenium visibility check)
     */
    private function hasGospelContent($html) {
        // Kiểm tra các từ khóa như Selenium
        $keywords = ['tin mừng', 'phúc âm', 'lời chúa', 'gospel', 'evangelium'];
        foreach ($keywords as $keyword) {
            if (stripos($html, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Parse HTML theo logic của Selenium
     */
    private function parseLikeSelenium($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Logic 1: Tìm section chứa H2 "Tin Mừng ngày hôm nay" (như Selenium)
        try {
            $sections = $xpath->query("//section[.//h2[contains(normalize-space(text()), 'Tin Mừng ngày hôm nay')]]");
            if ($sections->length > 0) {
                $contents = $xpath->query(".//div[contains(@class, 'section__content')]", $sections->item(0));
                if ($contents->length > 0) {
                    $text = $this->cleanText($contents->item(0)->textContent);
                    if (strlen($text) > 50) {
                        error_log("Found content with Selenium logic 1");
                        return "Tin Mừng ngày hôm nay\n" . $text;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Selenium logic 1 failed: " . $e->getMessage());
        }
        
        // Logic 2: Fallback - lấy bài đầu tiên (như Selenium fallback)
        try {
            $articles = $xpath->query("//article[contains(@class, 'teaser')] | //li//article | //article[contains(@class, 'article--list')]");
            if ($articles->length > 0) {
                $text = $this->cleanText($articles->item(0)->textContent);
                if (strlen($text) > 50) {
                    error_log("Found content with Selenium fallback");
                    return "Tin Mừng ngày hôm nay\n" . $text;
                }
            }
        } catch (Exception $e) {
            error_log("Selenium fallback failed: " . $e->getMessage());
        }
        
        // Logic 3: Tìm tất cả nội dung có từ khóa
        $allText = $dom->textContent;
        if (stripos($allText, 'tin mừng') !== false || stripos($allText, 'phúc âm') !== false) {
            $text = $this->cleanText($allText);
            if (strlen($text) > 100) {
                error_log("Found content with keyword search");
                return "Tin Mừng ngày hôm nay\n" . substr($text, 0, 1000) . "...";
            }
        }
        
        error_log("No gospel content found with any method");
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
     * Thông báo fallback
     */
    private function getFallbackMessage() {
        return "Tin Mừng ngày hôm nay (" . date('d/m/Y') . ")\n\n" . 
               "Xin lỗi, hiện tại không thể tải được nội dung lời chúa từ Vatican News. " .
               "Vui lòng thử lại sau hoặc truy cập trực tiếp tại: " . $this->vatican_url;
    }
}

// Test trực tiếp
if (basename($_SERVER['PHP_SELF']) === 'loi_chua_fetcher_selenium.php') {
    echo "<h2>Lời Chúa Hàng Ngày (Selenium Simulation)</h2>";
    echo "<pre>" . htmlspecialchars((new LoiChuaFetcherSelenium())->fetchTinMung()) . "</pre>";
}
?>
