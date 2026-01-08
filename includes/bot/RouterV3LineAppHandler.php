<?php
// filepath: /opt/lampp/htdocs/autobot/includes/bot/RouterV3LineAppHandler.php

require_once __DIR__ . '/BotHandlerInterface.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Logger.php';

/**
 * RouterV3LineAppHandler
 * 
 * LINE Application Automation System Handler
 * Manages conversation flow for LINE-based application/registration system
 * 
 * Key Features:
 * - Check application status for LINE user
 * - Guide through multi-step form process
 * - Handle document uploads
 * - Send status updates
 * - Re-upload workflow for incomplete applications
 * 
 * @version 3.0
 * @production-ready
 */
class RouterV3LineAppHandler implements BotHandlerInterface
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Handle incoming message
     * 
     * @param array $context {
     *   'channel_id' => int,
     *   'external_user_id' => string, // LINE userId
     *   'platform' => string, // 'line'
     *   'message' => array,
     *   'config' => array,
     *   'meta' => array
     * }
     * @return array Response with 'text' or 'messages'
     */
    public function handleMessage(array $context): array
    {
        try {
            Logger::info('[ROUTER_V3_LINEAPP] Start', [
                'channel_id' => $context['channel_id'] ?? null,
                'external_user_id' => $context['external_user_id'] ?? null,
                'platform' => $context['platform'] ?? null
            ]);
            
            $lineUserId = $context['external_user_id'] ?? null;
            $message = $context['message'] ?? [];
            $messageText = $message['text'] ?? '';
            
            if (!$lineUserId) {
                return [
                    'reply_text' => 'ไม่สามารถระบุตัวตนได้ กรุณาลองใหม่อีกครั้ง'
                ];
            }
            
            // Check if user has any active applications
            $stmt = $this->db->prepare("
                SELECT id, application_no, campaign_id, campaign_name, status, substatus
                FROM line_applications
                WHERE line_user_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            
            $stmt->execute([$lineUserId]);
            $latestApplication = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If no application exists, handle general conversation
            if (!$latestApplication) {
                // Detect message intent
                $textLower = mb_strtolower($messageText, 'UTF-8');
                
                // Greeting keywords
                if (preg_match('/(สวัสดี|หวัดดี|ดีครับ|ดีค่ะ|hello|hi|ว่าไง|เฮ้|เฮลโล)/u', $textLower)) {
                    return [
                        'reply_text' => "สวัสดีค่ะ! ยินดีต้อนรับ 😊\n\nต้องการความช่วยเหลืออะไรดีคะ?\n\n• พิมพ์ \"แคมเปญ\" หรือ \"สมัคร\" - ดูแคมเปญที่เปิดรับสมัคร\n• พิมพ์ \"ช่วย\" - ดูคำแนะนำการใช้งาน\n• พิมพ์ \"ติดต่อ\" - ติดต่อเจ้าหน้าที่"
                    ];
                }
                
                // Help keywords
                if (preg_match('/(ช่วย|help|ช่วยเหลือ|คำแนะนำ|guide|ใช้งาน|วิธี)/u', $textLower)) {
                    return [
                        'reply_text' => "📖 วิธีใช้งานง่ายๆ ค่ะ\n\n1️⃣ พิมพ์ \"แคมเปญ\" เพื่อดูรายการที่เปิดรับสมัคร\n2️⃣ คลิกลิงก์สมัครที่ได้รับ หรือพิมพ์ \"สมัคร [ชื่อแคมเปญ]\"\n3️⃣ กรอกข้อมูลในฟอร์มให้ครบถ้วน\n4️⃣ รอการตรวจสอบจากทีมงาน\n\n💬 มีคำถามเพิ่มเติม? พิมพ์ \"ติดต่อ\" เพื่อคุยกับเจ้าหน้าที่ได้เลยค่ะ"
                    ];
                }
                
                // Campaign list keywords
                if (preg_match('/(แคมเปญ|campaign|รายการ|สมัคร|list|ดู|มีอะไรบ้าง|เปิดรับ)/u', $textLower)) {
                    return $this->showCampaignList($lineUserId);
                }
                
                // Contact/Support keywords
                if (preg_match('/(ติดต่อ|contact|สอบถาม|ถาม|คุย|admin|เจ้าหน้าที่)/u', $textLower)) {
                    return [
                        'reply_text' => "📞 ติดต่อเจ้าหน้าที่\n\nหากต้องการสอบถามข้อมูลเพิ่มเติม เจ้าหน้าที่จะติดต่อกลับไปให้เร็วๆ นี้ค่ะ\n\nหรือสามารถโทรสอบถามได้ที่:\n☎️ 02-XXX-XXXX (จ-ศ 9:00-17:00)\n\n💬 พิมพ์ข้อความของคุณได้เลยค่ะ เจ้าหน้าที่จะรับเรื่องให้"
                    ];
                }
                
                // Application status check keywords
                if (preg_match('/(สถานะ|status|ตรวจสอบ|check|เช็ค|ติดตาม)/u', $textLower)) {
                    return [
                        'reply_text' => "ตรวจสอบสถานะใบสมัคร 🔍\n\nขออภัยค่ะ คุณยังไม่มีใบสมัครในระบบ\n\nต้องการสมัครใหม่?\n• พิมพ์ \"แคมเปญ\" เพื่อดูรายการที่เปิดรับสมัคร"
                    ];
                }
                
                // Default: offer options with better UX
                return [
                    'reply_text' => "ขอโทษนะคะ ไม่ค่อยเข้าใจคำถามของคุณ 😅\n\nลองเลือกจากตัวเลือกนี้ดูนะคะ:\n\n📋 \"แคมเปญ\" - ดูรายการที่เปิดรับสมัคร\n❓ \"ช่วย\" - ดูวิธีใช้งาน\n📞 \"ติดต่อ\" - คุยกับเจ้าหน้าที่\n\nหรือลองพิมพ์คำถามของคุณใหม่อีกครั้งก็ได้ค่ะ 💬"
                ];
            }
            
            // Route based on current application status
            $status = $latestApplication['status'];
            $appNo = $latestApplication['application_no'];
            
            // Handle "check status" request even when have application
            if (preg_match('/(สถานะ|status|ตรวจสอบ|check|เช็ค|ติดตาม)/u', mb_strtolower($messageText, 'UTF-8'))) {
                return $this->showApplicationStatus($latestApplication);
            }
            
            switch ($status) {
                case 'RECEIVED':
                case 'FORM_INCOMPLETE':
                    return $this->handleFormFlow($latestApplication, $messageText);
                    
                case 'DOC_PENDING':
                    return $this->handleDocumentRequest($latestApplication);
                    
                case 'INCOMPLETE':
                    return $this->handleReuploadFlow($latestApplication, $message);
                    
                case 'OCR_PROCESSING':
                    return [
                        'reply_text' => "กำลังตรวจสอบเอกสารของคุณอยู่นะคะ ⏳\n\n📋 เลขที่: {$appNo}\n📊 สถานะ: กำลังประมวลผล OCR\n\nระบบจะแจ้งผลให้ทราบภายใน 5-10 นาทีค่ะ\nรบกวนรอสักครู่นะคะ 😊"
                    ];
                    
                case 'OCR_DONE':
                case 'NEED_REVIEW':
                    return [
                        'reply_text' => "เอกสารของคุณอยู่ระหว่างการตรวจสอบค่ะ 👀\n\n📋 เลขที่: {$appNo}\n📊 สถานะ: กำลังตรวจสอบโดยเจ้าหน้าที่\n\nจะแจ้งผลให้ทราบโดยเร็วที่สุดนะคะ ขอบคุณที่รอค่ะ 🙏"
                    ];
                    
                case 'APPROVED':
                    return [
                        'reply_text' => "🎉 ยินดีด้วยค่ะ!\n\nใบสมัครของคุณผ่านการอนุมัติแล้ว\n\n📋 เลขที่: {$appNo}\n✅ สถานะ: อนุมัติ\n\n📌 กรุณาเก็บเลขนี้ไว้และนำมาในวันนัดหมายนะคะ\n\nมีคำถาม? พิมพ์ \"ติดต่อ\" เพื่อคุยกับเจ้าหน้าที่ได้เลยค่ะ"
                    ];
                    
                case 'REJECTED':
                    $reason = $latestApplication['substatus'] ?? '';
                    $reasonText = $reason ? "\n💭 เหตุผล: {$reason}\n" : '';
                    return [
                        'reply_text' => "😔 ขออภัยค่ะ\n\nใบสมัครของคุณยังไม่ผ่านการพิจารณา\n\n📋 เลขที่: {$appNo}{$reasonText}\nหากต้องการสมัครใหม่ หรือสอบถามข้อมูลเพิ่มเติม\nพิมพ์ \"ติดต่อ\" เพื่อคุยกับเจ้าหน้าที่ได้นะคะ"
                    ];
                    
                default:
                    return $this->showApplicationStatus($latestApplication);
            }
            
        } catch (Exception $e) {
            Logger::error('[ROUTER_V3_LINEAPP] Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'reply_text' => 'ขออภัยค่ะ เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้งหรือติดต่อเจ้าหน้าที่ค่ะ'
            ];
        }
    }
    
    /**
     * Show available campaigns for user to apply
     */
    private function showCampaignList(string $lineUserId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, code, name, description, liff_id, line_rich_menu_id
            FROM campaigns
            WHERE is_active = 1
                AND (start_date IS NULL OR start_date <= CURDATE())
                AND (end_date IS NULL OR end_date >= CURDATE())
            ORDER BY created_at DESC
            LIMIT 5
        ");
        
        $stmt->execute();
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($campaigns)) {
            return [
                'reply_text' => "ขณะนี้ยังไม่มีแคมเปญที่เปิดรับสมัครนะคะ 😊\n\nลองกลับมาดูใหม่ภายหลังนะคะ หรือติดต่อเจ้าหน้าที่เพื่อสอบถามข้อมูลเพิ่มเติมได้ค่ะ"
            ];
        }
        
        // Build campaign list with better formatting
        $text = "😊 สวัสดีค่ะ! มีแคมเปญที่เปิดรับสมัครอยู่นะคะ\n\n";
        
        $hasLiffUrl = false;
        
        foreach ($campaigns as $idx => $campaign) {
            $campaignNum = $idx + 1;
            $liffId = $campaign['liff_id'] ?? null;
            
            // Campaign name and description
            $text .= "━━━━━━━━━━━━━━━\n";
            $text .= "📋 {$campaign['name']}\n";
            
            if (!empty($campaign['description'])) {
                $text .= "   💬 {$campaign['description']}\n";
            }
            
            // Add LIFF URL if available
            if ($liffId && !empty($liffId)) {
                // ใช้ fragment (#) แทน query param (?) เพราะ fragment จะไม่หายตอน LIFF redirect
                $liffUrl = "https://liff.line.me/{$liffId}#campaign=" . urlencode($campaign['code']);
                $text .= "\n";
                $text .= "   👉 สมัครเลย: {$liffUrl}\n";
                $hasLiffUrl = true;
            } else {
                $text .= "\n";
                $text .= "   📱 พิมพ์ \"สมัคร {$campaign['code']}\" เพื่อเริ่มกรอกใบสมัครค่ะ\n";
            }
            
            $text .= "\n";
        }
        
        $text .= "━━━━━━━━━━━━━━━\n\n";
        
        // Add helpful footer
        if ($hasLiffUrl) {
            $text .= "💡 คลิกลิงก์ด้านบนเพื่อเริ่มกรอกใบสมัครได้เลยค่ะ\n\n";
        }
        
        $text .= "ต้องการความช่วยเหลือ?\n";
        $text .= "• พิมพ์ \"ช่วยเหลือ\" - ดูคำแนะนำ\n";
        $text .= "• พิมพ์ \"ติดต่อ\" - ติดต่อเจ้าหน้าที่";
        
        return ['reply_text' => $text];
    }
    
    /**
     * Handle form filling flow
     */
    private function handleFormFlow(array $application, string $messageText): array
    {
        $appNo = $application['application_no'];
        $campaignName = $application['campaign_name'];
        $liffId = null;
        
        // Try to get LIFF ID from campaign
        try {
            $stmt = $this->db->prepare("SELECT liff_id FROM campaigns WHERE id = ?");
            $stmt->execute([$application['campaign_id']]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            $liffId = $campaign['liff_id'] ?? null;
        } catch (Exception $e) {
            Logger::error('[ROUTER_V3] Failed to get LIFF ID', ['error' => $e->getMessage()]);
        }
        
        $message = "กรุณากรอกข้อมูลให้ครบถ้วนนะคะ 📝\n\n";
        $message .= "📋 เลขที่: {$appNo}\n";
        $message .= "🎯 แคมเปญ: {$campaignName}\n\n";
        
        if ($liffId && !empty($liffId)) {
            $liffUrl = "https://liff.line.me/{$liffId}?app={$appNo}";
            $message .= "👉 คลิกลิงก์นี้เพื่อกรอกฟอร์ม:\n{$liffUrl}\n\n";
            $message .= "หรือคลิกเมนูด้านล่างก็ได้ค่ะ";
        } else {
            $message .= "📱 กรุณาคลิกเมนู \"กรอกฟอร์ม\" ด้านล่างเพื่อดำเนินการต่อค่ะ\n\n";
            $message .= "หรือพิมพ์ \"ติดต่อ\" เพื่อขอความช่วยเหลือจากเจ้าหน้าที่";
        }
        
        return ['reply_text' => $message];
    }
    
    /**
     * Handle document upload request
     */
    private function handleDocumentRequest(array $application): array
    {
        $appNo = $application['application_no'];
        $liffId = null;
        
        // Try to get LIFF ID
        try {
            $stmt = $this->db->prepare("SELECT liff_id FROM campaigns WHERE id = ?");
            $stmt->execute([$application['campaign_id']]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            $liffId = $campaign['liff_id'] ?? null;
        } catch (Exception $e) {
            Logger::error('[ROUTER_V3] Failed to get LIFF ID', ['error' => $e->getMessage()]);
        }
        
        $message = "กรุณาอัปโหลดเอกสารนะคะ 📄\n\n";
        $message .= "📋 เลขที่: {$appNo}\n";
        $message .= "📌 สถานะ: รอการอัปโหลดเอกสาร\n\n";
        
        if ($liffId && !empty($liffId)) {
            $liffUrl = "https://liff.line.me/{$liffId}?app={$appNo}&step=upload";
            $message .= "👉 คลิกลิงก์นี้เพื่ออัปโหลดเอกสาร:\n{$liffUrl}\n\n";
        } else {
            $message .= "📱 คลิกเมนู \"อัปโหลดเอกสาร\" ด้านล่าง\n\n";
        }
        
        $message .= "💡 เอกสารที่ต้องใช้:\n";
        $message .= "• บัตรประชาชน\n";
        $message .= "• ทะเบียนบ้าน\n";
        $message .= "• เอกสารอื่นๆ ตามที่ระบุในฟอร์ม";
        
        return ['reply_text' => $message];
    }
    
    /**
     * Handle re-upload flow for incomplete applications
     */
    private function handleReuploadFlow(array $application, array $message): array
    {
        $appNo = $application['application_no'];
        $substatus = $application['substatus'] ?? 'กรุณาส่งเอกสารเพิ่มเติม';
        $liffId = null;
        
        // Try to get LIFF ID
        try {
            $stmt = $this->db->prepare("SELECT liff_id FROM campaigns WHERE id = ?");
            $stmt->execute([$application['campaign_id']]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            $liffId = $campaign['liff_id'] ?? null;
        } catch (Exception $e) {
            Logger::error('[ROUTER_V3] Failed to get LIFF ID', ['error' => $e->getMessage()]);
        }
        
        $msg = "ต้องการเอกสารเพิ่มเติมนะคะ 📄\n\n";
        $msg .= "📋 เลขที่: {$appNo}\n";
        $msg .= "💭 ข้อความจากเจ้าหน้าที่:\n   {$substatus}\n\n";
        
        if ($liffId && !empty($liffId)) {
            $liffUrl = "https://liff.line.me/{$liffId}?app={$appNo}&step=reupload";
            $msg .= "👉 คลิกลิงก์นี้เพื่ออัปโหลดเอกสารเพิ่ม:\n{$liffUrl}\n\n";
        } else {
            $msg .= "📱 คลิกเมนู \"ส่งเอกสารเพิ่ม\" ด้านล่าง\n\n";
        }
        
        $msg .= "ต้องการความช่วยเหลือ? พิมพ์ \"ติดต่อ\" ได้เลยค่ะ";
        
        return ['reply_text' => $msg];
    }
    
    /**
     * Show current application status
     */
    private function showApplicationStatus(array $application): array
    {
        $statusMap = [
            'RECEIVED' => ['emoji' => '📥', 'text' => 'ได้รับใบสมัครแล้ว'],
            'FORM_INCOMPLETE' => ['emoji' => '📝', 'text' => 'กรอกฟอร์มยังไม่ครบ'],
            'DOC_PENDING' => ['emoji' => '📄', 'text' => 'รอการอัปโหลดเอกสาร'],
            'OCR_PROCESSING' => ['emoji' => '⏳', 'text' => 'กำลังประมวลผลเอกสาร'],
            'OCR_DONE' => ['emoji' => '✅', 'text' => 'ประมวลผลเสร็จสิ้น'],
            'NEED_REVIEW' => ['emoji' => '👀', 'text' => 'อยู่ระหว่างตรวจสอบ'],
            'APPROVED' => ['emoji' => '🎉', 'text' => 'อนุมัติแล้ว'],
            'REJECTED' => ['emoji' => '❌', 'text' => 'ไม่ผ่านการพิจารณา'],
            'INCOMPLETE' => ['emoji' => '📋', 'text' => 'ต้องการเอกสารเพิ่มเติม'],
            'EXPIRED' => ['emoji' => '⏰', 'text' => 'หมดอายุ']
        ];
        
        $status = $application['status'];
        $statusInfo = $statusMap[$status] ?? ['emoji' => '📌', 'text' => $status];
        
        $message = "━━━ สถานะใบสมัคร ━━━\n\n";
        $message .= "{$statusInfo['emoji']} {$statusInfo['text']}\n\n";
        $message .= "📋 เลขที่: {$application['application_no']}\n";
        $message .= "🎯 แคมเปญ: {$application['campaign_name']}\n";
        
        if (!empty($application['substatus'])) {
            $message .= "💭 หมายเหตุ: {$application['substatus']}\n";
        }
        
        $message .= "\n━━━━━━━━━━━━━━━\n\n";
        
        // Add helpful next steps based on status
        switch ($status) {
            case 'FORM_INCOMPLETE':
                $message .= "💡 ต้องการ: กรอกฟอร์มให้ครบ\n";
                $message .= "พิมพ์ \"กรอกฟอร์ม\" เพื่อดำเนินการต่อ";
                break;
            case 'DOC_PENDING':
                $message .= "💡 ต้องการ: อัปโหลดเอกสาร\n";
                $message .= "พิมพ์ \"อัปโหลด\" เพื่ออัปโหลดเอกสาร";
                break;
            case 'INCOMPLETE':
                $message .= "💡 ต้องการ: ส่งเอกสารเพิ่มเติม\n";
                $message .= "พิมพ์ \"ส่งเอกสาร\" เพื่อดำเนินการ";
                break;
            case 'OCR_PROCESSING':
            case 'NEED_REVIEW':
                $message .= "💡 กรุณารอ: จะแจ้งผลให้ทราบเร็วๆ นี้";
                break;
            case 'APPROVED':
                $message .= "💡 ขั้นตอนถัดไป: นำเลขนี้มาในวันนัดหมาย";
                break;
            default:
                $message .= "💬 ต้องการความช่วยเหลือ?\nพิมพ์ \"ติดต่อ\" เพื่อคุยกับเจ้าหน้าที่";
        }
        
        return ['reply_text' => $message];
    }
    
    /**
     * Build notification message based on status
     */
    private function buildNotificationMessage(string $applicationNo, string $status, array $options = []): string
    {
        $templates = [
            'APPROVED' => "🎉 ยินดีด้วยค่ะ!\n\nใบสมัครเลขที่ %s ได้รับการอนุมัติแล้ว\n\nกรุณานำเลขนี้มาในวันนัดหมายค่ะ",
            
            'REJECTED' => "😔 ขออภัยค่ะ\n\nใบสมัครเลขที่ %s ไม่ผ่านการพิจารณา\n\nเหตุผล: %s\n\nหากต้องการสมัครใหม่ สามารถติดต่อเจ้าหน้าที่ได้ค่ะ",
            
            'INCOMPLETE' => "📄 ขอเอกสารเพิ่มเติมค่ะ\n\nใบสมัครเลขที่ %s\n\n%s\n\nกรุณาอัปโหลดเอกสารผ่านเมนู 'ส่งเอกสารเพิ่ม' ค่ะ",
            
            'APPOINTMENT' => "📅 นัดหมาย\n\nใบสมัครเลขที่ %s\n\nวันเวลา: %s\nสถานที่: %s\n\n%s\n\nกรุณามาตรงเวลาค่ะ",
        ];
        
        switch ($status) {
            case 'APPROVED':
                return sprintf($templates['APPROVED'], $applicationNo);
                
            case 'REJECTED':
                $reason = $options['reason'] ?? 'ไม่ระบุเหตุผล';
                return sprintf($templates['REJECTED'], $applicationNo, $reason);
                
            case 'INCOMPLETE':
                $message = $options['message'] ?? 'กรุณาส่งเอกสารเพิ่มเติม';
                return sprintf($templates['INCOMPLETE'], $applicationNo, $message);
                
            case 'APPOINTMENT':
                return sprintf(
                    $templates['APPOINTMENT'],
                    $applicationNo,
                    $options['appointment_date'] ?? '-',
                    $options['appointment_location'] ?? '-',
                    $options['appointment_note'] ?? ''
                );
                
            default:
                return sprintf(
                    "📋 อัปเดตสถานะใบสมัคร\n\nเลขที่: %s\nสถานะ: %s",
                    $applicationNo,
                    $status
                );
        }
    }
}
