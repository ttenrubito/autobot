<?php
// Quick check for LIFF ID status

require_once __DIR__ . '/includes/Database.php';

try {
    $db = Database::getInstance()->getPdo();
    
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "🔍 ตรวจสอบสถานะ LIFF ID\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    // Check campaigns table
    $stmt = $db->query("
        SELECT id, code, name, liff_id, is_active 
        FROM campaigns 
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($campaigns)) {
        echo "❌ ไม่พบแคมเปญที่ active อยู่\n\n";
        exit(1);
    }
    
    echo "แคมเปญที่ active:\n\n";
    
    $hasLiffId = false;
    foreach ($campaigns as $idx => $campaign) {
        $num = $idx + 1;
        $liffStatus = empty($campaign['liff_id']) ? '❌ ไม่มี' : '✅ มี';
        
        echo "{$num}. {$campaign['name']} (Code: {$campaign['code']})\n";
        echo "   LIFF ID: {$liffStatus}";
        
        if (!empty($campaign['liff_id'])) {
            echo " → {$campaign['liff_id']}\n";
            $hasLiffId = true;
        } else {
            echo " → NULL\n";
        }
        echo "\n";
    }
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    if ($hasLiffId) {
        echo "✅ สถานะ: มี LIFF ID อยู่แล้ว\n";
        echo "📱 ในแชท LINE ควรเห็นลิงก์: https://liff.line.me/...\n\n";
        echo "ถ้าไม่เห็นลิงก์ → อาจจะยังไม่ deploy code ล่าสุด\n";
        echo "ถ้าเห็นลิงก์แต่คลิกแล้ว error → ต้องสร้าง LIFF frontend HTML\n\n";
    } else {
        echo "⚠️  สถานะ: ยังไม่มี LIFF ID\n";
        echo "📱 ในแชท LINE จะเห็นข้อความ:\n";
        echo '   "📱 พิมพ์ \"สมัคร TEST2026\" เพื่อเริ่มกรอกใบสมัคร"' . "\n\n";
        echo "🔧 ต้องทำ: Setup LIFF ID (15 นาที)\n\n";
        echo "ขั้นตอน:\n";
        echo "1. ไปที่ https://developers.line.biz/console/\n";
        echo "2. สร้าง LIFF App (5 นาที)\n";
        echo "3. Update database ด้วยคำสั่ง:\n\n";
        echo "   UPDATE campaigns SET liff_id = 'YOUR_LIFF_ID' \n";
        echo "   WHERE code = 'TEST2026';\n\n";
    }
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
