<?php
use App\Core\Database;

function config(string $key, $default = null) {
    static $root = null;
    if ($root === null) $root = require dirname(__DIR__, 2) . '/config/app.php';
    $value = $root;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
        $value = $value[$segment];
    }
    return $value;
}
function db(): \PDO { return Database::connection(); }
function rows(string $sql, array $params = []): array { $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function row(string $sql, array $params = []): ?array { $st = db()->prepare($sql); $st->execute($params); return $st->fetch() ?: null; }
function scalar(string $sql, array $params = []): int { $st = db()->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
function execute(string $sql, array $params = []): int { $st = db()->prepare($sql); $st->execute($params); return $st->rowCount(); }
function transaction(callable $work) {
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $result = $work();
        if ($owns) $pdo->commit();
        return $result;
    } catch (\Throwable $error) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
// UTF-8 text also works on PHP installations without the optional mbstring module.
function text_length(string $value): int {
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    return preg_match_all('/./us', $value, $matches) ?: 0;
}
function text_slice(string $value, int $offset, ?int $length = null): string {
    if (function_exists('mb_substr')) return mb_substr($value, $offset, $length, 'UTF-8');
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return implode('', array_slice($chars, $offset, $length));
}
function name_initial(string $value): string {
    $first = text_slice(trim($value), 0, 1);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
}
function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_path(): string {
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return $path === '/' || $path === '.' ? '' : rtrim($path, '/');
}
function url(string $route = 'home', array $params = []): string {
    return base_path().'/index.php?'.http_build_query(array_merge(['r' => $route], $params));
}
function asset(string $file): string { return base_path().'/assets/'.rawurlencode($file); }
function go(string $route = 'home', array $params = []): never { header('Location: '.url($route, $params), true, 303); exit; }
function old(string $key, string $fallback = ''): string { return (string)($_SESSION['old'][$key] ?? $fallback); }
function old_store(array $fields): void { $_SESSION['old'] = $fields; }
function flash(string $kind, string $message): void { $_SESSION['flash'][] = ['kind' => $kind, 'message' => $message]; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function csrf_verify(): void {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals(csrf_token(), $_POST['csrf'])) {
        http_response_code(419); exit('Session expired or invalid security token. Refresh the page and try again.');
    }
}
function user(): ?array {
    static $cache = null, $cacheId = null, $loaded = false;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($loaded && $cacheId === $uid) return $cache;
    $loaded = true; $cacheId = $uid;
    if (!$uid) return $cache = null;
    $found = row('SELECT id,full_name,email,student_number,faculty,role,status,created_at FROM users WHERE id=?', [(int)$_SESSION['uid']]);
    if (!$found || $found['status'] !== 'active') { unset($_SESSION['uid']); return $cache = null; }
    return $cache = $found;
}
function require_login(): array { $u = user(); if (!$u) { flash('warning', 'Sign in to continue.'); go('login'); } return $u; }
function require_role(string ...$roles): array { $u = require_login(); if (!in_array($u['role'], $roles, true)) { http_response_code(403); render('error', ['pageTitle'=>'Access denied','errorMessage'=>'You do not have permission to perform this action.']); exit; } return $u; }
function valid_int($value): int { return filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0; }
function post_str(string $key): string { return trim((string)($_POST[$key] ?? '')); }
function pretty_date(?string $value, string $format = 'D, d M Y · g:i A'): string { return $value ? date($format, strtotime($value)) : '—'; }
function now_local(): string { return date('Y-m-d H:i:s'); }
function is_future(string $date): bool { return strtotime($date) > time(); }
function notify(int $uid, string $title, string $message, string $route = ''): void {
    execute('INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)', [$uid,text_slice($title,0,180),$message,text_slice($route,0,255)]);
}
function audit(string $action, string $entity, int $id = 0, string $detail = ''): void {
    execute('INSERT INTO activity_logs(actor_user_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)', [user()['id'] ?? null,$action,$entity,$id ?: null,$detail]);
}
function render(string $view, array $data = []): void {
    extract($data, EXTR_SKIP);
    $viewFile = dirname(__DIR__).'/Views/pages/'.$view.'.php';
    if (!is_file($viewFile)) throw new RuntimeException('Missing view: '.$view);
    require dirname(__DIR__).'/Views/partials/layout.php';
    unset($_SESSION['old']);
}
function clean_like(string $value): string { return str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $value); }
function slugify(string $name): string {
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    return $slug !== '' ? $slug : 'club-'.bin2hex(random_bytes(3));
}
function faculty_options(): array { return ['Faculty of Applied Science','Faculty of Business Studies','Faculty of Technological Studies','University-wide']; }
function short_text(string $text, int $max = 125): string { return text_length($text) > $max ? text_slice($text,0,max(0,$max-1)).'…' : $text; }
function status_pill(string $status): string {
    $labels = ['approved'=>'Approved','pending'=>'Pending','registered'=>'Registered','rejected'=>'Rejected','cancelled'=>'Cancelled','active'=>'Active','suspended'=>'Suspended','draft'=>'Draft','left'=>'Left','completed'=>'Completed','hidden'=>'Hidden','published'=>'Published'];
    $safe = e($status);
    return '<span class="status status-'.e(preg_replace('/[^a-z]/','',$status)).'">'.e($labels[$status] ?? ucfirst($safe)).'</span>';
}
function icon_for(string $category): string {
    $map = ['Technology'=>'⌘','Leadership'=>'✦','Arts & Culture'=>'♪','Environment'=>'♻','Community Service'=>'♡','Professional'=>'◈','Sports'=>'◎','Student Community'=>'✳'];
    return $map[$category] ?? '✳';
}
function message_url(string $link): string {
    $parts=explode('&',$link,2);
    $params=[];if(isset($parts[1]))parse_str($parts[1],$params);
    unset($params['r']);
    return url(preg_match('/^[a-z][a-z0-9\/-]*$/',$parts[0]) ? $parts[0] : 'dashboard',array_filter($params,'is_scalar'));
}
