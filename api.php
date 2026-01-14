<?php
/**
 * API Backend untuk Sistem Blog (Support Upload Gambar)
 * Menangani request dari Frontend ke Database MySQL
 */

// 1. Konfigurasi Header (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
// Catatan: Jangan set Content-Type JSON secara global jika return bisa text/html error, 
// tapi untuk API ini kita usahakan selalu JSON.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. Koneksi Database
$host = "localhost";
$port = 3306;
$user = "febri196_aku";      
$pass = "anakbajenk";          
$db   = "febri196_blog"; 

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database Connection Failed: " . $conn->connect_error]);
    exit();
}

// 3. Helpers
$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

// Helper: Ambil Input (Bisa JSON atau Form Data)
function getInput() {
    $json = json_decode(file_get_contents("php://input"), true);
    if ($json) return $json;
    return $_POST; // Fallback jika menggunakan FormData/Multipart
}

// Helper: Format Tanggal ke Indonesia
function formatTanggalIndonesia($dateString) {
    if (empty($dateString)) return '';
    $bulanIndo = [
        'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
        'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
        'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September',
        'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
    ];
    $timestamp = strtotime($dateString);
    $dateEnglish = date('d F Y', $timestamp); 
    return strtr($dateEnglish, $bulanIndo);
}

// Helper: Sinkronisasi Label
function syncLabels($conn, $postId, $labelsString) {
    // Bersihkan relasi lama
    $conn->query("DELETE FROM post_labels WHERE post_id = $postId");

    // Jika labels dikirim sebagai array (dari JSON) atau string (dari FormData)
    if (is_string($labelsString)) {
        $labelsArray = explode(',', $labelsString);
    } else if (is_array($labelsString)) {
        $labelsArray = $labelsString;
    } else {
        return;
    }

    foreach ($labelsArray as $labelName) {
        $labelName = trim($labelName);
        if (empty($labelName)) continue;

        // Cek/Buat Label Master
        $stmt = $conn->prepare("SELECT id FROM labels WHERE name = ?");
        $stmt->bind_param("s", $labelName);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $labelId = 0;
        if ($row = $res->fetch_assoc()) {
            $labelId = $row['id'];
        } else {
            $stmtIns = $conn->prepare("INSERT INTO labels (name) VALUES (?)");
            $stmtIns->bind_param("s", $labelName);
            $stmtIns->execute();
            $labelId = $conn->insert_id;
            $stmtIns->close();
        }
        $stmt->close();

        // Hubungkan ke Post
        $stmtLink = $conn->prepare("INSERT IGNORE INTO post_labels (post_id, label_id) VALUES (?, ?)");
        $stmtLink->bind_param("ii", $postId, $labelId);
        $stmtLink->execute();
        $stmtLink->close();
    }
}

// Helper: Handle Upload File
function handleUpload() {
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        // Buat folder jika belum ada
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate nama file unik
        $fileExtension = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
        $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
        $targetFile = $uploadDir . $fileName;
        
        // Validasi tipe file (hanya gambar)
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array(strtolower($fileExtension), $allowedTypes)) {
            return false;
        }

        if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetFile)) {
            return $targetFile; // Kembalikan path file
        }
    }
    return false;
}

