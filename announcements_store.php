<?php
// announcements_store.php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ann_db() {
  static $conn;
  if (!$conn) {
    $conn = new mysqli('localhost','root','','maid_system');
    $conn->set_charset('utf8mb4');
  }
  return $conn;
}

function ann_list(int $limit = 20): array {
  $sql = "SELECT id,title,body,author,created_at
          FROM announcements
          ORDER BY created_at DESC
          LIMIT ?";
  $stmt = ann_db()->prepare($sql);
  $stmt->bind_param('i', $limit);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows;
}

function ann_create(string $title, string $body, string $author='Admin'): bool {
  $sql = "INSERT INTO announcements (title,body,author,created_at)
          VALUES (?,?,?,NOW())";
  $stmt = ann_db()->prepare($sql);
  $stmt->bind_param('sss', $title, $body, $author);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function ann_delete(int $id): bool {
  $sql = "DELETE FROM announcements WHERE id=?";
  $stmt = ann_db()->prepare($sql);
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $n = $stmt->affected_rows;
  $stmt->close();
  return $n > 0;
}


