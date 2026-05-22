<?php
/**
 * Ozone Phrae - Sync Debugger v2.1
 * เครื่องมือตรวจสอบการส่งข้อมูลเข้า Google Sheets
 */
header("Content-Type: text/html; charset=UTF-8");

// ==========================================================
// 📍 ขั้นตอนสำคัญ: วาง Web App URL ระหว่างเครื่องหมาย " " ด้านล่างนี้
// ==========================================================
$google_url = "https://script.google.com/macros/s/AKfycbx4eSYVAVa3ewQnXHmRqoCEOWD3o_Hwz1LSZDzDSMEANGP5MQPRBlxO8v2hIKaS5-TPlw/exec"; 
// ==========================================================

echo "<div style='font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 30px; border: 1px solid #eee; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05);'>";
echo "<h2 style='color: #15803d;'>Ozone Sync Debugger</h2>";

// เปลี่ยนวิธีตรวจสอบให้ฉลาดขึ้น เช็คว่าเป็นลิงก์ของ Google Script จริงหรือไม่
if (empty($google_url) || strpos($google_url, 'script.google.com') === false) {
    echo "<div style='background: #fff1f2; color: #be123c; padding: 20px; border-radius: 15px; border: 1px solid #fecdd3;'>";
    echo "<h3>❌ รูปแบบ URL ไม่ถูกต้อง หรือยังไม่ได้ใส่ URL</h3>";
    echo "<p>กรุณานำ <b>Web App URL</b> ของคุณมาวางระหว่างเครื่องหมาย <code>\" \"</code> ที่ตัวแปร <b>\$google_url</b> (ประมาณบรรทัดที่ 11)</p>";
    echo "<p>ตัวอย่างที่ถูกต้อง:<br> <code>\$google_url = \"https://script.google.com/macros/s/AKfyc.../exec\";</code></p>";
    echo "</div>";
    echo "</div>";
    exit();
}

// ข้อมูลจำลองสำหรับทดสอบ
$test_data = [
    "type" => "Orders",
    "id" => "DEBUG-" . rand(1000, 9999),
    "guest_name" => "ระบบตรวจสอบ (Debug)",
    "guest_phone" => "080-000-0000",
    "guest_province" => "ทดสอบ",
    "check_in" => date('Y-m-d'),
    "check_out" => date('Y-m-d', strtotime('+1 day')),
    "total_price" => 0
];

echo "<p>กำลังพยายามส่งข้อมูลไปที่ Google Sheets...<br><small style='color:#94a3b8;'>URL: " . substr($google_url, 0, 45) . "...</small></p>";

$payload = json_encode($test_data);
$ch = curl_init($google_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // ติดตาม Redirect 302
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch); 
curl_close($ch);

echo "<hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>";

if ($error) {
    echo "<h3 style='color: #e11d48;'>❌ เชื่อมต่อไม่สำเร็จ (cURL Error)</h3>";
    echo "<p><b>สาเหตุ:</b> " . $error . "</p>";
    echo "<p style='font-size: 0.9em; color: #64748b;'><i>คำแนะนำ: ลองตรวจสอบว่า Server ของคุณเปิดใช้งาน cURL หรือไม่ หรือลองติดต่อผู้ให้บริการโฮสติ้ง</i></p>";
} else {
    echo "<h4>ผลการตอบกลับ (Response):</h4>";
    echo "<pre style='background: #f8fafc; padding: 15px; border-radius: 10px; overflow-x: auto; font-size: 13px;'>" . htmlspecialchars($response) . "</pre>";
    
    $result = json_decode($response, true);
    if (isset($result['status']) && $result['status'] === 'success') {
        echo "<div style='background: #f0fdf4; color: #166534; padding: 15px; border-radius: 15px; border: 1px solid #bbf7d0;'>";
        echo "<b>✅ สำเร็จ!</b> ข้อมูลถูกส่งไปที่ Google Sheets เรียบร้อยแล้วครับ กรุณาเปิดเช็คในตาราง 'Orders'";
        echo "</div>";
    } else {
        echo "<div style='background: #fffbeb; color: #92400e; padding: 15px; border-radius: 15px; border: 1px solid #fef3c7;'>";
        echo "<b>⚠️ Google ตอบกลับแต่มีปัญหา:</b> " . ($result['message'] ?? 'ไม่ทราบสาเหตุ');
        echo "<br><br><b>วิธีแก้:</b> ตรวจสอบว่าใน Google Sheets ของคุณมีแผ่นงานที่ชื่อว่า <b>Orders</b> (ต้องสะกดตัวใหญ่ B และมี s) หรือยัง";
        echo "</div>";
    }
}

echo "</div>";
?>