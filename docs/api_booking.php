<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// จัดการ OPTIONS สำหรับ CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 📍 1. Web App URL จาก Google Apps Script
$google_url = "https://script.google.com/macros/s/AKfycbyX_12QBnPKU6atdYtcHVBc1MApc9uFXBEGV-i6aheUJy7YOqAT60MhMxoLtYDZc5R-/exec"; 

// --- การตั้งค่าฐานข้อมูล ---
$host = "localhost";
$db_name = "ozonephr_dashboard"; 
$username = "ozonephr_dashboard";     
$password = "Ap2316087*"; 

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => "DB Connection Error: " . $e->getMessage()]);
    exit();
}

/**
 * ฟังก์ชันส่งข้อมูลไป Google Sheets ผ่าน cURL
 */
function syncToGoogleSheets($url, $data) {
    if (empty($url) || strpos($url, 'script.google.com') === false) return false;
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$target = $_GET['target'] ?? '';

// --- [GET] ดึงข้อมูล ---
if ($method == 'GET') {
    if ($action == 'all_bookings') {
        $stmt = $conn->query("SELECT b.*, r.name as room_name, r.type as room_type, r.price as room_price FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id ORDER BY b.created_at DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } 
    else if ($action == 'all_rooms') {
        $stmt = $conn->query("SELECT * FROM rooms ORDER BY id DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    }
    else if (($target == 'room' && $action == 'delete_room') || $action == 'delete_room') {
        $id = $_GET['id'] ?? null;
        if($id) {
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id=?");
            if($stmt->execute([$id])) {
                echo json_encode(["status" => "success", "message" => "ลบที่พักสำเร็จ"]);
            } else {
                echo json_encode(["status" => "error", "message" => "ไม่สามารถลบได้"]);
            }
        }
    }
    else if (isset($_GET['check_in']) && isset($_GET['check_out'])) {
        $check_in = $_GET['check_in'];
        $check_out = $_GET['check_out'];
        $sql = "SELECT r.*, 
                (SELECT COUNT(*) FROM bookings b WHERE b.room_id = r.id AND b.status != 'canceled' AND NOT (b.check_out <= :in OR b.check_in >= :out)) as is_booked
                FROM rooms r WHERE r.status = 'available'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':in' => $check_in, ':out' => $check_out]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    } 
    else {
        $stmt = $conn->query("SELECT * FROM rooms WHERE status = 'available'");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    }
}

// --- [POST] บันทึกข้อมูลใหม่ / อัปโหลด ---
if ($method == 'POST') {
    $json_data = json_decode(file_get_contents("php://input"), true);
    
    // กรณีจัดการข้อมูลห้องพัก (เพิ่ม/แก้ไข) พร้อมจัดการรูปภาพ
    if ($target == 'room' || $target == 'room_upload') {
        $id = $_POST['id'] ?? $json_data['id'] ?? '';
        $name = $_POST['name'] ?? $json_data['name'] ?? '';
        $type = $_POST['type'] ?? $json_data['type'] ?? '';
        $capacity = $_POST['capacity'] ?? $json_data['capacity'] ?? 0;
        $price = $_POST['price'] ?? $json_data['price'] ?? 0;
        $deposit_amount = $_POST['deposit_amount'] ?? $json_data['deposit_amount'] ?? 100;
        $description = $_POST['description'] ?? $json_data['description'] ?? '';
        $facilities = $_POST['facilities'] ?? $json_data['facilities'] ?? '';
        $status = $_POST['status'] ?? $json_data['status'] ?? 'available';
        
        // รับรายชื่อรูปที่จะลบ (ส่งมาจากหน้าบ้านเป็น JSON string)
        $deleted_images_json = $_POST['deleted_images'] ?? '[]';
        $deleted_images = json_decode($deleted_images_json, true);

        $upload_dir = 'uploads/rooms/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

        // 1. ดึงข้อมูลเดิมจาก Database ก่อน
        $image_url = '';
        $current_gallery = [];
        if (!empty($id)) {
            $stmt_old = $conn->prepare("SELECT image_url, gallery_images FROM rooms WHERE id = ?");
            $stmt_old->execute([$id]);
            $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
            $image_url = $old_data['image_url'] ?? '';
            
            $gallery_raw = $old_data['gallery_images'] ?? '[]';
            $current_gallery = is_string($gallery_raw) ? json_decode($gallery_raw, true) : (is_array($gallery_raw) ? $gallery_raw : []);
            if (!is_array($current_gallery)) $current_gallery = [];
        }

        // 2. จัดการการลบรูปภาพเดิม (Physical Delete & Filter Array)
        if (!empty($deleted_images) && is_array($deleted_images)) {
            foreach ($deleted_images as $del_url) {
                // ลบไฟล์ออกจาก Server
                if (file_exists($del_url)) {
                    unlink($del_url);
                }
                // ลบออกจากรายการ Gallery ปัจจุบัน
                if (($key = array_search($del_url, $current_gallery)) !== false) {
                    unset($current_gallery[$key]);
                }
            }
            $current_gallery = array_values($current_gallery); // จัดเรียง index ใหม่
        }

        // 3. อัปโหลดรูปหลักใหม่ (ถ้ามี)
        if (isset($_FILES['primary_image']) && $_FILES['primary_image']['error'] == 0) {
            // ลบรูปหลักเก่าถ้ามี
            if (!empty($image_url) && file_exists($image_url)) unlink($image_url);
            
            $ext = pathinfo($_FILES['primary_image']['name'], PATHINFO_EXTENSION);
            $new_name = 'room_main_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['primary_image']['tmp_name'], $upload_dir . $new_name)) {
                $image_url = $upload_dir . $new_name;
            }
        }

        // 4. อัปโหลดรูปประกอบใหม่เพิ่ม (Multiple Upload)
        if (isset($_FILES['gallery_images'])) {
            $files = $_FILES['gallery_images'];
            $file_count = is_array($files['name']) ? count($files['name']) : 0;

            for ($i = 0; $i < $file_count; $i++) {
                if ($files['error'][$i] == 0) {
                    $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                    $new_gallery_name = 'room_gal_' . time() . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $new_gallery_name)) {
                        $current_gallery[] = $upload_dir . $new_gallery_name;
                    }
                }
            }
        }

        $gallery_images_final = json_encode($current_gallery, JSON_UNESCAPED_UNICODE);

        // 5. บันทึกลง Database
        if (empty($id)) {
            $sql = "INSERT INTO rooms (name, type, capacity, price, deposit_amount, image_url, gallery_images, description, facilities, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $success = $stmt->execute([$name, $type, $capacity, $price, $deposit_amount, $image_url, $gallery_images_final, $description, $facilities, $status]);
        } else {
            $sql = "UPDATE rooms SET name=?, type=?, capacity=?, price=?, deposit_amount=?, image_url=?, gallery_images=?, description=?, facilities=?, status=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $success = $stmt->execute([$name, $type, $capacity, $price, $deposit_amount, $image_url, $gallery_images_final, $description, $facilities, $status, $id]);
        }
        
        echo json_encode(["status" => $success ? "success" : "error", "message" => $success ? "บันทึกข้อมูลเรียบร้อย" : "เกิดข้อผิดพลาดในการบันทึก"]);
    } 
    // กรณีบันทึกการจองใหม่
    else {
        $data = (object)$json_data;
        if(!empty($data->room_id) && !empty($data->guest_name)) {
            try {
                $stmtRoom = $conn->prepare("SELECT name, type FROM rooms WHERE id = ? LIMIT 1");
                $stmtRoom->execute([$data->room_id]);
                $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
                
                $room_label = $room ? $room['name'] . " (" . $room['type'] . ")" : "ID: " . $data->room_id;
                $guest_province = !empty($data->guest_province) ? $data->guest_province : (!empty($data->province) ? $data->province : '-');
                $total_price = floatval($data->total_price ?? 0);
                $deposit = isset($data->deposit_amount) ? floatval($data->deposit_amount) : (isset($data->deposit) ? floatval($data->deposit) : ($total_price * 0.20));
                
                $comment = $data->comment ?? $data->guest_notes ?? '-';
                $guest_phone = $data->guest_phone ?? $data->phone ?? '-';
                $guest_count = $data->guest_count ?? 1;

                $sql = "INSERT INTO bookings (room_id, room_detail, guest_name, guest_phone, guest_province, guest_count, comment, check_in, check_out, total_price, deposit_amount, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                $stmt = $conn->prepare($sql);
                
                if($stmt->execute([$data->room_id, $room_label, $data->guest_name, $guest_phone, $guest_province, $guest_count, $comment, $data->check_in, $data->check_out, $total_price, $deposit])) {
                    $new_id = $conn->lastInsertId();
                    $sheetData = [
                        "type" => "booking", "timestamp" => date("Y-m-d H:i:s"), "id" => "B" . str_pad($new_id, 4, '0', STR_PAD_LEFT),
                        "guest_name" => $data->guest_name, "guest_phone" => $guest_phone, "guest_province" => $guest_province,
                        "guest_count" => $guest_count, "room_info" => $room_label, "check_in" => $data->check_in,
                        "check_out" => $data->check_out, "total_price" => $total_price, "deposit" => $deposit, "comment" => $comment
                    ];
                    syncToGoogleSheets($google_url, $sheetData);
                    echo json_encode(["status" => "success", "data" => ["id" => $new_id]], JSON_UNESCAPED_UNICODE);
                }
            } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        }
    }
}

// --- [PUT] อัปเดตสถานะการจอง ---
if ($method == 'PUT') {
    $data = json_decode(file_get_contents("php://input"));
    if(!empty($data->id) && !empty($data->status)) {
        $stmt = $conn->prepare("UPDATE bookings SET status=?, total_price=? WHERE id=?");
        if($stmt->execute([$data->status, $data->total_price, $data->id])) {
            echo json_encode(["status" => "success"]);
        }
    }
}

// --- [DELETE] ลบข้อมูล ---
if ($method == 'DELETE') {
    $id = $_GET['id'] ?? null;
    if($id) {
        if ($target == 'room') {
            // ก่อนลบห้องพัก ควรลบรูปภาพที่เกี่ยวข้องด้วย
            $stmt_img = $conn->prepare("SELECT image_url, gallery_images FROM rooms WHERE id = ?");
            $stmt_img->execute([$id]);
            $room_files = $stmt_img->fetch(PDO::FETCH_ASSOC);
            if ($room_files) {
                if (file_exists($room_files['image_url'])) unlink($room_files['image_url']);
                $gal = json_decode($room_files['gallery_images'], true);
                if (is_array($gal)) {
                    foreach ($gal as $f) if (file_exists($f)) unlink($f);
                }
            }
            $stmt = $conn->prepare("DELETE FROM rooms WHERE id=?");
            if($stmt->execute([$id])) echo json_encode(["status" => "success", "message" => "ลบที่พักและไฟล์ที่เกี่ยวข้องสำเร็จ"]);
        } else {
            $stmt = $conn->prepare("DELETE FROM bookings WHERE id=?");
            if($stmt->execute([$id])) echo json_encode(["status" => "success", "message" => "ลบการจองสำเร็จ"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "ID is missing"]);
    }
}
?>