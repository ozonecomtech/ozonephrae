<?php
/**
 * Ozone Phrae Unified Dashboard API v1.0
 * รวบรวมข้อมูลจากระบบอาหาร และ ระบบจองที่พัก
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// --- การตั้งค่าฐานข้อมูล ---
$host = "localhost";
$db_name = "ozonephr_dashboard";
$username = "ozonephr_dashboard"; 
$password = "Ap2316087*"; // แก้ไขรหัสผ่านของคุณตรงนี้

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Connection Failed"]);
    exit();
}

$response = [];

// 1. สรุปยอดขายอาหาร (Food & Beverage Stats)
// ยอดรวมจากโต๊ะที่จ่ายแล้ว (สมมติ status = 'served' คือสำเร็จ)
$stmtFood = $conn->query("SELECT SUM(total_amount) as total, COUNT(id) as count FROM orders WHERE status != 'cancelled'");
$foodStats = $stmtFood->fetch(PDO::FETCH_ASSOC);
$response['food_revenue'] = (float)($foodStats['total'] ?? 0);
$response['food_orders_count'] = (int)($foodStats['count'] ?? 0);

// ยอดขายแยกตามหมวดหมู่ (JOIN categories + menu_items + order_items)
$sqlCat = "SELECT c.name as label, SUM(oi.price_at_time * oi.quantity) as value 
           FROM order_items oi
           JOIN menu_items m ON oi.menu_item_id = m.id
           JOIN categories c ON m.category_id = c.id
           GROUP BY c.name";
$stmtCat = $conn->query($sqlCat);
$response['food_by_category'] = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

// 2. สรุปยอดจองที่พัก (Booking Stats)
$stmtBook = $conn->query("SELECT SUM(total_price) as total, COUNT(id) as count FROM bookings WHERE status = 'confirmed'");
$bookStats = $stmtBook->fetch(PDO::FETCH_ASSOC);
$response['booking_revenue'] = (float)($bookStats['total'] ?? 0);
$response['booking_count'] = (int)($bookStats['count'] ?? 0);

// อัตราการเข้าพัก (Occupancy) - สมมติเช็คจากวันที่ปัจจุบัน
$today = date('Y-m-d');
$stmtOcc = $conn->prepare("SELECT COUNT(DISTINCT room_id) as booked FROM bookings WHERE status = 'confirmed' AND :today BETWEEN check_in AND check_out");
$stmtOcc->execute(['today' => $today]);
$bookedRooms = $stmtOcc->fetch(PDO::FETCH_ASSOC)['booked'];

$stmtTotalRooms = $conn->query("SELECT COUNT(id) as total FROM rooms WHERE status = 'available'");
$totalRooms = $stmtTotalRooms->fetch(PDO::FETCH_ASSOC)['total'];

$response['occupancy_rate'] = $totalRooms > 0 ? round(($bookedRooms / $totalRooms) * 100, 2) : 0;

// 3. รายการล่าสุด (Recent Activity)
// ออเดอร์อาหารล่าสุด 5 รายการ
$stmtRecentOrders = $conn->query("SELECT customer_name, table_number, total_amount, status, created_at FROM orders ORDER BY created_at DESC LIMIT 5");
$response['recent_orders'] = $stmtRecentOrders->fetchAll(PDO::FETCH_ASSOC);

// การจองที่พักล่าสุด 5 รายการ
$stmtRecentBookings = $conn->query("SELECT guest_name, check_in, total_price, status FROM bookings ORDER BY created_at DESC LIMIT 5");
$response['recent_bookings'] = $stmtRecentBookings->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>