#!/usr/bin/env php
<?php
/**
 * Frontend Page Verification Tests
 * Checks if all pages load correctly with proper structure
 */

class FrontendPageTests {
    private $passed = 0;
    private $failed = 0;
    private $baseUrl = 'http://localhost/autobot';
    
    public function run() {
        echo "🌐 Frontend Page Verification\n";
        echo "===============================\n\n";
        
        echo "📌 Testing Customer Portal Pages...\n";
        $this->testPage('/public/login.html', ['AI Automation', 'Email', 'Password']);

        // Main customer pages are PHP now
        $this->testPage('/public/dashboard.php', ['Dashboard']);
        $this->testPage('/public/services.php', ['บริการ']);
        $this->testPage('/public/usage.php', ['การใช้งาน']);
        $this->testPage('/public/api-docs.php', ['API']);
        $this->testPage('/public/payment.php', ['ชำระเงิน']);
        $this->testPage('/public/billing.php', ['ใบแจ้งหนี้']);
        $this->testPage('/public/profile.php', ['โปรไฟล์']);

        // New customer pages
        $this->testPage('/public/chat-history.php', ['ประวัติการสนทนา']);
        $this->testPage('/public/addresses.php', ['ที่อยู่จัดส่ง']);
        $this->testPage('/public/orders.php', ['คำสั่งซื้อ']);
        $this->testPage('/public/payment-history.php', ['ประวัติการชำระ']);

        echo "\n📌 Testing Admin Panel Pages...\n";
        $this->testPage('/public/admin/login.html', ['Admin', 'Password']);
        $this->testPage('/public/admin/index.php', ['Dashboard']);
        
        echo "\n===============================\n";
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        
        echo "Results: ";
        echo "\033[32m{$this->passed} passed\033[0m / ";
        echo "\033[31m{$this->failed} failed\033[0m\n";
        echo "Success Rate: {$percentage}%\n";
        
        return $this->failed === 0;
    }
    
    private function testPage($path, $expectedContent = []) {
        $url = $this->baseUrl . $path;
        $pageName = basename($path);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Check HTTP 200
        if ($httpCode !== 200) {
            $this->fail("{$pageName} - HTTP {$httpCode}");
            return;
        }
        
        // Check for expected content
        $allFound = true;
        $missing = [];
        
        foreach ($expectedContent as $content) {
            if (stripos($html, $content) === false) {
                $allFound = false;
                $missing[] = $content;
            }
        }
        
        // Check for viewport meta tag
        if (stripos($html, 'viewport') === false) {
            $this->fail("{$pageName} - Missing viewport meta tag");
            return;
        }
        
        // Check for CSS
        if (stripos($html, 'style.css') === false) {
            $this->fail("{$pageName} - Missing CSS");
            return;
        }
        
        if ($allFound) {
            $this->pass("{$pageName}");
        } else {
            $this->fail("{$pageName} - Missing: " . implode(', ', $missing));
        }
    }
    
    private function pass($test) {
        echo "  \033[32m✓\033[0m {$test}\n";
        $this->passed++;
    }
    
    private function fail($test) {
        echo "  \033[31m✗\033[0m {$test}\n";
        $this->failed++;
    }
}

// Run tests
$tests = new FrontendPageTests();
$success = $tests->run();

exit($success ? 0 : 1);
