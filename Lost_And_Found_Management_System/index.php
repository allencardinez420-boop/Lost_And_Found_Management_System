<?php
session_start();

const DB_HOST = '127.0.0.1';
const DB_PORT = '3307';
const DB_NAME = 'lost_found_db';
const DB_USER = 'root';
const DB_PASS = '';

function db_config(string $name, string $default): string {
  $value = getenv($name);
  return $value === false ? $default : $value;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $candidates = [
        ['host' => db_config('DB_HOST', DB_HOST), 'port' => db_config('DB_PORT', DB_PORT), 'user' => db_config('DB_USER', DB_USER), 'pass' => db_config('DB_PASS', DB_PASS)],
        ['host' => '127.0.0.1', 'port' => '3307', 'user' => 'root', 'pass' => ''],
        ['host' => 'localhost', 'port' => '3307', 'user' => 'root', 'pass' => ''],
        ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => ''],
        ['host' => 'localhost', 'port' => '3306', 'user' => 'root', 'pass' => ''],
        ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => 'root'],
        ['host' => 'localhost', 'port' => '3306', 'user' => 'root', 'pass' => 'root'],
        ['host' => '127.0.0.1', 'port' => '3307', 'user' => 'root', 'pass' => 'root'],
        ['host' => '127.0.0.1', 'port' => '3308', 'user' => 'root', 'pass' => ''],
    ];

    $dbName = db_config('DB_NAME', DB_NAME);
    $lastException = null;

    $seen = [];
    $uniqueCandidates = [];
    foreach ($candidates as $c) {
        $key = "{$c['host']}:{$c['port']}:{$c['user']}:{$c['pass']}";
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueCandidates[] = $c;
        }
    }

    foreach ($uniqueCandidates as $cand) {
        try {
            $dsn = "mysql:host={$cand['host']};port={$cand['port']};dbname={$dbName};charset=utf8mb4";
            $conn = new PDO($dsn, $cand['user'], $cand['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $conn->query('SELECT 1');
            $pdo = $conn;
            return $pdo;
        } catch (Throwable $e) {
            $lastException = $e;
        }
    }

    // Try without dbname to auto-create lost_found_db if missing
    foreach ($uniqueCandidates as $cand) {
        try {
            $dsn = "mysql:host={$cand['host']};port={$cand['port']};charset=utf8mb4";
            $conn = new PDO($dsn, $cand['user'], $cand['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $conn->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn->exec("USE `{$dbName}`");
            $tableCount = (int)$conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$dbName}'")->fetchColumn();
            if ($tableCount === 0 && file_exists(__DIR__ . '/lost&found_db.sql')) {
                $sql = file_get_contents(__DIR__ . '/lost&found_db.sql');
                $conn->exec($sql);
            }
            $pdo = $conn;
            return $pdo;
        } catch (Throwable $e) {
            $lastException = $e;
        }
    }

    throw $lastException ?? new RuntimeException('Unable to connect to database on any detected port.');
}
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $page = 'home', array $query = []): never {
    $url = 'index.php?page=' . urlencode($page);
    if ($query) $url .= '&' . http_build_query($query);
    header('Location: ' . $url); exit;
}
function flash(string $type, string $message): void { $_SESSION['flash'] = ['type' => $type, 'message' => $message]; }
function get_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid security token.'); } }
function user(): ?array { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool { return user() !== null; }
function is_admin(): bool { return in_array(user()['role'] ?? '', ['Admin'], true); }
function require_login(): void { if (!is_logged_in()) { flash('error', 'Please sign in first.'); redirect('login'); } }
function require_admin(): void { if (!is_admin()) { flash('error', 'Admin access required.'); redirect('dashboard'); } }
function migrate_legacy_user_id(): void {
  $pdo = db();
  $legacyId = '241-0200-1';
  $newId = 'U001';
  $legacy = $pdo->prepare('SELECT user_id FROM users WHERE user_id=?');
  $legacy->execute([$legacyId]);
  if (!$legacy->fetchColumn()) return;

  $current = $pdo->prepare('SELECT user_id FROM users WHERE user_id=?');
  $current->execute([$newId]);
  if ($current->fetchColumn()) return;

  $pdo->prepare('UPDATE users SET user_id=? WHERE user_id=?')->execute([$newId, $legacyId]);
  if (($_SESSION['user']['user_id'] ?? '') === $legacyId) $_SESSION['user']['user_id'] = $newId;
}
function next_id(string $prefix, string $column, string $table): string {
    $stmt = db()->query("SELECT MAX(CAST(SUBSTRING($column, 2) AS UNSIGNED)) AS m FROM $table");
    $max = (int)($stmt->fetch()['m'] ?? 0);
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function safe_date(string $value): string { $d = DateTime::createFromFormat('Y-m-d', $value); return ($d && $d->format('Y-m-d') === $value) ? $value : date('Y-m-d'); }
function categories(): array { return db()->query('SELECT * FROM categories ORDER BY category_name')->fetchAll(); }
function stats(): array {
    $q = db()->query("SELECT
        (SELECT COUNT(*) FROM lost_records) AS lost_total,
        (SELECT COUNT(*) FROM found_item_records) AS found_total,
        (SELECT COUNT(*) FROM found_item_records WHERE status='Unclaimed') AS unclaimed_total,
        (SELECT COUNT(*) FROM claim_records WHERE status='Approved') AS approved_total,
        (SELECT COUNT(*) FROM claim_records WHERE status='Pending') AS pending_total,
        (SELECT COUNT(*) FROM users) AS users_total");
    return $q->fetch();
}
function match_score(array $lost, array $found): int {
    $score = 0;
    if (($lost['category_id'] ?? '') === ($found['category_id'] ?? '')) $score += 50;
    if (strcasecmp(trim($lost['item_name']), trim($found['item_name'])) === 0) $score += 30;
    if (strcasecmp(trim($lost['color'] ?? ''), trim($found['color'] ?? '')) === 0) $score += 10;
    if (strcasecmp(trim($lost['location_lost'] ?? ''), trim($found['location_found'] ?? '')) === 0) $score += 10;
    return $score;
}
function badge_class(string $type): string {
    return [
        'lost' => 'bg-rose-50 text-rose-700', 'found' => 'bg-emerald-50 text-emerald-700',
        'claim' => 'bg-orange-50 text-orange-700', 'user' => 'bg-violet-50 text-violet-700',
        'pending' => 'bg-amber-50 text-amber-700', 'approved' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-rose-50 text-rose-700', 'returned' => 'bg-blue-50 text-blue-700',
        'matched' => 'bg-blue-50 text-blue-700', 'unclaimed' => 'bg-amber-50 text-amber-700',
        'claimed' => 'bg-emerald-50 text-emerald-700',
    ][strtolower($type)] ?? 'bg-slate-100 text-slate-700';
}
function status_badge(string $s): string { return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold '.badge_class($s).'">'.e($s).'</span>'; }
function initials(?string $name): string { $name = trim((string)$name) ?: 'U'; $p = preg_split('/\s+/', $name); $ini = ''; foreach (array_slice($p, 0, 2) as $x) $ini .= strtoupper(mb_substr($x, 0, 1)); return $ini ?: 'U'; }
function chart_series(): array {
    $rows = db()->query("SELECT DATE_FORMAT(d,'%Y-%m') AS m,
        (SELECT COUNT(*) FROM lost_records WHERE DATE_FORMAT(date_lost,'%Y-%m')=m) AS lost,
        (SELECT COUNT(*) FROM found_item_records WHERE DATE_FORMAT(date_found,'%Y-%m')=m) AS found,
        (SELECT COUNT(*) FROM claim_records WHERE DATE_FORMAT(date_claimed,'%Y-%m')=m) AS claims
        FROM (SELECT date_lost d FROM lost_records UNION SELECT date_found FROM found_item_records UNION SELECT date_claimed FROM claim_records) t GROUP BY m ORDER BY m ASC")->fetchAll();
    if (!$rows) return ['labels'=>['No data'],'lost'=>[0],'found'=>[0],'claims'=>[0]];
    $l=[];$lo=[];$fo=[];$cl=[];
    foreach ($rows as $r) { $l[]=$r['m']; $lo[]=(int)$r['lost']; $fo[]=(int)$r['found']; $cl[]=(int)$r['claims']; }
    return ['labels'=>$l,'lost'=>$lo,'found'=>$fo,'claims'=>$cl];
}
function app_pages(): array { return ['dashboard','lost','found','claims','users','departments','reports','activity','profile','settings','matching','admin','my-reports','browse','report-lost','report-found']; }
function flash_toast(?array $flash = null): void {
    $f = $flash ?? get_flash(); if (!$f) return;
    $ok = $f['type']==='success';
    echo '<div id="toast" role="status" aria-live="polite" class="fixed right-4 top-4 z-[100] flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-semibold shadow-lg '.($ok?'border-emerald-200 bg-emerald-50 text-emerald-800':'border-rose-200 bg-rose-50 text-rose-800').'">'
        .'<span aria-hidden="true">'.($ok?'✓':'!').'</span><span>'.e($f['message']).'</span></div>';
    echo '<script>setTimeout(()=>{const t=document.getElementById("toast");if(t){t.style.transition="opacity .4s";t.style.opacity="0";setTimeout(()=>t.remove(),400);}},3500);</script>';
}


// ---------- actions ----------
try {
  migrate_legacy_user_id();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([trim($_POST['user_id'] ?? '')]);
            $f = $stmt->fetch();
            if ($f && password_verify($_POST['password'] ?? '', $f['password_hash'])) {
                unset($f['password_hash']);
                $_SESSION['user'] = $f;
                flash('success', 'Welcome back, ' . $f['name'] . '.');
                redirect('dashboard');
            }
            flash('error', 'Invalid User ID or password.'); redirect('login');
        }
        if ($action === 'register') {
            $name = trim($_POST['name'] ?? '');
            $role = $_POST['role'] ?? 'Outsider';
            $dept = trim($_POST['department'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $pw = $_POST['password'] ?? '';
            if ($name === '' || strlen($pw) < 6) { flash('error', 'Provide name and a 6+ char password.'); redirect('login'); }
            $allowed = ['Student','Instructor','Employee','Outsider'];
            if (!in_array($role, $allowed, true)) $role = 'Outsider';
            $uid = next_id('U', 'user_id', 'users');
            db()->prepare('INSERT INTO users (user_id,name,role,department,contact,password_hash) VALUES (?,?,?,?,?,?)')
                ->execute([$uid,$name,$role,$dept?:null,$contact?:null,password_hash($pw, PASSWORD_DEFAULT)]);
            $s = db()->prepare('SELECT * FROM users WHERE user_id=?'); $s->execute([$uid]);
            $_SESSION['user'] = $s->fetch(); unset($_SESSION['user']['password_hash']);
            flash('success', "Account created. Your User ID is $uid."); redirect('dashboard');
        }
        if ($action === 'logout') { unset($_SESSION['user']); flash('success', 'You have been signed out.'); redirect('home'); }

        if ($action === 'report_lost') {
            require_login();
            $id = next_id('L','lost_id','lost_records');
            $cat = trim($_POST['category_id'] ?? '');
            if ($cat === '') $cat = db()->query("SELECT category_id FROM categories ORDER BY category_id LIMIT 1")->fetchColumn() ?: 'CAT001';
            db()->prepare('INSERT INTO lost_records (lost_id,user_id,category_id,item_name,color,date_lost,location_lost,status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id, user()['user_id'], $cat, trim($_POST['item_name']), trim($_POST['color'] ?? ''), safe_date($_POST['date_lost']), trim($_POST['location_lost']), 'Pending']);
            flash('success', "Lost item $id was reported successfully.");
            redirect('lost');
        }
        if ($action === 'report_found') {
            require_login();
            $id = next_id('F','found_id','found_item_records');
            $cat = trim($_POST['category_id'] ?? '');
            if ($cat === '') $cat = db()->query("SELECT category_id FROM categories ORDER BY category_id LIMIT 1")->fetchColumn() ?: 'CAT001';
            db()->prepare('INSERT INTO found_item_records (found_id,user_id,category_id,item_name,color,date_found,location_found,status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id, user()['user_id'], $cat, trim($_POST['item_name']), trim($_POST['color'] ?? ''), safe_date($_POST['date_found']), trim($_POST['location_found']), 'Unclaimed']);
            flash('success', "Found item $id was added to the registry.");
            redirect('found');
        }
        if ($action === 'claim') {
            require_login();
            $fid = trim($_POST['found_id'] ?? '');
            $it = db()->prepare("SELECT * FROM found_item_records WHERE found_id=? AND status='Unclaimed'");
            $it->execute([$fid]); $row = $it->fetch();
            if (!$row) { flash('error', 'That item is no longer available.'); redirect('found'); }
            $c = db()->prepare('SELECT claim_id,status FROM claim_records WHERE found_id=? AND user_id=? LIMIT 1');
            $c->execute([$fid, user()['user_id']]);
            $existingClaim = $c->fetch();
            if ($existingClaim && $existingClaim['status'] !== 'Rejected') { flash('error', 'You already have a claim for this item.'); redirect('claims'); }
            if ($existingClaim) {
              $cid = $existingClaim['claim_id'];
              db()->prepare("UPDATE claim_records SET date_claimed=?, verified_by=NULL, status='Pending' WHERE claim_id=?")
                ->execute([date('Y-m-d'), $cid]);
            } else {
              $cid = next_id('C','claim_id','claim_records');
              db()->prepare('INSERT INTO claim_records (claim_id,found_id,user_id,date_claimed,verified_by,status) VALUES (?,?,?,?,NULL,?)')
                ->execute([$cid, $fid, user()['user_id'], date('Y-m-d'), 'Pending']);
            }
            flash('success', 'Claim submitted for verification.'); redirect('claims');
        }
        if ($action === 'claim_review') {
            require_admin();
            $cid = trim($_POST['claim_id'] ?? '');
            $decision = $_POST['decision'] ?? 'Pending';
            if (!in_array($decision, ['Approved','Rejected','Returned'], true)) $decision = 'Rejected';
            $pdo = db(); $pdo->beginTransaction();
            try {
                $s = $pdo->prepare('SELECT * FROM claim_records WHERE claim_id=? FOR UPDATE'); $s->execute([$cid]); $cl = $s->fetch();
                if (!$cl) throw new RuntimeException('Claim not found.');
                if ($decision === 'Approved' || $decision === 'Returned') {
                    $pdo->prepare("UPDATE found_item_records SET status='Claimed' WHERE found_id=?")->execute([$cl['found_id']]);
                    $pdo->prepare("UPDATE lost_records l JOIN claim_records c ON c.user_id=l.user_id JOIN found_item_records f ON f.found_id=c.found_id SET l.status='Returned' WHERE c.claim_id=? AND l.category_id=f.category_id AND LOWER(l.item_name)=LOWER(f.item_name) AND LOWER(COALESCE(l.color,''))=LOWER(COALESCE(f.color,'')) AND l.status IN ('Pending','Matched')")->execute([$cid]);
                } elseif ($decision === 'Rejected') {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM claim_records WHERE found_id=? AND claim_id!=? AND status='Approved'");
                    $chk->execute([$cl['found_id'], $cid]);
                    if ((int)$chk->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE found_item_records SET status='Unclaimed' WHERE found_id=?")->execute([$cl['found_id']]);
                    }
                }
                $sv = $decision === 'Returned' ? 'Approved' : $decision;
                $pdo->prepare('UPDATE claim_records SET status=?, verified_by=? WHERE claim_id=?')->execute([$sv, user()['user_id'], $cid]);
                $pdo->commit(); flash('success', "Claim $cid updated to $sv.");
            } catch (Throwable $e) { $pdo->rollBack(); flash('error', $e->getMessage()); }
            $back = $_POST['redirect'] ?? 'claims'; redirect($back);
        }
        if ($action === 'delete_lost') { require_admin(); db()->prepare('DELETE FROM lost_records WHERE lost_id=?')->execute([trim($_POST['lost_id'] ?? '')]); flash('success', 'Lost record deleted.'); redirect('lost'); }
        if ($action === 'delete_found') { require_admin(); db()->prepare('DELETE FROM found_item_records WHERE found_id=?')->execute([trim($_POST['found_id'] ?? '')]); flash('success', 'Found item deleted.'); redirect('found'); }
        if ($action === 'delete_user') { require_admin(); $u = trim($_POST['user_id'] ?? ''); if ($u === user()['user_id']) { flash('error','Cannot delete your own account.'); redirect('users'); } db()->prepare('DELETE FROM users WHERE user_id=?')->execute([$u]); flash('success', "User $u deleted."); redirect('users'); }
        if ($action === 'verify_claim') { require_admin(); $cid = trim($_POST['claim_id'] ?? ''); db()->prepare('UPDATE claim_records SET verified_by=? WHERE claim_id=?')->execute([user()['user_id'], $cid]); flash('success', "Claim $cid verified."); redirect('claims'); }
    }

    $flash = get_flash();
    $page = $_GET['page'] ?? 'home';
    if ($page === 'admin' || $page === 'browse' || $page === 'my-reports') $page = is_admin() ? 'claims' : 'lost';
    if (is_logged_in() && in_array($page, ['home'], true)) $page = 'dashboard';
    if (!is_logged_in() && in_array($page, app_pages(), true) && !in_array($page, ['login','home'], true)) { flash('error', 'Please sign in first.'); redirect('login'); }
    $st = stats();
} catch (Throwable $ex) {
  $flash = ['type'=>'error','message'=>'Database connection failed: ' . $ex->getMessage() . ' Import lost&found_db.sql and set DB_PASS if your MySQL root account has a password.'];
    $page = 'setup-help';
    $st = ['lost_total'=>0,'found_total'=>0,'unclaimed_total'=>0,'approved_total'=>0,'pending_total'=>0,'users_total'=>0];
}


function layout_header(string $page, ?array $flash): void { global $st;
$isApp = in_array($page, app_pages(), true) && is_logged_in();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lost & Found Management System</title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
tailwind.config = { theme: { extend: { colors: { navy:'#173B68', blue:'#1D63B8', sky:'#EAF4FF', ink:'#10233E', sidebar:'#0F2A4D' }, boxShadow:{soft:'0 12px 40px rgba(16,35,62,.08)'} } } }
</script>
<style>
  html{scroll-behavior:smooth}
  body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f7fb;color:#10233E}
  .card{border:1px solid #e6edf7;border-radius:16px;background:#fff;box-shadow:0 6px 24px rgba(16,35,62,.04)}
  .focus-ring:focus{outline:none;box-shadow:0 0 0 3px rgba(29,99,184,.18)}
  .sidebar-link{display:flex;align-items:center;gap:.75rem;padding:.55rem .85rem;border-radius:10px;color:#cfe0f7;font-weight:600;font-size:.9rem;transition:background .15s,color .15s}
  .sidebar-link:hover{background:rgba(255,255,255,.08);color:#fff}
  .sidebar-link.active{background:#1D63B8;color:#fff;box-shadow:0 6px 18px rgba(29,99,184,.45)}
  .sidebar-section{font-size:.66rem;font-weight:800;letter-spacing:.16em;color:#7a92b6;text-transform:uppercase;padding:.85rem .85rem .35rem}
  .stat-card{position:relative;overflow:hidden}
  .stat-card::after{content:"";position:absolute;right:-30px;top:-30px;width:120px;height:120px;border-radius:9999px;opacity:.08}
  .stat-blue::after{background:#1D63B8}.stat-green::after{background:#10b981}.stat-orange::after{background:#f59e0b}.stat-violet::after{background:#8b5cf6}
  .table th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700}
  .table td{font-size:.9rem;color:#1e293b}
  .btn{display:inline-flex;align-items:center;gap:.45rem;border-radius:10px;padding:.5rem .9rem;font-weight:600;font-size:.85rem;border:1px solid transparent;transition:all .15s;cursor:pointer}
  .btn-primary{background:#1D63B8;color:#fff}.btn-primary:hover{background:#174f96}
  .btn-soft{background:#EAF4FF;color:#1D63B8}.btn-soft:hover{background:#d8e8fb}
  .btn-danger{background:#fee2e2;color:#b91c1c}.btn-danger:hover{background:#fecaca}
  .btn-ghost{background:#fff;color:#1D63B8;border-color:#cfe0f7}.btn-ghost:hover{background:#EAF4FF}
  .input{width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:.6rem .8rem;font-size:.9rem;background:#fff}
  .input:focus{outline:none;border-color:#1D63B8;box-shadow:0 0 0 3px rgba(29,99,184,.15)}
  .scrollbar-thin::-webkit-scrollbar{height:8px;width:8px}
  .scrollbar-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:9999px}
  @media (max-width: 1023px){ #sidebar{transform:translateX(-100%);transition:transform .25s} #sidebar.open{transform:translateX(0)} }
</style>
</head>
<body>
<?php if ($isApp):
  $u = user();
  $role = strtolower($u['role'] ?? 'outsider');
  $isAdmin = $role === 'admin';
  $notifPendingClaims = [];
  $notifUnclaimedFound = [];
  $unreadClaimsCount = 0;
  $unclaimedFoundCount = 0;
  try {
    if ($isAdmin) {
      $notifPendingClaims = db()->query("
        SELECT c.claim_id, c.date_claimed, c.created_at, c.status,
               f.found_id, f.item_name, f.color, f.location_found,
               u.name AS claimant
        FROM claim_records c
        JOIN found_item_records f ON f.found_id=c.found_id
        JOIN users u ON u.user_id=c.user_id
        WHERE c.status='Pending'
        ORDER BY c.created_at DESC
        LIMIT 6
      ")->fetchAll();
      $unreadClaimsCount = (int)db()->query("SELECT COUNT(*) FROM claim_records WHERE status='Pending'")->fetchColumn();
    } else {
      $notifPendingClaims = db()->query("
        SELECT c.claim_id, c.date_claimed, c.created_at, c.status,
               f.found_id, f.item_name, f.color, f.location_found,
               u.name AS claimant
        FROM claim_records c
        JOIN found_item_records f ON f.found_id=c.found_id
        JOIN users u ON u.user_id=c.user_id
        WHERE c.user_id=" . db()->quote($u['user_id']) . "
        ORDER BY c.created_at DESC
        LIMIT 6
      ")->fetchAll();
      $unreadClaimsCount = (int)db()->query("SELECT COUNT(*) FROM claim_records WHERE user_id=" . db()->quote($u['user_id']) . " AND status='Pending'")->fetchColumn();
    }

    $notifUnclaimedFound = db()->query("
      SELECT found_id, item_name, color, location_found, date_found
      FROM found_item_records
      WHERE status='Unclaimed'
      ORDER BY created_at DESC
      LIMIT 4
    ")->fetchAll();
    $unclaimedFoundCount = (int)db()->query("SELECT COUNT(*) FROM found_item_records WHERE status='Unclaimed'")->fetchColumn();
  } catch (Throwable $e) {}
  $totalNotifs = $unreadClaimsCount;

  $groups = [
    'Overview' => [
      ['dashboard','Dashboard','M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    ],
    'Records' => [
      ['users','Users','M16 11a4 4 0 10-8 0 4 4 0 008 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
      ['lost','Lost Records','M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
      ['found','Found Item Records','M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
      ['claims','Claim Records','M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
      ['matching','Matching','M4 6h16M4 12h16M4 18h7'],
    ],
    'Users & Management' => $isAdmin ? [
      ['departments','Departments','M3 7h18M3 12h18M3 17h18'],
    ] : [],
    'Reports' => [
      ['reports','Reports','M9 19V6h13M9 19a2 2 0 11-4 0 2 2 0 014 0z'],
      ['activity','Activity Logs','M12 8v4l3 3M12 21a9 9 0 100-18 9 9 0 000 18z'],
    ],
    'System' => [
      ['profile','Profile','M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0z'],
      ['settings','Settings','M11.49 3.17c-.32-1.03-1.59-1.03-1.91 0a1.65 1.65 0 01-2.59.79 1.65 1.65 0 00-2.34 2.34 1.65 1.65 0 01-.79 2.59c-1.03.32-1.03 1.59 0 1.91a1.65 1.65 0 01.79 2.59 1.65 1.65 0 002.34 2.34 1.65 1.65 0 012.59.79c.32 1.03 1.59 1.03 1.91 0a1.65 1.65 0 012.59-.79 1.65 1.65 0 002.34-2.34 1.65 1.65 0 01.79-2.59c1.03-.32 1.03-1.59 0-1.91a1.65 1.65 0 01-.79-2.59 1.65 1.65 0 00-2.34-2.34 1.65 1.65 0 01-2.59-.79zM12 15a3 3 0 100-6 3 3 0 000 6z'],
    ],
  ];
?>
<div class="flex min-h-screen">
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-72 bg-sidebar text-slate-100 lg:static lg:translate-x-0">
    <div class="flex h-20 items-center gap-3 border-b border-white/10 px-5">
      <img src="assets/logo.jpg" alt="Lost & Found logo" class="h-12 w-12 rounded-xl object-cover ring-2 ring-white/20">
      <div class="leading-tight"><div class="text-base font-black tracking-wide text-white">LOST &amp; FOUND</div><div class="text-[10px] font-bold uppercase tracking-[.22em] text-slate-300">Management System</div></div>
    </div>
    <nav class="px-3 py-4 space-y-1 overflow-y-auto h-[calc(100vh-5rem)] scrollbar-thin">
      <?php foreach ($groups as $gname => $items): if (!$items) continue; ?>
        <div class="sidebar-section"><?=e($gname)?></div>
        <?php foreach ($items as $it): $active = ($page === $it[0]); ?>
          <a href="index.php?page=<?=$it[0]?>" class="sidebar-link <?=$active?'active':''?>" <?=$active?'aria-current="page"':''?>>
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?=$it[2]?>"/></svg>
            <span><?=e($it[1])?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="sidebar-section">Account</div>
      <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="logout">
        <button class="sidebar-link w-full text-left text-rose-300 hover:!bg-rose-500/20 hover:!text-rose-100">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          <span>Logout</span>
        </button>
      </form>
    </nav>
  </aside>

  <div class="flex-1 min-w-0">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
      <div class="flex items-center justify-between gap-3 px-4 py-3 lg:px-8">
        <div class="flex items-center gap-3">
          <button id="menuBtn" class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-navy hover:bg-slate-50 lg:hidden" aria-label="Open menu">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <div>
            <div class="text-[10px] font-bold uppercase tracking-[.18em] text-blue">Lost &amp; Found</div>
            <div class="text-lg font-black text-navy capitalize"><?=e(str_replace('-',' ', $page === 'report-lost' ? 'Report Lost' : ($page === 'report-found' ? 'Report Found' : $page)))?></div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <div class="relative">
            <button id="notifBtn" class="relative grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-navy transition" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0"/></svg>
              <?php if ($totalNotifs > 0): ?>
                <span class="absolute -right-1 -top-1 grid h-5 min-w-[1.25rem] place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white shadow-sm"><?=$totalNotifs?></span>
              <?php endif; ?>
            </button>
            <div id="notifMenu" class="absolute right-0 mt-2 hidden w-80 sm:w-96 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50">
              <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 bg-slate-50/70">
                <div class="flex items-center gap-2">
                  <span class="font-black text-navy text-sm">Notifications</span>
                  <?php if ($unreadClaimsCount > 0): ?>
                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-700"><?=$unreadClaimsCount?> Pending</span>
                  <?php endif; ?>
                </div>
                <a href="index.php?page=claims" class="text-xs font-bold text-blue hover:underline">View claims →</a>
              </div>
              <div class="max-h-[380px] overflow-y-auto divide-y divide-slate-100 scrollbar-thin">
                <?php if ($notifPendingClaims): ?>
                  <div class="p-2">
                    <div class="px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">
                      <?=$isAdmin ? 'Claims Awaiting Review' : 'Your Pending Claims'?>
                    </div>
                    <?php foreach ($notifPendingClaims as $nc): ?>
                      <a href="index.php?page=claims&q=<?=urlencode($nc['claim_id'])?>" class="flex items-start gap-3 rounded-xl p-2.5 transition hover:bg-slate-50 group">
                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600 group-hover:bg-amber-100">
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                          <div class="flex items-center justify-between gap-1">
                            <span class="font-bold text-xs text-navy group-hover:text-blue truncate"><?=e($nc['item_name'])?></span>
                            <span class="text-[10px] font-semibold text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded"><?=e($nc['status'])?></span>
                          </div>
                          <p class="text-[11px] text-slate-500 truncate mt-0.5">Claimant: <?=e($nc['claimant'])?> · <span class="font-mono text-blue font-semibold"><?=e($nc['claim_id'])?></span></p>
                          <p class="text-[10px] text-slate-400 mt-0.5"><?=e($nc['location_found'])?> · <?=e($nc['date_claimed'])?></p>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if ($notifUnclaimedFound): ?>
                  <div class="p-2 bg-slate-50/30">
                    <div class="flex items-center justify-between px-2.5 py-1">
                      <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Unclaimed Found Item Records (<?=$unclaimedFoundCount?>)</span>
                      <a href="index.php?page=found&status=Unclaimed" class="text-[11px] font-bold text-blue hover:underline">View all →</a>
                    </div>
                    <?php foreach ($notifUnclaimedFound as $uf): ?>
                      <a href="index.php?page=found&q=<?=urlencode($uf['found_id'])?>" class="flex items-start gap-3 rounded-xl p-2 transition hover:bg-white group">
                        <div class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4M4 6v12c0 1.1.9 2 2 2h14v-4"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                          <div class="flex items-center justify-between">
                            <span class="font-bold text-xs text-navy group-hover:text-blue truncate"><?=e($uf['item_name'])?></span>
                            <span class="text-[10px] text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded font-medium">Unclaimed</span>
                          </div>
                          <p class="text-[10px] text-slate-400 truncate"><?=e($uf['location_found'])?> · Found on <?=e($uf['date_found'])?></p>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

                <?php if (!$notifPendingClaims && !$notifUnclaimedFound): ?>
                  <div class="p-6 text-center text-slate-500">
                    <div class="mx-auto grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-slate-400 mb-2">
                      <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </div>
                    <p class="text-xs font-bold text-navy">No pending notifications</p>
                    <p class="text-[11px] text-slate-400 mt-1">All claim records are processed and no items are pending review.</p>
                  </div>
                <?php endif; ?>
              </div>
              <div class="border-t border-slate-100 p-2 bg-slate-50/60 flex justify-between gap-2">
                <a href="index.php?page=claims" class="btn btn-soft w-full justify-center text-xs py-1.5">Go to Claim Records</a>
                <a href="index.php?page=found" class="btn btn-ghost w-full justify-center text-xs py-1.5">Found Item Records</a>
              </div>
            </div>
          </div>
          <div class="relative">
            <button id="profileBtn" class="flex items-center gap-2 rounded-xl border border-slate-200 px-2 py-1.5 hover:bg-slate-50" aria-haspopup="true" aria-expanded="false">
              <span class="grid h-8 w-8 place-items-center rounded-full bg-blue text-sm font-bold text-white"><?=e(initials($u['name'] ?? ''))?></span>
              <span class="hidden text-left sm:block">
                <span class="block text-sm font-bold text-navy leading-tight"><?=e($u['name'] ?? 'User')?></span>
                <span class="block text-[10px] font-bold uppercase tracking-wide text-slate-500"><?=e($u['role'] ?? 'Guest')?></span>
              </span>
              <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div id="profileMenu" class="absolute right-0 mt-2 hidden w-48 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
              <a href="index.php?page=profile" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Profile</a>
              <a href="index.php?page=settings" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Settings</a>
              <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="logout"><button class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">Logout</button></form>
            </div>
          </div>
        </div>
      </div>
    </header>
    <div id="sidebarBackdrop" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>
    <main class="px-4 py-6 lg:px-8">
<?php else: ?>
<?php if ($page !== 'login'): ?>
<header class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur">
  <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 lg:px-8">
    <a href="index.php?page=home" class="flex items-center gap-3">
      <img src="assets/logo.jpg" alt="Lost & Found Management System logo" class="h-11 w-11 rounded-xl object-cover shadow-sm">
      <div class="leading-tight hidden sm:block"><div class="font-black text-navy">LOST & FOUND</div><div class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Management System</div></div>
    </a>
    <nav class="flex items-center gap-1">
      <?php if(is_logged_in()): ?>
        <a href="index.php?page=dashboard" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Dashboard</a>
        <form method="post" class="ml-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="logout"><button class="rounded-xl bg-navy px-4 py-2 text-sm font-bold text-white">Sign out</button></form>
      <?php else: ?>
        <a id="publicSignInLink" href="index.php?page=login" class="ml-2 rounded-xl bg-navy px-4 py-2 text-sm font-bold text-white">Sign in</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php endif; ?>
<main class="mx-auto max-w-7xl px-4 py-10 lg:px-8">
<?php endif; ?>
<?php flash_toast($flash); ?>
<?php }

function layout_footer(): void { ?>
    </main>
  </div>
</div>
<footer class="border-t border-slate-200 bg-white py-6 text-center text-xs text-slate-500">© <?=date('Y')?> Lost &amp; Found Management System </footer>
<script>
(function(){
  const btn=document.getElementById('menuBtn'), sb=document.getElementById('sidebar'), bd=document.getElementById('sidebarBackdrop');
  if(btn && sb){ btn.addEventListener('click',()=>{sb.classList.toggle('open'); if(bd) bd.classList.toggle('hidden');}); if(bd) bd.addEventListener('click',()=>{sb.classList.remove('open'); bd.classList.add('hidden');}); }
  const nb=document.getElementById('notifBtn'), nm=document.getElementById('notifMenu');
  const pb=document.getElementById('profileBtn'), pm=document.getElementById('profileMenu');
  if(nb && nm){
    nb.addEventListener('click',(e)=>{
      e.stopPropagation();
      if(pm) pm.classList.add('hidden');
      nm.classList.toggle('hidden');
    });
    nm.addEventListener('click',(e)=>{ e.stopPropagation(); });
  }
  if(pb && pm){
    pb.addEventListener('click',(e)=>{
      e.stopPropagation();
      if(nm) nm.classList.add('hidden');
      pm.classList.toggle('hidden');
    });
    pm.addEventListener('click',(e)=>{ e.stopPropagation(); });
  }
  document.addEventListener('click',()=>{
    if(nm) nm.classList.add('hidden');
    if(pm) pm.classList.add('hidden');
  });
  document.querySelectorAll('[data-confirm]').forEach(el=>{ el.addEventListener('submit',function(ev){ if(!confirm(el.getAttribute('data-confirm'))) ev.preventDefault(); }); });
  document.querySelectorAll('[data-filter]').forEach(inp => {
    const target = document.querySelector(inp.getAttribute('data-filter'));
    if (!target) return;
    inp.addEventListener('input', () => {
      const q = inp.value.toLowerCase().trim();
      let visibleCount = 0;
      const dataRows = target.querySelectorAll('tbody tr:not(.empty-state-row)');
      dataRows.forEach(tr => {
        const matches = !q || tr.textContent.toLowerCase().includes(q);
        tr.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
      });
      let clientMsg = target.querySelector('.client-filter-empty-row');
      if (visibleCount === 0 && dataRows.length > 0) {
        if (!clientMsg) {
          clientMsg = document.createElement('tr');
          clientMsg.className = 'client-filter-empty-row empty-state-row';
          const colCount = target.querySelectorAll('thead th').length || 8;
          clientMsg.innerHTML = `<td colspan="${colCount}" class="px-4 py-12 text-center text-slate-500">` +
            `<div class="mx-auto flex max-w-sm flex-col items-center justify-center">` +
            `<div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">` +
            `<svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>` +
            `</div>` +
            `<p class="mt-3 text-sm font-bold text-navy">No items found</p>` +
            `<p class="mt-1 text-xs text-slate-500">No records match your search keyword.</p>` +
            `</div></td>`;
          const tbody = target.querySelector('tbody');
          if (tbody) tbody.appendChild(clientMsg);
        }
        clientMsg.style.display = '';
      } else if (clientMsg) {
        clientMsg.style.display = 'none';
      }
    });
  });
  const signInPanel=document.getElementById('signInPanel'), registerPanel=document.getElementById('registerPanel');
  const showRegister=document.getElementById('showRegister'), showSignIn=document.getElementById('showSignIn');
  const publicSignInLink=document.getElementById('publicSignInLink');
  const loginPassword=document.getElementById('loginPassword'), toggleLoginPassword=document.getElementById('toggleLoginPassword'), loginPasswordIcon=document.getElementById('loginPasswordIcon');
  const loginUserId=document.getElementById('loginUserId');
  const toggleAuthPanel=(register)=>{
    if(signInPanel) signInPanel.classList.toggle('hidden', register);
    if(registerPanel) registerPanel.classList.toggle('hidden', !register);
    if(publicSignInLink) publicSignInLink.classList.toggle('hidden', register);
  };
  const registerContact=document.getElementById('registerContact'), registerPassword=document.getElementById('registerPassword');
  const clearAuthFields=()=>{
    if(loginUserId) loginUserId.value='';
    if(loginPassword) loginPassword.value='';
    if(registerContact) registerContact.value='';
    if(registerPassword) registerPassword.value='';
  };
  clearAuthFields();
  if(showRegister) showRegister.addEventListener('click',()=>{ clearAuthFields(); toggleAuthPanel(true); });
  if(showSignIn) showSignIn.addEventListener('click',()=>toggleAuthPanel(false));
  if(loginUserId && loginPassword){
    loginUserId.value='';
    loginPassword.value='';
  }
  if(loginPassword && toggleLoginPassword){
    toggleLoginPassword.addEventListener('click',()=>{
      const visible=loginPassword.type==='text';
      loginPassword.type=visible?'password':'text';
      toggleLoginPassword.setAttribute('aria-label',visible?'Show password':'Hide password');
      toggleLoginPassword.title=visible?'Show password':'Hide password';
      if(loginPasswordIcon) loginPasswordIcon.innerHTML=visible
        ? '<path d="M2.06 12.35a1 1 0 0 1 0-.7C3.7 7.56 7.53 5 12 5s8.3 2.56 9.94 6.65a1 1 0 0 1 0 .7C20.3 16.44 16.47 19 12 19s-8.3-2.56-9.94-6.65Z"/><circle cx="12" cy="12" r="3"/>'
        : '<path d="m3 3 18 18"/><path d="M10.58 10.58a2 2 0 0 0 2.83 2.83"/><path d="M9.9 4.24A10.8 10.8 0 0 1 12 4c4.47 0 8.3 2.56 9.94 6.65a1 1 0 0 1 0 .7 10.8 10.8 0 0 1-4.03 4.72"/><path d="M6.61 6.61A10.8 10.8 0 0 0 2.06 11.65a1 1 0 0 0 0 .7C3.7 16.44 7.53 19 12 19c1.06 0 2.08-.15 3.03-.43"/>';
    });
  }
})();
</script>
</body></html>
<?php }


layout_header($page, $flash);

if ($page === 'home'):
  if (is_logged_in()) { redirect('dashboard'); }
?>
<section class="rounded-2xl border border-slate-200 bg-white p-8 lg:p-14">
  <div class="grid gap-10 lg:grid-cols-[1.15fr_.85fr] lg:items-center">
    <div>
      <div class="mb-5 inline-flex items-center gap-2 rounded-full border border-blue-100 bg-sky px-3 py-1.5 text-xs font-bold uppercase tracking-[.16em] text-blue">Smart campus item recovery</div>
      <h1 class="max-w-3xl text-4xl font-black leading-tight tracking-tight text-ink sm:text-5xl">Lost something? <span class="text-blue">Found something?</span></h1>
      <p class="mt-5 max-w-2xl text-lg leading-8 text-slate-600">A centralized workflow for reporting lost items, registering found property, matching records, submitting claims, and verifying returns.</p>
      <div class="mt-8 flex flex-wrap gap-3"><a href="index.php?page=login" class="btn btn-primary px-5 py-3">Sign in to continue</a></div>
    </div>
    <div class="flex justify-center"><img src="assets/logo.jpg" alt="Lost & Found logo" class="h-56 w-56 rounded-3xl object-cover shadow"></div>
  </div>
</section>
<?php elseif ($page === 'login'): ?>
<div class="-mx-4 -my-10 flex min-h-[calc(100vh-4.5rem)] flex-col items-center justify-center gap-0 px-4 py-10 lg:mx-0 lg:my-0 lg:min-h-[80vh]">
  <div class="flex w-full max-w-xl flex-col gap-[50px]">
    <div class="overflow-hidden rounded-2xl shadow-soft bg-gradient-to-br from-navy to-blue p-8 text-white sm:p-10">
        <div class="text-center">
          <img src="assets/logo.jpg" alt="Lost & Found logo" class="mx-auto h-24 w-24 rounded-3xl object-cover shadow-lg ring-4 ring-white/20 lg:h-32 lg:w-32">
          <div class="mt-4 text-xl font-black tracking-wide lg:mt-6 lg:text-2xl">LOST &amp; FOUND</div>
          <div class="text-xs font-bold uppercase tracking-[.28em] text-white/80">Management System</div>
          <p class="mx-auto mt-3 max-w-xs text-sm text-white/80 lg:mt-4">Find it. Report it. Get it back.</p>
        </div>
    </div>
    <div id="authCard" class="rounded-2xl bg-white px-4 pb-6 pt-8 shadow-soft sm:p-8">
      <div id="signInPanel">
      <h1 class="text-xl font-black text-navy lg:text-2xl">Sign in</h1>
      <p class="mt-1 text-sm text-slate-500">Use a registered account to continue.</p>
      <form method="post" autocomplete="off" class="mt-5 space-y-3 lg:mt-6 lg:space-y-4">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="login">
        <div><label class="block text-[10px] font-bold uppercase tracking-wide text-slate-600 lg:text-xs">User ID</label><input id="loginUserId" name="user_id" autocomplete="off" readonly onfocus="this.removeAttribute('readonly')" required class="input mt-1 py-1.5 text-xs lg:py-2 lg:text-sm" placeholder="Enter your user ID"></div>
        <div><label class="block text-[10px] font-bold uppercase tracking-wide text-slate-600 lg:text-xs">Password</label><div class="relative mt-1"><input id="loginPassword" type="password" name="password" autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly')" required class="input py-1.5 pr-12 text-xs lg:py-2 lg:text-sm" placeholder="Enter your password"><button type="button" id="toggleLoginPassword" class="absolute inset-y-0 right-0 grid w-10 place-items-center text-slate-400 hover:text-blue lg:w-12" aria-label="Show password" title="Show password"><svg id="loginPasswordIcon" class="h-4 w-4 lg:h-5 lg:w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.06 12.35a1 1 0 0 1 0-.7C3.7 7.56 7.53 5 12 5s8.3 2.56 9.94 6.65a1 1 0 0 1 0 .7C20.3 16.44 16.47 19 12 19s-8.3-2.56-9.94-6.65Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
        <button class="btn btn-primary w-full justify-center py-1.5 text-xs lg:py-2 lg:text-sm">Sign in</button>
      </form>
      <div class="mt-6 border-t border-slate-200 pt-4">
        <button type="button" id="showRegister" class="text-sm font-bold text-blue">Create a new account</button>
      </div>
      </div>
      <div id="registerPanel" class="hidden">
          <h1 class="text-2xl font-black text-navy">Create an account</h1>
          <p class="mt-1 text-sm text-slate-500">Register your account to continue.</p>
          <form method="post" autocomplete="off" class="mt-4 space-y-2">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="register">
            <input name="name" required class="input py-1.5" placeholder="Full name">
            <div class="grid grid-cols-2 gap-2">
              <select name="role" class="input py-1.5"><option>Student</option><option>Instructor</option><option>Employee</option><option>Outsider</option></select>
              <input name="department" class="input py-1.5" placeholder="Department">
            </div>
            <input id="registerContact" name="contact" autocomplete="off" readonly onfocus="this.removeAttribute('readonly')" class="input py-1.5" placeholder="Contact">
            <input id="registerPassword" type="password" name="password" autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly')" minlength="6" required class="input py-1.5" placeholder="Password (6+ chars)">
            <button class="btn btn-soft w-full justify-center py-1.5">Create account</button>
          </form>
          <button type="button" id="showSignIn" class="mt-4 text-sm font-bold text-blue">Back to sign in</button>
      </div>
    </div>
  </div>
</div>
<?php elseif ($page === 'dashboard'):
  $recentLost = db()->query("SELECT l.*,c.category_name,u.name AS reporter FROM lost_records l JOIN categories c ON c.category_id=l.category_id JOIN users u ON u.user_id=l.user_id ORDER BY l.created_at DESC LIMIT 5")->fetchAll();
  $recentFound = db()->query("SELECT f.*,c.category_name,u.name AS reporter FROM found_item_records f JOIN categories c ON c.category_id=f.category_id JOIN users u ON u.user_id=f.user_id ORDER BY f.created_at DESC LIMIT 5")->fetchAll();
  $recentClaims = db()->query("SELECT c.*,f.item_name,u.name AS claimant FROM claim_records c JOIN found_item_records f ON f.found_id=c.found_id JOIN users u ON u.user_id=c.user_id ORDER BY c.created_at DESC LIMIT 5")->fetchAll();
  $series = chart_series();
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <?php
  $cards = [
    ['Lost Records', (int)$st['lost_total'], 'Reported items awaiting match', 'lost', 'stat-blue', '#1D63B8', 'M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['Found Item Records', (int)$st['found_total'], (int)$st['unclaimed_total'].' unclaimed', 'found', 'stat-green', '#10b981', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['Claim Records', (int)$st['approved_total'] + (int)$st['pending_total'], (int)$st['pending_total'].' pending review', 'claims', 'stat-orange', '#f59e0b', 'M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
    ['Users', (int)$st['users_total'], 'Registered accounts', is_admin() ? 'users' : 'settings', 'stat-violet', '#8b5cf6', 'M16 11a4 4 0 10-8 0 4 4 0 008 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
  ];
  foreach ($cards as $c): ?>
  <div class="card stat-card <?=$c[4]?> p-5">
    <div class="flex items-start justify-between">
      <div>
        <div class="text-xs font-bold uppercase tracking-wider text-slate-500"><?=$c[0]?></div>
        <div class="mt-2 text-3xl font-black text-navy"><?=number_format($c[1])?></div>
        <div class="mt-1 text-xs text-slate-500"><?=$c[2]?></div>
        <a href="index.php?page=<?=$c[3]?>" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-blue hover:underline">View all →</a>
      </div>
      <div class="grid h-11 w-11 place-items-center rounded-xl" style="background:<?=$c[5]?>1a;color:<?=$c[5]?>">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?=$c[6]?>"/></svg>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="mt-6 grid gap-4 xl:grid-cols-2">
  <div class="card p-5">
    <div class="flex items-center justify-between"><h3 class="text-base font-black text-navy">Recent Lost Records</h3><a href="index.php?page=lost" class="text-xs font-bold text-blue">View all →</a></div>
    <div class="mt-3 overflow-x-auto scrollbar-thin">
      <table class="table w-full text-left"><thead><tr class="border-b border-slate-100"><th class="py-2 pr-3">Item</th><th class="py-2 pr-3">Category</th><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Location</th><th class="py-2 pr-3">Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentLost as $r): ?>
          <tr class="border-b border-slate-50"><td class="py-2 pr-3 font-semibold text-navy"><?=e($r['item_name'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['category_name'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['date_lost'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['location_lost'])?></td><td class="py-2 pr-3"><?=status_badge($r['status'])?></td></tr>
        <?php endforeach; if (!$recentLost): ?><tr><td colspan="5" class="py-4 text-center text-slate-500">No lost records yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card p-5">
    <div class="flex items-center justify-between"><h3 class="text-base font-black text-navy">Recent Found Item Records</h3><a href="index.php?page=found" class="text-xs font-bold text-blue">View all →</a></div>
    <div class="mt-3 overflow-x-auto scrollbar-thin">
      <table class="table w-full text-left"><thead><tr class="border-b border-slate-100"><th class="py-2 pr-3">Item</th><th class="py-2 pr-3">Category</th><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Location</th><th class="py-2 pr-3">Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentFound as $r): ?>
          <tr class="border-b border-slate-50"><td class="py-2 pr-3 font-semibold text-navy"><?=e($r['item_name'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['category_name'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['date_found'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['location_found'])?></td><td class="py-2 pr-3"><?=status_badge($r['status'])?></td></tr>
        <?php endforeach; if (!$recentFound): ?><tr><td colspan="5" class="py-4 text-center text-slate-500">No found items yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="mt-6 grid gap-4 xl:grid-cols-2">
  <div class="card p-5">
    <h3 class="text-base font-black text-navy">Recent Claim Records</h3>
    <div class="mt-3 overflow-x-auto scrollbar-thin">
      <table class="table w-full text-left"><thead><tr class="border-b border-slate-100"><th class="py-2 pr-3">Item</th><th class="py-2 pr-3">Claimed By</th><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Claim ID</th></tr></thead>
        <tbody>
        <?php foreach ($recentClaims as $r): ?>
          <tr class="border-b border-slate-50"><td class="py-2 pr-3 font-semibold text-navy"><?=e($r['item_name'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['claimant'])?></td><td class="py-2 pr-3 text-slate-600"><?=e($r['date_claimed'])?></td><td class="py-2 pr-3"><?=status_badge($r['status'])?></td><td class="py-2 pr-3 font-mono text-xs text-blue"><?=e($r['claim_id'])?></td></tr>
        <?php endforeach; if (!$recentClaims): ?><tr><td colspan="5" class="py-4 text-center text-slate-500">No claim records yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card min-w-0 p-5">
    <h3 class="text-base font-black text-navy">Summary Overview</h3>
    <p class="text-xs text-slate-500">Grouped by month from the live database.</p>
    <div class="relative mt-4 h-64 w-full overflow-hidden"><canvas id="dashChart" aria-label="Lost, Found, and Claims summary chart"></canvas></div>
  </div>
</div>
<script>
(function(){
  const el = document.getElementById('dashChart');
  if (!el || typeof Chart === 'undefined') return;
  new Chart(el.getContext('2d'), {
    type: 'line',
    data: {
      labels: <?=json_encode($series['labels'])?>,
      datasets: [
        { label:'Lost Items', data: <?=json_encode($series['lost'])?>, borderColor:'#1D63B8', backgroundColor:'rgba(29,99,184,.12)', tension:.3, fill:true, pointRadius:2, pointHoverRadius:4 },
        { label:'Found Item Records', data: <?=json_encode($series['found'])?>, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.10)', tension:.3, fill:true, pointRadius:2, pointHoverRadius:4 },
        { label:'Claims', data: <?=json_encode($series['claims'])?>, borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,.10)', tension:.3, fill:true, pointRadius:2, pointHoverRadius:4 },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12 } } },
      scales: {
        x: { ticks: { autoSkip: true, maxRotation: 0, minRotation: 0, maxTicksLimit: 8 }, grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(15,42,77,.06)' } }
      }
    }
  });
  if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver(() => { const c = Chart.getChart('dashChart'); if (c) c.resize(); });
    const card = el.closest('.card'); if (card) ro.observe(card);
  }
})();
</script>

<?php elseif ($page === 'lost'):
  require_login();
  $q = trim($_GET['q'] ?? ''); $status = $_GET['status'] ?? '';
  $sql = "SELECT l.*,c.category_name,u.name AS reporter FROM lost_records l JOIN categories c ON c.category_id=l.category_id JOIN users u ON u.user_id=l.user_id WHERE 1=1";
  $params = [];
  if ($q !== '') {
    $sql .= ' AND (l.item_name LIKE ? OR c.category_name LIKE ? OR l.color LIKE ? OR l.location_lost LIKE ? OR l.lost_id LIKE ? OR u.name LIKE ?)';
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
  }
  if (in_array($status, ['Pending','Matched','Returned'], true)) { $sql .= ' AND l.status=?'; $params[] = $status; }
  $sql .= ' ORDER BY l.created_at DESC, l.lost_id DESC';
  $stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
?>
<div class="card p-5">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div><h1 class="text-2xl font-black text-navy">Lost Records</h1><p class="text-sm text-slate-500">All reported lost items.</p></div>
    <a href="index.php?page=report-lost" class="btn btn-primary">+ Add Lost Report</a>
  </div>
  <form method="get" action="index.php" class="mt-4 grid gap-3 sm:grid-cols-[1fr_180px_auto_auto]">
    <input type="hidden" name="page" value="lost">
    <input name="q" value="<?=e($q)?>" placeholder="Search by item, category, color, location, or ID" class="input" data-filter="#lostTable">
    <select name="status" class="input"><option value="">All statuses</option><option <?=$status==='Pending'?'selected':''?>>Pending</option><option <?=$status==='Matched'?'selected':''?>>Matched</option><option <?=$status==='Returned'?'selected':''?>>Returned</option></select>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($q !== '' || $status !== ''): ?>
      <a href="index.php?page=lost" class="btn btn-ghost">Reset</a>
    <?php endif; ?>
  </form>
</div>
<div class="mt-4 card p-0 overflow-hidden">
  <div class="overflow-x-auto scrollbar-thin">
  <table id="lostTable" class="table w-full min-w-[1100px] text-left">
    <thead class="bg-slate-50"><tr><th class="px-4 py-3">Record ID</th><th class="px-4 py-3">Item Name</th><th class="px-4 py-3">Category</th><th class="px-4 py-3">Color</th><th class="px-4 py-3">Date Lost</th><th class="px-4 py-3">Location</th><th class="px-4 py-3">Reported By</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($rows as $r): ?>
      <tr class="hover:bg-slate-50">
        <td class="px-4 py-3 font-bold text-blue"><?=e($r['lost_id'])?></td>
        <td class="px-4 py-3 font-semibold text-navy"><?=e($r['item_name'])?></td>
        <td class="px-4 py-3"><?=e($r['category_name'])?></td>
        <td class="px-4 py-3 text-slate-600"><?=e($r['color'] ?: '—')?></td>
        <td class="px-4 py-3"><?=e($r['date_lost'])?></td>
        <td class="px-4 py-3"><?=e($r['location_lost'])?></td>
        <td class="px-4 py-3"><?=e($r['reporter'])?></td>
        <td class="px-4 py-3"><?=status_badge($r['status'])?></td>
        <td class="px-4 py-3 text-right">
          <div class="flex justify-end gap-2">
            <a href="index.php?page=matching&lost_id=<?=e($r['lost_id'])?>" class="btn btn-soft">View</a>
            <?php if (is_admin()): ?>
              <form method="post" data-confirm="Delete this lost record?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_lost"><input type="hidden" name="lost_id" value="<?=e($r['lost_id'])?>"><button class="btn btn-danger">Delete</button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; if (!$rows): ?>
      <tr class="empty-state-row">
        <td colspan="9" class="px-4 py-12 text-center text-slate-500">
          <div class="mx-auto flex max-w-sm flex-col items-center justify-center">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
              <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <p class="mt-3 text-sm font-bold text-navy">No lost records found</p>
            <p class="mt-1 text-xs text-slate-500"><?= ($q !== '' || $status !== '') ? 'No items match your search criteria. Try a different keyword.' : 'There are no reported lost items yet.' ?></p>
            <?php if ($q !== '' || $status !== ''): ?>
              <a href="index.php?page=lost" class="btn btn-soft mt-3 text-xs">Clear search</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php elseif ($page === 'found'):
  require_login();
  $q = trim($_GET['q'] ?? ''); $status = $_GET['status'] ?? '';
  $sql = "SELECT f.*,c.category_name,u.name AS reporter,my_claim.status AS user_claim_status FROM found_item_records f JOIN categories c ON c.category_id=f.category_id JOIN users u ON u.user_id=f.user_id LEFT JOIN claim_records my_claim ON my_claim.found_id=f.found_id AND my_claim.user_id=? WHERE 1=1";
  $params = [user()['user_id']];
  if ($q !== '') {
    $sql .= ' AND (f.item_name LIKE ? OR c.category_name LIKE ? OR f.color LIKE ? OR f.location_found LIKE ? OR f.found_id LIKE ? OR u.name LIKE ?)';
    $like = "%$q%";
    $params = [$like, $like, $like, $like, $like, $like];
  }
  if (in_array($status, ['Unclaimed','Claimed'], true)) { $sql .= ' AND f.status=?'; $params[] = $status; }
  $sql .= ' ORDER BY f.created_at DESC, f.found_id DESC';
  $stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
?>
<div class="card p-5">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div><h1 class="text-2xl font-black text-navy">Found Item Records</h1><p class="text-sm text-slate-500">All found property in the registry.</p></div>
    <a href="index.php?page=report-found" class="btn btn-primary">+ Add Found Record</a>
  </div>
  <form method="get" action="index.php" class="mt-4 grid gap-3 sm:grid-cols-[1fr_180px_auto_auto]">
    <input type="hidden" name="page" value="found">
    <input name="q" value="<?=e($q)?>" placeholder="Search by item, category, color, location, or ID" class="input" data-filter="#foundTable">
    <select name="status" class="input"><option value="">All statuses</option><option <?=$status==='Unclaimed'?'selected':''?>>Unclaimed</option><option <?=$status==='Claimed'?'selected':''?>>Claimed</option></select>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($q !== '' || $status !== ''): ?>
      <a href="index.php?page=found" class="btn btn-ghost">Reset</a>
    <?php endif; ?>
  </form>
</div>
<div class="mt-4 card p-0 overflow-hidden">
  <div class="overflow-x-auto scrollbar-thin">
  <table id="foundTable" class="table w-full min-w-[1000px] text-left">
    <thead class="bg-slate-50"><tr><th class="px-4 py-3">Item ID</th><th class="px-4 py-3">Item Name</th><th class="px-4 py-3">Category</th><th class="px-4 py-3">Color</th><th class="px-4 py-3">Date Found</th><th class="px-4 py-3">Location</th><th class="px-4 py-3">Found By</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($rows as $r): ?>
      <tr class="hover:bg-slate-50">
        <td class="px-4 py-3 font-bold text-blue"><?=e($r['found_id'])?></td>
        <td class="px-4 py-3 font-semibold text-navy"><?=e($r['item_name'])?></td>
        <td class="px-4 py-3"><?=e($r['category_name'])?></td>
        <td class="px-4 py-3 text-slate-600"><?=e($r['color'] ?: '—')?></td>
        <td class="px-4 py-3"><?=e($r['date_found'])?></td>
        <td class="px-4 py-3"><?=e($r['location_found'])?></td>
        <td class="px-4 py-3"><?=e($r['reporter'])?></td>
        <td class="px-4 py-3"><?=status_badge($r['status'])?></td>
        <td class="px-4 py-3 text-right">
          <div class="flex justify-end gap-2">
            <?php if ($r['status'] === 'Unclaimed' && $r['user_claim_status'] !== 'Approved'): ?>
              <form method="post" data-confirm="Submit a claim for this item?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim"><input type="hidden" name="found_id" value="<?=e($r['found_id'])?>"><button class="btn btn-primary">Claim</button></form>
            <?php elseif ($r['user_claim_status'] === 'Approved'): ?>
              <span class="text-xs font-semibold text-emerald-600">Claim approved</span>
            <?php else: ?>
              <span class="text-xs text-slate-400">Already claimed</span>
            <?php endif; ?>
            <?php if (is_admin()): ?>
              <form method="post" data-confirm="Delete this found record?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_found"><input type="hidden" name="found_id" value="<?=e($r['found_id'])?>"><button class="btn btn-danger">Delete</button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; if (!$rows): ?>
      <tr class="empty-state-row">
        <td colspan="9" class="px-4 py-12 text-center text-slate-500">
          <div class="mx-auto flex max-w-sm flex-col items-center justify-center">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
              <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <p class="mt-3 text-sm font-bold text-navy">No found items found</p>
            <p class="mt-1 text-xs text-slate-500"><?= ($q !== '' || $status !== '') ? 'No items match your search criteria. Try a different keyword.' : 'There are no found items in the registry yet.' ?></p>
            <?php if ($q !== '' || $status !== ''): ?>
              <a href="index.php?page=found" class="btn btn-soft mt-3 text-xs">Clear search</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php elseif ($page === 'claims'):
  require_login();
  $q = trim($_GET['q'] ?? '');
  $filter = $_GET['status'] ?? '';
  $sql = "SELECT c.*,f.item_name,f.color,f.location_found,f.status AS found_status,u.name AS claimant,v.name AS verifier FROM claim_records c JOIN found_item_records f ON f.found_id=c.found_id JOIN users u ON u.user_id=c.user_id LEFT JOIN users v ON v.user_id=c.verified_by WHERE 1=1";
  $params = [];
  if (!is_admin()) { $sql .= ' AND c.user_id=?'; $params[] = user()['user_id']; }
  if ($q !== '') {
    $sql .= ' AND (f.item_name LIKE ? OR c.claim_id LIKE ? OR c.found_id LIKE ? OR u.name LIKE ? OR f.location_found LIKE ? OR IFNULL(f.color,"") LIKE ?)';
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
  }
  if (in_array($filter, ['Pending','Approved','Rejected'], true)) { $sql .= ' AND c.status=?'; $params[] = $filter; }
  $sql .= ' ORDER BY FIELD(c.status,"Pending","Approved","Rejected"), c.created_at DESC';
  $stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
?>
<div class="card p-5">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div><h1 class="text-2xl font-black text-navy">Claim Records</h1><p class="text-sm text-slate-500"><?=is_admin()?'All claims in the system.':'Track your submitted claims.'?></p></div>
  </div>
  <form method="get" action="index.php" class="mt-4 grid gap-3 sm:grid-cols-[1fr_180px_auto_auto]">
    <input type="hidden" name="page" value="claims">
    <input name="q" value="<?=e($q)?>" placeholder="Search by item name, claim ID, claimant, location..." class="input" data-filter="#claimsTable">
    <select name="status" class="input"><option value="">All statuses</option><option <?=$filter==='Pending'?'selected':''?>>Pending</option><option <?=$filter==='Approved'?'selected':''?>>Approved</option><option <?=$filter==='Rejected'?'selected':''?>>Rejected</option></select>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($q !== '' || $filter !== ''): ?>
      <a href="index.php?page=claims" class="btn btn-ghost">Reset</a>
    <?php endif; ?>
  </form>
</div>
<div class="mt-4 card p-0 overflow-hidden">
  <div class="overflow-x-auto scrollbar-thin">
  <table id="claimsTable" class="table w-full min-w-[1000px] text-left">
    <thead class="bg-slate-50"><tr><th class="px-4 py-3">Claim ID</th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Claimant</th><th class="px-4 py-3">Date Claimed</th><th class="px-4 py-3">Verification</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($rows as $r): ?>
      <tr class="hover:bg-slate-50">
        <td class="px-4 py-3 font-bold text-blue"><?=e($r['claim_id'])?></td>
        <td class="px-4 py-3"><div class="font-semibold text-navy"><?=e($r['item_name'])?></div><div class="text-xs text-slate-500"><?=e($r['found_id'])?> · <?=e($r['location_found'])?></div></td>
        <td class="px-4 py-3"><?=e($r['claimant'])?></td>
        <td class="px-4 py-3"><?=e($r['date_claimed'])?></td>
        <td class="px-4 py-3 text-slate-600"><?=e($r['verifier'] ?? 'Not verified')?></td>
        <td class="px-4 py-3"><?=status_badge($r['status'])?></td>
        <td class="px-4 py-3 text-right">
          <div class="flex flex-wrap justify-end gap-2">
            <?php if (is_admin() && $r['status'] === 'Pending'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim_review"><input type="hidden" name="claim_id" value="<?=e($r['claim_id'])?>"><input type="hidden" name="decision" value="Approved"><input type="hidden" name="redirect" value="claims"><button type="submit" class="btn btn-primary">Approve</button></form>
              <form method="post" data-confirm="Reject this claim?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim_review"><input type="hidden" name="claim_id" value="<?=e($r['claim_id'])?>"><input type="hidden" name="decision" value="Rejected"><input type="hidden" name="redirect" value="claims"><button type="submit" class="btn btn-danger">Reject</button></form>
            <?php elseif (is_admin() && $r['status'] === 'Approved'): ?>
              <form method="post" data-confirm="Mark this as returned?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim_review"><input type="hidden" name="claim_id" value="<?=e($r['claim_id'])?>"><input type="hidden" name="decision" value="Returned"><input type="hidden" name="redirect" value="claims"><button type="submit" class="btn btn-soft">Mark Returned</button></form>
              <form method="post" data-confirm="Reject this previously approved claim?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim_review"><input type="hidden" name="claim_id" value="<?=e($r['claim_id'])?>"><input type="hidden" name="decision" value="Rejected"><input type="hidden" name="redirect" value="claims"><button type="submit" class="btn btn-danger">Reject</button></form>
            <?php elseif (is_admin() && $r['status'] === 'Rejected'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim_review"><input type="hidden" name="claim_id" value="<?=e($r['claim_id'])?>"><input type="hidden" name="decision" value="Approved"><input type="hidden" name="redirect" value="claims"><button type="submit" class="btn btn-primary">Approve</button></form>
            <?php else: ?>
              <span class="text-xs text-slate-400">No actions</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; if (!$rows): ?>
      <tr class="empty-state-row">
        <td colspan="7" class="px-4 py-12 text-center text-slate-500">
          <div class="mx-auto flex max-w-sm flex-col items-center justify-center">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
              <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <p class="mt-3 text-sm font-bold text-navy">No claim records found</p>
            <p class="mt-1 text-xs text-slate-500"><?= ($q !== '' || $filter !== '') ? 'No claims match your search criteria. Try a different keyword.' : 'There are no claim records yet.' ?></p>
            <?php if ($q !== '' || $filter !== ''): ?>
              <a href="index.php?page=claims" class="btn btn-soft mt-3 text-xs">Clear search</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php elseif ($page === 'matching'):
  require_login();
  $focusLost = trim($_GET['lost_id'] ?? '');
  $lost = db()->query("SELECT l.*,c.category_name,u.name AS reporter FROM lost_records l JOIN categories c ON c.category_id=l.category_id JOIN users u ON u.user_id=l.user_id WHERE l.status IN ('Pending','Matched') ORDER BY l.created_at DESC")->fetchAll();
  $found = db()->query("SELECT f.*,c.category_name,u.name AS reporter FROM found_item_records f JOIN categories c ON c.category_id=f.category_id JOIN users u ON u.user_id=f.user_id WHERE f.status='Unclaimed' ORDER BY f.created_at DESC")->fetchAll();
  $pairs = [];
  foreach ($lost as $l) foreach ($found as $f) { $s = match_score($l, $f); if ($s >= 60) $pairs[] = ['score'=>$s, 'lost'=>$l, 'found'=>$f]; }
  usort($pairs, fn($a,$b) => $b['score'] <=> $a['score']);
?>
<div class="card p-5"><h1 class="text-2xl font-black text-navy">Lost and Found Matching</h1><p class="text-sm text-slate-500">Compares item name, category, color, and location. Higher percentage = stronger match.</p></div>
<div class="mt-4 grid gap-4">
  <?php if (!$pairs): ?>
    <div class="card p-8 text-center text-slate-500">No matches above 60% confidence yet.</div>
  <?php endif; ?>
  <?php foreach (array_slice($pairs, 0, 25) as $p): $score = $p['score']; $color = $score>=80?'bg-emerald-50 text-emerald-700':($score>=70?'bg-amber-50 text-amber-700':'bg-slate-100 text-slate-700'); ?>
    <div class="card p-5">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-6 flex-1">
          <div><div class="text-[10px] font-bold uppercase tracking-wider text-rose-600">Lost</div><div class="font-black text-navy"><?=e($p['lost']['item_name'])?></div><div class="text-xs text-slate-500"><?=e($p['lost']['category_name'])?> · <?=e($p['lost']['color'])?> · <?=e($p['lost']['location_lost'])?> · <?=e($p['lost']['date_lost'])?></div><div class="text-xs text-slate-400">Reported by <?=e($p['lost']['reporter'])?></div></div>
          <div><div class="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Found</div><div class="font-black text-navy"><?=e($p['found']['item_name'])?></div><div class="text-xs text-slate-500"><?=e($p['found']['category_name'])?> · <?=e($p['found']['color'])?> · <?=e($p['found']['location_found'])?> · <?=e($p['found']['date_found'])?></div><div class="text-xs text-slate-400">Found by <?=e($p['found']['reporter'])?></div></div>
        </div>
        <div class="flex flex-col items-end gap-2">
          <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-black <?=$color?>"><?=$score?>% match</span>
          <?php if ($score >= 80): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="claim"><input type="hidden" name="found_id" value="<?=e($p['found']['found_id'])?>"><button class="btn btn-primary">Auto-claim</button></form>
          <?php endif; ?>
        </div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-3 text-[11px] text-slate-500 sm:grid-cols-4">
        <div>Category: <?=($p['lost']['category_id']===$p['found']['category_id'])?'✓ match':'—'?></div>
        <div>Name: <?=(strcasecmp(trim($p['lost']['item_name']),trim($p['found']['item_name']))===0)?'✓ match':'—'?></div>
        <div>Color: <?=(strcasecmp(trim($p['lost']['color']??''),trim($p['found']['color']??''))===0)?'✓ match':'—'?></div>
        <div>Location: <?=(strcasecmp(trim($p['lost']['location_lost']),trim($p['found']['location_found']))===0)?'✓ match':'—'?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php elseif ($page === 'users'):
  $q = trim($_GET['q'] ?? '');
  $sql = "SELECT * FROM users WHERE 1=1"; $params=[];
  if ($q!=='') { $sql .= ' AND (user_id LIKE ? OR name LIKE ? OR role LIKE ? OR IFNULL(department,"") LIKE ? OR IFNULL(contact,"") LIKE ?)'; $like="%$q%"; $params=[$like,$like,$like,$like,$like]; }
  $sql .= ' ORDER BY created_at DESC, user_id ASC';
  $stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
?>
<div class="card p-5">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div><h1 class="text-2xl font-black text-navy">Users</h1><p class="text-sm text-slate-500">Manage all registered accounts.</p></div>
    <form method="get" action="index.php" class="flex gap-2">
      <input type="hidden" name="page" value="users">
      <input name="q" value="<?=e($q)?>" placeholder="Search by ID, name, role, department" class="input" data-filter="#usersTable">
      <button class="btn btn-primary">Search</button>
      <?php if ($q !== ''): ?>
        <a href="index.php?page=users" class="btn btn-ghost">Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>
<div class="mt-4 card p-0 overflow-hidden"><div class="overflow-x-auto scrollbar-thin">
  <table id="usersTable" class="table w-full min-w-[900px] text-left">
    <thead class="bg-slate-50"><tr><th class="px-4 py-3">User ID</th><th class="px-4 py-3">Full Name</th><th class="px-4 py-3">Contact</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">Department</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Registered</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
      <?php foreach ($rows as $u): ?>
        <tr class="hover:bg-slate-50">
          <td class="px-4 py-3 font-bold text-blue"><?=e($u['user_id'])?></td>
          <td class="px-4 py-3"><div class="flex items-center gap-2"><span class="grid h-8 w-8 place-items-center rounded-full bg-blue text-xs font-bold text-white"><?=e(initials($u['name']))?></span><span class="font-semibold text-navy"><?=e($u['name'])?></span></div></td>
          <td class="px-4 py-3 text-slate-600"><?=e($u['contact'] ?? '—')?></td>
          <td class="px-4 py-3"><?=status_badge($u['role'])?></td>
          <td class="px-4 py-3"><?=e($u['department'] ?? '—')?></td>
          <td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700">Active</span></td>
          <td class="px-4 py-3 text-slate-500 text-xs"><?=e($u['created_at'] ?? '')?></td>
          <td class="px-4 py-3 text-right">
            <?php if (is_admin() && $u['user_id'] !== user()['user_id']): ?>
              <form method="post" data-confirm="Delete user <?=e($u['user_id'])?>?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=e($u['user_id'])?>"><button class="btn btn-danger">Delete</button></form>
            <?php elseif ($u['user_id'] === user()['user_id']): ?>
              <span class="text-xs text-slate-400">You</span>
            <?php else: ?>
              <a href="index.php?page=profile&user_id=<?=urlencode($u['user_id'])?>" class="text-xs font-semibold text-blue hover:underline">View</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr class="empty-state-row">
          <td colspan="8" class="px-4 py-12 text-center text-slate-500">
            <div class="mx-auto flex max-w-sm flex-col items-center justify-center">
              <div class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
              </div>
              <p class="mt-3 text-sm font-bold text-navy">No users found</p>
              <p class="mt-1 text-xs text-slate-500"><?= ($q !== '') ? 'No users match your search keyword.' : 'No registered users found.' ?></p>
              <?php if ($q !== ''): ?>
                <a href="index.php?page=users" class="btn btn-soft mt-3 text-xs">Clear search</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php elseif ($page === 'departments'):
  require_admin();
  $depts = db()->query("SELECT IFNULL(department,'(Unassigned)') AS dept, COUNT(*) AS total, SUM(role='Student') AS students, SUM(role='Instructor') AS instructors, SUM(role='Employee') AS employees, SUM(role='Admin') AS admins FROM users GROUP BY dept ORDER BY total DESC")->fetchAll();
?>
<div class="card p-5"><h1 class="text-2xl font-black text-navy">Departments</h1><p class="text-sm text-slate-500">Breakdown of users by department.</p></div>
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($depts as $d): ?>
    <div class="card p-5">
      <div class="text-sm font-bold text-slate-500"><?=e($d['dept'])?></div>
      <div class="mt-1 text-3xl font-black text-navy"><?=number_format((int)$d['total'])?></div>
      <div class="mt-3 flex flex-wrap gap-2 text-xs">
        <span class="pill bg-blue-50 text-blue"><?=(int)$d['students']?> Students</span>
        <span class="pill bg-emerald-50 text-emerald-700"><?=(int)$d['instructors']?> Instructors</span>
        <span class="pill bg-orange-50 text-orange-700"><?=(int)$d['employees']?> Employees</span>
        <span class="pill bg-violet-50 text-violet-700"><?=(int)$d['admins']?> Admins</span>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php elseif ($page === 'reports'):
  $byCatLost = db()->query("SELECT c.category_name, COUNT(*) AS n FROM lost_records l JOIN categories c ON c.category_id=l.category_id GROUP BY c.category_name ORDER BY n DESC")->fetchAll();
  $byCatFound = db()->query("SELECT c.category_name, COUNT(*) AS n FROM found_item_records f JOIN categories c ON c.category_id=f.category_id GROUP BY c.category_name ORDER BY n DESC")->fetchAll();
  $byStatus = db()->query("SELECT status, COUNT(*) AS n FROM claim_records GROUP BY status")->fetchAll();
  $monthly = chart_series();
?>
<div class="card p-5"><div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><h1 class="text-2xl font-black text-navy">Reports</h1><p class="text-sm text-slate-500">Live analytics from the database.</p></div><button onclick="window.print()" class="btn btn-soft">Export / Print</button></div></div>
<div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-2">
  <div class="card min-w-0 p-5"><h3 class="font-black text-navy">Lost Items by Category</h3><div class="relative mt-4 h-72 w-full overflow-hidden"><canvas id="cLost"></canvas></div></div>
  <div class="card min-w-0 p-5"><h3 class="font-black text-navy">Found Item Records by Category</h3><div class="relative mt-4 h-72 w-full overflow-hidden"><canvas id="cFound"></canvas></div></div>
  <div class="card min-w-0 p-5"><h3 class="font-black text-navy">Claims by Status</h3><div class="relative mt-4 h-72 w-full overflow-hidden"><canvas id="cClaims"></canvas></div></div>
  <div class="card min-w-0 p-5"><h3 class="font-black text-navy">Monthly Lost and Found</h3><div class="relative mt-4 h-72 w-full overflow-hidden"><canvas id="cMonthly"></canvas></div></div>
</div>
<script>
(function(){
  if (typeof Chart === 'undefined') return;
  const palette = ['#1D63B8','#10b981','#f59e0b','#8b5cf6','#ef4444','#0ea5e9','#22c55e','#f97316','#a855f7','#14b8a6'];
  const barColors = (n) => { const out=[]; for (let i=0;i<n;i++) out.push(palette[i % palette.length]); return out; };
  const baseOpts = { responsive:true, maintainAspectRatio:false, animation:false, plugins:{ legend:{ display:false } } };
  const make = (id, type, labels, data, colors) => {
    const el = document.getElementById(id); if (!el) return;
    const opts = Object.assign({}, baseOpts);
    if (type === 'bar') {
      opts.scales = {
        x: { ticks: { autoSkip: true, maxRotation: 0, minRotation: 0 }, grid: { display: false } },
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(15,42,77,.06)' } }
      };
    }
    new Chart(el.getContext('2d'), {
      type, data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: colors, tension:.3, fill: type==='line' }] }, options: opts
    });
  };
  make('cLost', 'bar', <?=json_encode(array_column($byCatLost,'category_name'))?>, <?=json_encode(array_map('intval',array_column($byCatLost,'n')))?>, barColors(<?=count($byCatLost)?>));
  make('cFound', 'bar', <?=json_encode(array_column($byCatFound,'category_name'))?>, <?=json_encode(array_map('intval',array_column($byCatFound,'n')))?>, barColors(<?=count($byCatFound)?>));
  make('cClaims', 'doughnut', <?=json_encode(array_column($byStatus,'status'))?>, <?=json_encode(array_map('intval',array_column($byStatus,'n')))?>, barColors(<?=count($byStatus)?>));
  const ml = document.getElementById('cMonthly'); if (ml) new Chart(ml.getContext('2d'), { type:'line', data:{ labels:<?=json_encode($monthly['labels'])?>, datasets:[{ label:'Lost', data:<?=json_encode($monthly['lost'])?>, borderColor:'#1D63B8', backgroundColor:'rgba(29,99,184,.1)', tension:.3, fill:true },{ label:'Found', data:<?=json_encode($monthly['found'])?>, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.1)', tension:.3, fill:true }] }, options:{ responsive:true, maintainAspectRatio:false, animation:false, plugins:{ legend:{ position:'bottom' } }, scales:{ x:{ ticks:{ autoSkip:true, maxRotation:0 } }, y:{ beginAtZero:true, ticks:{ precision:0 } } } } });
  if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver(() => { for (const id of ['cLost','cFound','cClaims','cMonthly']) { const c = Chart.getChart(id); if (c) c.resize(); } });
    document.querySelectorAll('main .card').forEach(el => ro.observe(el));
  }
})();
</script>
<?php elseif ($page === 'activity'):
  $logs = db()->query("SELECT c.claim_id, c.status, c.date_claimed, c.created_at, u.name AS actor, f.item_name FROM claim_records c LEFT JOIN users u ON u.user_id=c.verified_by JOIN found_item_records f ON f.found_id=c.found_id ORDER BY c.created_at DESC LIMIT 50")->fetchAll();
?>
<div class="card p-5"><h1 class="text-2xl font-black text-navy">Activity Logs</h1><p class="text-sm text-slate-500">Latest claim verifications and decisions.</p></div>
<div class="mt-4 card p-0 overflow-hidden"><div class="overflow-x-auto scrollbar-thin"><table class="table w-full min-w-[700px] text-left"><thead class="bg-slate-50"><tr><th class="px-4 py-3">When</th><th class="px-4 py-3">Claim</th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">By</th></tr></thead><tbody class="divide-y divide-slate-100">
  <?php foreach ($logs as $l): ?><tr><td class="px-4 py-3 text-slate-500 text-xs"><?=e($l['created_at'])?></td><td class="px-4 py-3 font-bold text-blue"><?=e($l['claim_id'])?></td><td class="px-4 py-3"><?=e($l['item_name'])?></td><td class="px-4 py-3"><?=status_badge($l['status'])?></td><td class="px-4 py-3"><?=e($l['actor'] ?? '—')?></td></tr><?php endforeach; if (!$logs): ?><tr><td colspan="5" class="px-4 py-12 text-center text-slate-500">No activity yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php elseif ($page === 'profile'):
  $profileId = trim($_GET['user_id'] ?? '');
  $u = user();
  if ($profileId !== '' && $profileId !== ($u['user_id'] ?? '')) {
    $profileStmt = db()->prepare('SELECT user_id,name,role,department,contact,created_at FROM users WHERE user_id=?');
    $profileStmt->execute([$profileId]);
    $viewedUser = $profileStmt->fetch();
    if ($viewedUser) $u = $viewedUser;
  }
?>
<div class="card p-6">
  <div class="flex items-center gap-4">
    <span class="grid h-16 w-16 place-items-center rounded-2xl bg-blue text-2xl font-black text-white"><?=e(initials($u['name']))?></span>
    <div><h1 class="text-2xl font-black text-navy"><?=e($u['name'])?></h1><div class="text-sm text-slate-500"><?=e($u['role'])?><?= $u['department'] ? ' · '.e($u['department']) : '' ?></div></div>
  </div>
  <div class="mt-6 grid gap-3 sm:grid-cols-2 text-sm">
    <div><div class="text-slate-500">User ID</div><div class="font-semibold text-navy"><?=e($u['user_id'])?></div></div>
    <div><div class="text-slate-500">Contact</div><div class="font-semibold text-navy"><?=e($u['contact'] ?? '—')?></div></div>
    <div><div class="text-slate-500">Department</div><div class="font-semibold text-navy"><?=e($u['department'] ?? '—')?></div></div>
    <div><div class="text-slate-500">Role</div><div class="font-semibold text-navy"><?=e($u['role'])?></div></div>
  </div>
</div>
<?php elseif ($page === 'settings'):
  $allUsers = [];
  try { $allUsers = db()->query("SELECT user_id,name,role,department,contact,created_at FROM users ORDER BY FIELD(role,'Admin','Instructor','Employee','Student','Security Guard','Outsider'), name ASC")->fetchAll(); } catch (Throwable $e) {}
  $me = user();
?>
<div class="card p-6">
  <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div><h1 class="text-2xl font-black text-navy">Settings</h1><p class="text-sm text-slate-500">Application preferences and the user directory.</p></div>
    <a href="<?=is_admin()?'index.php?page=users':'#users-list'?>" class="btn btn-primary"><?=is_admin()?'Open full Users page':'View Users'?></a>
  </div>
  <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_2fr]">
    <div class="space-y-3">
      <div><h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">Appearance</h2></div>
      <label class="block text-sm font-semibold text-slate-700">Theme<input class="input mt-1" value="Light" disabled></label>
      <label class="block text-sm font-semibold text-slate-700">Default landing page
        <select class="input mt-1"><option>Dashboard</option><option>Found Item Records</option><option>Lost Records</option></select>
      </label>
      <button class="btn btn-soft" onclick="alert('Saved locally (demo).')">Save preferences</button>
    </div>
    <div id="users-list" class="min-w-0">
      <div class="flex items-end justify-between">
        <div><h2 class="text-base font-black text-navy">Users</h2><p class="text-xs text-slate-500">All registered accounts in the system.</p></div>
        <span class="pill bg-violet-50 text-violet-700"><?=count($allUsers)?> total</span>
      </div>
      <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 scrollbar-thin">
        <table class="table w-full min-w-[700px] text-left">
          <thead class="bg-slate-50"><tr><th class="px-3 py-2">User</th><th class="px-3 py-2">Role</th><th class="px-3 py-2">Department</th><th class="px-3 py-2">Contact</th><th class="px-3 py-2">Registered</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($allUsers as $uu): $isMe = $uu['user_id'] === $me['user_id']; ?>
              <tr class="<?=$isMe?'bg-sky/40':''?>">
                <td class="px-3 py-2">
                  <div class="flex items-center gap-2">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-blue text-xs font-bold text-white"><?=e(initials($uu['name']))?></span>
                    <div>
                      <div class="font-semibold text-navy"><?=e($uu['name'])?><?= $isMe ? ' <span class="text-[10px] font-bold text-blue">(You)</span>' : '' ?></div>
                      <div class="font-mono text-[11px] text-slate-500"><?=e($uu['user_id'])?></div>
                    </div>
                  </div>
                </td>
                <td class="px-3 py-2"><?=status_badge($uu['role'])?></td>
                <td class="px-3 py-2 text-slate-600"><?=e($uu['department'] ?? '—')?></td>
                <td class="px-3 py-2 text-slate-600"><?=e($uu['contact'] ?? '—')?></td>
                <td class="px-3 py-2 text-xs text-slate-500"><?=e($uu['created_at'] ?? '')?></td>
              </tr>
            <?php endforeach; if (!$allUsers): ?>
              <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">No users found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="mt-2 text-xs text-slate-500">Signed in as <b><?=e($me['name'])?></b> (<?=e($me['role'])?>).<?= is_admin() ? ' Admins can manage and delete accounts from the <a href="index.php?page=users" class="font-bold text-blue">Users page</a>.' : ' Only admins can edit or delete accounts.' ?></p>
    </div>
  </div>
</div>
<?php elseif ($page === 'report-lost' || $page === 'report-found'):
  require_login(); $isLost = ($page === 'report-lost'); $categoryOptions = categories();
?>
<div class="card p-6">
  <h1 class="text-2xl font-black text-navy"><?=$isLost?'Report a lost item':'Report a found item'?></h1>
  <p class="text-sm text-slate-500"><?=$isLost?'Give enough detail for the system to match your record with found property.':'Register found property so its owner can search for it and submit a claim.'?></p>
  <form method="post" class="mt-6 grid gap-4 md:grid-cols-2">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="<?=$isLost?'report_lost':'report_found'?>">
    <label class="block text-sm font-semibold text-slate-700 md:col-span-1">Item name<input name="item_name" required class="input mt-1" placeholder="e.g. Black leather wallet"></label>
    <label class="block text-sm font-semibold text-slate-700 md:col-span-1">Color<input name="color" class="input mt-1" placeholder="e.g. Black"></label>
    <label class="block text-sm font-semibold text-slate-700 md:col-span-1">Category<select name="category_id" required class="input mt-1"><option value="">Select a category</option><?php foreach ($categoryOptions as $category): ?><option value="<?=e($category['category_id'])?>"><?=e($category['category_name'])?></option><?php endforeach; ?></select></label>
    <label class="block text-sm font-semibold text-slate-700 md:col-span-1">Date <?=$isLost?'lost':'found'?><input type="date" name="<?=$isLost?'date_lost':'date_found'?>" value="<?=date('Y-m-d')?>" required class="input mt-1"></label>
    <label class="block text-sm font-semibold text-slate-700 md:col-span-1">Location <?=$isLost?'lost':'found'?><input name="<?=$isLost?'location_lost':'location_found'?>" required class="input mt-1" placeholder="Library, cafeteria, room, parking area..."></label>
    <div class="md:col-span-2 flex justify-end gap-2"><a href="index.php?page=dashboard" class="btn btn-ghost">Cancel</a><button class="btn btn-primary"><?=$isLost?'Submit Lost Report':'Add Found Record'?></button></div>
  </form>
</div>
<?php elseif ($page === 'setup-help'): ?>
<div class="card p-8"><h1 class="text-2xl font-black text-navy">Setup / database connection</h1><p class="mt-3 text-slate-600">Import <code>lost&found_db.sql</code> into phpMyAdmin, then set <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code>, and <code>DB_PASS</code> for your MySQL installation.</p><div class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-600"><b>Default:</b> host 127.0.0.1 · port 3307 · database lost_found_db · user root · password blank.</div></div>
<?php else: ?>
<div class="card p-8 text-center"><h1 class="text-2xl font-black text-navy">Page not found</h1><a href="index.php?page=dashboard" class="btn btn-primary mt-4">Back to Dashboard</a></div>
<?php endif; layout_footer(); ?>
