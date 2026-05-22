<?php
/**
 * Ozone Phrae - Unified Menu & Order API v2.0 (Updated with Image Upload & CSV)
 * แก้ไข: ปรับปรุงระบบสถานะเป็น 4 ขั้นตอน, นำเข้าข้อมูลเมนูผ่าน CSV และระบบอัปโหลดรูปภาพ (Drop Zone)
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- การตั้งค่าฐานข้อมูล ---
$host = "localhost";
$db_name = "ozonephr_dashboard"; 
$username = "ozonephr_dashboard";     
$password = "Ap2316087*"; 

// URL ของ Google Apps Script สำหรับ Sync ข้อมูล
$GOOGLE_WEB_APP_URL = "https://script.google.com/macros/s/AKfycbyX_12QBnPKU6atdYtcHVBc1MApc9uFXBEGV-i6aheUJy7YOqAT60MhMxoLtYDZc5R-/exec"; 

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Connection Failed: " . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$target = $_GET['target'] ?? '';

// สำหรับรับข้อมูลแบบ JSON (ดั้งเดิม)
$input = json_decode(file_get_contents("php://input"), true);

if (empty($action) && isset($input['action'])) $action = $input['action'];

try {
    // ==============================================================
    // [GET] ดึงข้อมูล
    // ==============================================================
    if ($method === 'GET') {
        if ($action === 'list_categories') {
            $stmt = $conn->query("SELECT * FROM categories ORDER BY id ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
            
        } elseif ($action === 'all_items' || $action === 'list_menu') {
            $sql = "SELECT m.*, c.name as category_name 
                    FROM menu_items m 
                    LEFT JOIN categories c ON m.category_id = c.id 
                    ORDER BY c.id ASC, m.id ASC";
            $stmt = $conn->query($sql);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
            
        } elseif ($action === 'all_orders') {
            $stmt = $conn->query("SELECT * FROM orders ORDER BY created_at DESC");
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orders as &$order) {
                $itemStmt = $conn->prepare("
                    SELECT oi.*, m.name AS menu_name, m.image_url 
                    FROM order_items oi 
                    LEFT JOIN menu_items m ON oi.menu_item_id = m.id 
                    WHERE oi.order_id = ?
                ");
                $itemStmt->execute([$order['id']]);
                $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($orders, JSON_UNESCAPED_UNICODE);
        }

    // ==============================================================
    // [POST] สร้าง/อัปเดต/ลบ/นำเข้า CSV และ อัปโหลดรูปเมนูอาหาร
    // ==============================================================
    } elseif ($method === 'POST') {
        
        // 🌟 [NEW ACTION] อัปโหลดและจัดการข้อมูลเมนูอาหาร (Drop Zone) 🌟
        if ($target === 'food_upload') {
            $id = $_POST['id'] ?? null;
            $name = $_POST['name'] ?? '';
            $category_id = $_POST['category_id'] ?? null;
            $price = $_POST['price'] ?? 0;
            $description = $_POST['description'] ?? '';
            $is_available = $_POST['is_available'] ?? 1;
            
            // รับค่ารูปภาพเดิมกรณีไม่ได้เปลี่ยน
            $existing_image_url = $_POST['existing_image_url'] ?? '';
            $image_url = $existing_image_url;

            // 1. ตรวจสอบและสร้างโฟลเดอร์หากยังไม่มี
            $upload_dir = 'uploads/food/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // 2. จัดการไฟล์รูปภาพใหม่ (ถ้ามีการส่งมา)
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
                
                // สุ่มชื่อไฟล์ใหม่เพื่อป้องกันชื่อซ้ำ
                $new_name = 'food_' . time() . '_' . uniqid() . '.' . strtolower($ext);
                $target_file = $upload_dir . $new_name;

                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target_file)) {
                    $image_url = $target_file; // เปลี่ยนไปใช้ URL ของรูปภาพใหม่
                    
                    // (Optional) ลบไฟล์รูปเก่าทิ้งเพื่อประหยัดพื้นที่เซิร์ฟเวอร์
                    if (!empty($existing_image_url) && file_exists($existing_image_url)) {
                        unlink($existing_image_url);
                    }
                }
            }

            // 3. บันทึกหรืออัปเดตลงฐานข้อมูล
            if (!empty($id)) {
                // กรณีแก้ไข (Update)
                $stmt = $conn->prepare("UPDATE menu_items SET category_id=?, name=?, price=?, image_url=?, description=?, is_available=? WHERE id=?");
                $stmt->execute([$category_id, $name, $price, $image_url, $description, $is_available, $id]);
                echo json_encode(["status" => "success", "message" => "อัปเดตเมนูอาหารสำเร็จ"]);
            } else {
                // กรณีเพิ่มใหม่ (Insert)
                $stmt = $conn->prepare("INSERT INTO menu_items (category_id, name, price, image_url, description, is_available) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$category_id, $name, $price, $image_url, $description, $is_available]);
                echo json_encode(["status" => "success", "message" => "เพิ่มเมนูอาหารใหม่สำเร็จ"]);
            }
            exit; // จบการทำงานของ food_upload
        }

        // [ACTION] นำเข้าไฟล์ CSV
        elseif ($action === 'import_csv' && isset($_FILES['csv_file'])) {
            $file = $_FILES['csv_file']['tmp_name'];
            $count = 0;

            if (($handle = fopen($file, "r")) !== FALSE) {
                // ข้ามหัวตาราง (Row 1)
                fgetcsv($handle, 1000, ",");
                
                $sql = "INSERT INTO menu_items (category_id, name, description, price, image_url, is_available, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if(count($data) >= 6) {
                        // จัดการรูปแบบวันที่ (DD/MM/YYYY -> YYYY-MM-DD)
                        $raw_date = trim($data[6] ?? '');
                        $formatted_date = date('Y-m-d H:i:s'); // ค่าเริ่มต้น
                        
                        if (!empty($raw_date)) {
                            // พยายามแปลงจาก d/m/Y (เช่น 15/5/2029)
                            $date_parts = explode('/', $raw_date);
                            if(count($date_parts) == 3) {
                                // ตรวจสอบว่าเป็นปี พ.ศ. หรือ ค.ศ. (ถ้า > 2500 ให้ลบ 543)
                                $year = (int)$date_parts[2];
                                if ($year > 2500) $year -= 543;
                                $formatted_date = "$year-{$date_parts[1]}-{$date_parts[0]} " . date('H:i:s');
                            }
                        }

                        $stmt->execute([
                            $data[0], // category_id
                            $data[1], // name
                            $data[2], // description
                            $data[3], // price
                            $data[4], // image_url
                            $data[5], // is_available
                            $formatted_date // created_at
                        ]);
                        $count++;
                    }
                }
                fclose($handle);
                echo json_encode(["status" => "success", "message" => "Imported $count items", "count" => $count]);
                exit;
            } else {
                throw new Exception("ไม่สามารถอ่านไฟล์ได้");
            }
        }

        // ลบ Order (ลบผ่าน POST เผื่อฝั่ง client สะดวก)
        elseif ($action === 'delete') {
            $id = $_POST['id'] ?? $input['id'] ?? null;
            if ($id) {
                $conn->beginTransaction();
                $conn->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
                $conn->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
                $conn->commit();
                echo json_encode(["status" => "success", "message" => "Order deleted"]);
                exit;
            }

        // ล้างออเดอร์ที่เสิร์ฟแล้ว
        } elseif ($action === 'clear_served') {
            $conn->beginTransaction();
            $conn->query("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE status = 'served')");
            $conn->query("DELETE FROM orders WHERE status = 'served'");
            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Cleared served orders"]);
            exit;

        // อัปเดตสถานะ
        } elseif ($action === 'update_status') {
            $id = $_POST['order_id'] ?? $input['order_id'] ?? null;
            $new_status = $_POST['status'] ?? $input['status'] ?? null;
            if ($id && $new_status) {
                $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $id]);
                echo json_encode(["status" => "success", "new_status" => $new_status]);
                exit;
            }
        }

        // การจัดการหมวดหมู่และเมนู (กรณีไม่มีภาพแนบ / ใช้ JSON)
        if ($target === 'category') {
            $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->execute([$input['name']]);
            echo json_encode(["status" => "success", "message" => "Category added"]);
            
        } elseif ($target === 'item') {
            $stmt = $conn->prepare("INSERT INTO menu_items (category_id, name, price, image_url, description, is_available) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['category_id'], $input['name'], $input['price'], 
                $input['image_url'], $input['description'], $input['is_available']
            ]);
            echo json_encode(["status" => "success", "message" => "Menu item added"]);
            
        } elseif ($action === 'checkout' || empty($target)) {
            // ระบบสั่งอาหารจากฝั่งลูกค้า
            $customer_name = $input['customer_name'] ?? 'ไม่ระบุ';
            $table_number = $input['table_number'] ?? 'ไม่ระบุโต๊ะ';
            $total_amount = $input['total_amount'] ?? 0;
            $cart = $input['cart'] ?? [];

            if (empty($cart)) throw new Exception("ตะกร้าสินค้าว่างเปล่า");

            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO orders (customer_name, table_number, total_amount, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$customer_name, $table_number, $total_amount]);
            $order_id = $conn->lastInsertId();

            $items_detail_array = []; 
            $itemStmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price_at_time, note, item_status) VALUES (?, ?, ?, ?, ?, 'pending')");
            
            foreach ($cart as $item) {
                $menu_id = $item['menu_item_id'] ?? $item['id'] ?? null;
                if (!$menu_id) continue;
                $qty = $item['quantity'] ?? $item['qty'] ?? 1;
                $price = $item['price_at_time'] ?? $item['price'] ?? 0;
                $note = $item['note'] ?? '';
                $itemStmt->execute([$order_id, $menu_id, $qty, $price, $note]);

                $nameStmt = $conn->prepare("SELECT name FROM menu_items WHERE id = ?");
                $nameStmt->execute([$menu_id]);
                $db_item = $nameStmt->fetch(PDO::FETCH_ASSOC);
                $name = $db_item['name'] ?? $item['name'] ?? 'เมนู';
                $subtotal = $qty * $price;
                $items_detail_array[] = "- {$qty}x {$name} " . (!empty($note) ? "[$note]" : "") . " = {$subtotal} ฿";
            }
            $conn->commit();

            // Sync ข้อมูลไป Google Sheets
            if (!empty($GOOGLE_WEB_APP_URL)) {
                date_default_timezone_set('Asia/Bangkok');
                $sheet_data = [
                    "type" => "order", 
                    "order_id" => "ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT),
                    "order_time" => date('Y-m-d H:i:s'),
                    "customer_name" => $customer_name,
                    "table_info" => $table_number,
                    "items_detail" => implode("\n", $items_detail_array),
                    "total_price" => $total_amount,
                    "status" => "รอดำเนินการ"
                ];
                $ch = curl_init($GOOGLE_WEB_APP_URL);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sheet_data));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
                curl_exec($ch);
                curl_close($ch);
            }
            echo json_encode(["status" => "success", "message" => "สั่งอาหารสำเร็จ", "order_id" => $order_id]);
        }

    // ==============================================================
    // [PUT] อัปเดตข้อมูล (ยังคงไว้สำหรับรองรับ API JSON เดิม)
    // ==============================================================
    } elseif ($method === 'PUT') {
        if ($target === 'category') {
            $stmt = $conn->prepare("UPDATE categories SET name=? WHERE id=?");
            $stmt->execute([$input['name'], $input['id']]);
            echo json_encode(["status" => "success"]);
            
        } elseif ($target === 'item') {
            $stmt = $conn->prepare("UPDATE menu_items SET category_id=?, name=?, price=?, image_url=?, description=?, is_available=? WHERE id=?");
            $stmt->execute([
                $input['category_id'], $input['name'], $input['price'], 
                $input['image_url'], $input['description'], $input['is_available'], $input['id']
            ]);
            echo json_encode(["status" => "success"]);
            
        } elseif (isset($input['order_id']) && isset($input['status'])) {
            $stmt = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
            $stmt->execute([$input['status'], $input['order_id']]);
            echo json_encode(["status" => "success"]);
        }

    // ==============================================================
    // [DELETE] ลบข้อมูล
    // ==============================================================
    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? $input['id'] ?? null;
        
        if ($target === 'category' && $id) {
            $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(["status" => "success"]);
            
        } elseif ($target === 'item' && $id) {
            // เมื่อลบเมนู แนะนำให้ลบรูปภาพเมนูนั้นทิ้งด้วย
            $stmt_img = $conn->prepare("SELECT image_url FROM menu_items WHERE id = ?");
            $stmt_img->execute([$id]);
            $item_img = $stmt_img->fetch(PDO::FETCH_ASSOC);
            
            if ($item_img && !empty($item_img['image_url']) && file_exists($item_img['image_url'])) {
                unlink($item_img['image_url']);
            }

            $stmt = $conn->prepare("DELETE FROM menu_items WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(["status" => "success"]);
            
        } elseif ($action === 'delete' && $id) {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
            $conn->commit();
            echo json_encode(["status" => "success"]);
        }
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>