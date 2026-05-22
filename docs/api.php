<?php
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
$password = "Ap2316087*"; // แก้ไขรหัสผ่านของคุณตรงนี้

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Connection Error: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
        $stmt = $conn->prepare("SELECT * FROM posts ORDER BY id DESC");
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // แปลง JSON string กลับเป็น array สำหรับฝั่งหน้าบ้าน
        foreach($posts as &$post) {
            $post['gallery'] = json_decode($post['gallery'] ?? '[]');
        }
        echo json_encode($posts, JSON_UNESCAPED_UNICODE);
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"));
        if(!empty($data->title)) {
            $sql = "INSERT INTO posts (title, category, image_url, gallery, excerpt) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            // บันทึก gallery เป็น JSON string
            $gallery_json = json_encode($data->gallery ?? []);
            if($stmt->execute([$data->title, $data->category, $data->image_url, $gallery_json, $data->excerpt])) {
                echo json_encode(["status" => "success", "message" => "บันทึกสำเร็จ"], JSON_UNESCAPED_UNICODE);
            }
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents("php://input"));
        if(!empty($data->id)) {
            $sql = "UPDATE posts SET title=?, category=?, image_url=?, gallery=?, excerpt=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $gallery_json = json_encode($data->gallery ?? []);
            if($stmt->execute([$data->title, $data->category, $data->image_url, $gallery_json, $data->excerpt, $data->id])) {
                echo json_encode(["status" => "success", "message" => "แก้ไขสำเร็จ"], JSON_UNESCAPED_UNICODE);
            }
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents("php://input"));
        $id = $_GET['id'] ?? $data->id ?? null;
        if($id) {
            $stmt = $conn->prepare("DELETE FROM posts WHERE id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["status" => "success", "message" => "ลบสำเร็จ"], JSON_UNESCAPED_UNICODE);
            }
        }
        break;
}
?>