// 4. Routing Logic
switch ($action) {
    
    // --- LOGIN ---
    case 'login':
        if ($method === 'POST') {
            $input = getInput();
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';

            $stmt = $conn->prepare("SELECT id, name, role, password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if ($password === $row['password']) {
                    echo json_encode([
                        "status" => "success",
                        "user" => ["id" => $row['id'], "name" => $row['name'], "role" => $row['role']]
                    ]);
                } else {
                    echo json_encode(["status" => "error", "message" => "Password salah"]);
                }
            } else {
                echo json_encode(["status" => "error", "message" => "Username tidak ditemukan"]);
            }
            $stmt->close();
        }
        break;

    // --- GET POSTS ---
    case 'get_posts':
        if ($method === 'GET') {
            $sql = "SELECT * FROM posts ORDER BY created_at DESC";
            $result = $conn->query($sql);
            
            $posts = [];
            while ($row = $result->fetch_assoc()) {
                $postId = $row['id'];
                $row['date'] = formatTanggalIndonesia($row['created_at']);

                // Labels
                $sqlLabels = "SELECT l.name FROM labels l JOIN post_labels pl ON l.id = pl.label_id WHERE pl.post_id = $postId";
                $resLabels = $conn->query($sqlLabels);
                $labels = [];
                while($l = $resLabels->fetch_assoc()) $labels[] = $l['name'];
                $row['labels'] = $labels;

                // Comments
                $sqlComments = "SELECT id, user_name, content, created_at FROM comments WHERE post_id = $postId ORDER BY created_at DESC";
                $resComments = $conn->query($sqlComments);
                $comments = [];
                while($c = $resComments->fetch_assoc()) {
                    $comments[] = [
                        'id' => $c['id'],
                        'user' => $c['user_name'],
                        'text' => $c['content'],
                        'date' => formatTanggalIndonesia($c['created_at'])
                    ];
                }
                $row['comments'] = $comments;

                $posts[] = $row;
            }
            echo json_encode($posts);
        }
        break;

    // --- CREATE POST (Support Upload) ---
    case 'create_post':
        if ($method === 'POST') {
            $input = getInput();
            
            $title   = $input['title'];
            $content = $input['content'];
            $plainText = strip_tags($content);
            $excerpt = substr($plainText, 0, 150) . (strlen($plainText) > 150 ? '...' : '');
            $author  = $input['author'];
            $created_at = date('Y-m-d');
            
            // Handle Image: Prioritas Upload > URL Manual
            $image = $input['image'] ?? '';
            $uploadedPath = handleUpload();
            if ($uploadedPath) {
                $image = $uploadedPath;
            }

            $stmt = $conn->prepare("INSERT INTO posts (title, excerpt, content, image, author, created_at, views, likes) VALUES (?, ?, ?, ?, ?, ?, 0, 0)");
            $stmt->bind_param("ssssss", $title, $excerpt, $content, $image, $author, $created_at);

            if ($stmt->execute()) {
                $newPostId = $conn->insert_id;
                if (isset($input['labels'])) {
                    syncLabels($conn, $newPostId, $input['labels']);
                }
                echo json_encode(["status" => "success", "message" => "Artikel berhasil dibuat", "id" => $newPostId]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal: " . $stmt->error]);
            }
            $stmt->close();
        }
        break;

    // --- UPDATE POST (Support Upload) ---
    case 'update_post':
        if ($method === 'POST') {
            $input = getInput();
            
            $id      = $input['id'];
            $title   = $input['title'];
            $content = $input['content'];
            $plainText = strip_tags($content);
            $excerpt = substr($plainText, 0, 150) . (strlen($plainText) > 150 ? '...' : '');
            $author  = $input['author'];
            
            // Handle Image
            $image = $input['image'] ?? '';
            $uploadedPath = handleUpload();
            if ($uploadedPath) {
                $image = $uploadedPath; // Ganti gambar lama dengan yang baru diupload
            }

            $stmt = $conn->prepare("UPDATE posts SET title=?, excerpt=?, content=?, image=?, author=? WHERE id=?");
            $stmt->bind_param("sssssi", $title, $excerpt, $content, $image, $author, $id);

            if ($stmt->execute()) {
                if (isset($input['labels'])) {
                    syncLabels($conn, $id, $input['labels']);
                }
                echo json_encode(["status" => "success", "message" => "Artikel diperbarui"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Gagal: " . $stmt->error]);
            }
            $stmt->close();
        }
        break;

    // --- DELETE, LIKE, VIEW, COMMENT (Sama seperti sebelumnya) ---
    case 'delete_post':
        if ($method === 'POST') { 
            $input = getInput();
            $id = $input['id'];
            $stmt = $conn->prepare("DELETE FROM posts WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) echo json_encode(["status" => "success"]);
            else echo json_encode(["status" => "error", "message" => $stmt->error]);
            $stmt->close();
        }
        break;

    case 'like_post':
        if ($method === 'POST') {
            $input = getInput();
            $postId = $input['id'];
            $ip = $_SERVER['REMOTE_ADDR'];
            $stmt = $conn->prepare("INSERT IGNORE INTO post_likes (post_id, ip_address) VALUES (?, ?)");
            $stmt->bind_param("is", $postId, $ip);
            if ($stmt->execute()) echo json_encode(["status" => "success"]);
            else echo json_encode(["status" => "error"]);
            $stmt->close();
        }
        break;

    case 'add_comment':
        if ($method === 'POST') {
            $input = getInput();
            $stmt = $conn->prepare("INSERT INTO comments (post_id, user_name, content) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $input['post_id'], $input['user'], $input['text']);
            if ($stmt->execute()) echo json_encode(["status" => "success", "id" => $conn->insert_id]);
            else echo json_encode(["status" => "error", "message" => $stmt->error]);
            $stmt->close();
        }
        break;

    case 'view_post':
        if ($method === 'POST') {
            $input = getInput();
            $conn->query("UPDATE posts SET views = views + 1 WHERE id = " . intval($input['id']));
            echo json_encode(["status" => "success"]);
        }
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Invalid Action"]);
        break;
}

$conn->close();
?>