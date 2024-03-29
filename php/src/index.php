<?php
require_once 'limonade/lib/limonade.php';

/* $m = new Memcache(); */

function configure() {
  $m = new Memcache();
  $m->addServer('localhost', 11211);
  option('m', $m);
  /* print_r($GLOBALS); */
  /* exit(); */
/* $user_id = 'a'; */
/*   $set = option('m')->set($user_id, 'nogeoge', 0, 600); */
/*   var_dump($set); */
/*   $hoge = option('m')->get($user_id); */
/* var_dump($hoge); */
  /*解析スタート*/
  //xhprof_enable();

  option('base_uri', '/');
  option('session', 'isu4_qualifier_session');

  $host = getenv('ISU4_DB_HOST') ?: 'localhost';
  $port = getenv('ISU4_DB_PORT') ?: 3306;
  $dbname = getenv('ISU4_DB_NAME') ?: 'isu4_qualifier';
  $username = getenv('ISU4_DB_USER') ?: 'root';
  $password = getenv('ISU4_DB_PASSWORD');
  $db = null;
  try {
    $db = new PDO(
      'mysql:host=' . $host . ';port=' . $port. ';dbname=' . $dbname,
      $username,
      $password,
      [ PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
      ]
    );
  } catch (PDOException $e) {
    halt("Connection faild: $e");
  }
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  option('db_conn', $db);

  $config = [
    'user_lock_threshold' => getenv('ISU4_USER_LOCK_THRESHOLD') ?: 3,
    'ip_ban_threshold' => getenv('ISU4_IP_BAN_THRESHOLD') ?: 10
  ];
  option('config', $config);
}

function uri_for($path) {
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?: $_SERVER['HTTP_HOST'];
  return 'http://' . $host . $path;
}

function get($key) {
  return set($key);
}

function before() {
  layout('base.html.php');
}

function calculate_password_hash($password, $salt) {
  return hash('sha256', $password . ':' . $salt);
}

function login_log($succeeded, $login, $user_id=null) {
  $db = option('db_conn');

  $date = date('Y-m-d H:i:s');
  $stmt = $db->prepare('INSERT INTO login_log (`created_at`, `user_id`, `login`, `ip`, `succeeded`) VALUES (:date,:user_id,:login,:ip,:succeeded)');
  /* $stmt = $db->prepare('INSERT INTO login_log (`user_id`, `login`, `ip`, `succeeded`) VALUES (:user_id,:login,:ip,:succeeded)'); */
  $stmt->bindValue(':date', $date);
  $stmt->bindValue(':user_id', $user_id);
  $stmt->bindValue(':login', $login);
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
  $stmt->bindValue(':succeeded', $succeeded ? 1 : 0);
  $stmt->execute();
}

function user_locked($user) {
  if (empty($user)) { return null; }

  $user_id = $user['id'];
  if (option('m')->get($user_id)) {
    return true;
  }

  $db = option('db_conn');
  $stmt = $db->prepare('SELECT COUNT(id) AS failures FROM login_log WHERE user_id = :user_id AND id > IFNULL((select id from login_log where user_id = :user_id AND succeeded = 1 ORDER BY id DESC LIMIT 1), 0)');
  $stmt->bindValue(':user_id', $user_id);
  $stmt->execute();
  $log = $stmt->fetch(PDO::FETCH_ASSOC);

  $config = option('config');
  $is_locked = $config['user_lock_threshold'] <= $log['failures'];
  if ($is_locked) {
    option('m')->set($user_id, true, 0, 600);
  }
  return $is_locked;
}

# FIXME
function ip_banned() {
  $ip = $_SERVER['REMOTE_ADDR'];
  
  if (option('m')->get($ip)) {
    return true;
  }

  $db = option('db_conn');
  $stmt = $db->prepare('SELECT COUNT(id) AS failures FROM login_log WHERE ip = :ip AND id > IFNULL((select id from login_log where ip = :ip AND succeeded = 1 ORDER BY id DESC LIMIT 1), 0)');
  $stmt->bindValue(':ip', $ip);
  $stmt->execute();
  $log = $stmt->fetch(PDO::FETCH_ASSOC);

  $config = option('config');
  $is_banned = $config['ip_ban_threshold'] <= $log['failures'];
  if ($is_banned) {
    option('m')->set($ip, true, 0, 600);
  }
  return $is_banned;
}

function attempt_login($login, $password) {
  $db = option('db_conn');

  $stmt = $db->prepare('SELECT id, login, password_hash, salt FROM users WHERE login = :login');
  $stmt->bindValue(':login', $login);
  $stmt->execute();
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (ip_banned()) {
    login_log(false, $login, isset($user['id']) ? $user['id'] : null);
    return ['error' => 'banned'];
  }

  if (user_locked($user)) {
    login_log(false, $login, $user['id']);
    return ['error' => 'locked'];
  }

  if (!empty($user) && calculate_password_hash($password, $user['salt']) == $user['password_hash']) {
    login_log(true, $login, $user['id']);
    return ['user' => $user];
  }
  elseif (!empty($user)) {
    login_log(false, $login, $user['id']);
    return ['error' => 'wrong_password'];
  }
  else {
    login_log(false, $login);
    return ['error' => 'wrong_login'];
  }
}

function current_user() {
  return option('m')->get('user');
  /* if (empty($_SESSION['user_id'])) { */
  /*   return null; */
  /* } */

  /* $db = option('db_conn'); */

  /* $stmt = $db->prepare('SELECT id, login FROM users WHERE id = :id'); */
  /* $stmt->bindValue(':id', $_SESSION['user_id']); */
  /* $stmt->execute(); */
  /* $user = $stmt->fetch(PDO::FETCH_ASSOC); */

  /* if (empty($user)) { */
  /*   unset($_SESSION['user_id']); */
  /*   return null; */
  /* } */

  /* return $user; */
}

function last_login() {
  $user = current_user();
  if (empty($user)) {
    return null;
  }

  $db = option('db_conn');

  $stmt = $db->prepare('SELECT ip, created_at FROM login_log WHERE succeeded = 1 AND user_id = :id ORDER BY id DESC LIMIT 2');
  $stmt->bindValue(':id', $user['id']);
  $stmt->execute();
  $stmt->fetch();
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function banned_ips() {
  $threshold = option('config')['ip_ban_threshold'];
  $ips = [];

  $db = option('db_conn');

  $stmt = $db->prepare('SELECT ip FROM (SELECT ip, MAX(succeeded) as max_succeeded, COUNT(id) as cnt FROM login_log GROUP BY ip) AS t0 WHERE t0.max_succeeded = 0 AND t0.cnt >= :threshold');
  $stmt->bindValue(':threshold', $threshold);
  $stmt->execute();
  $not_succeeded = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  $ips = array_merge($not_succeeded);

  $stmt = $db->prepare('SELECT ip, MAX(id) AS last_login_id FROM login_log WHERE succeeded = 1 GROUP by ip');
  $stmt->execute();
  $last_succeeds = $stmt->fetchAll();

  foreach ($last_succeeds as $row) {
    $stmt = $db->prepare('SELECT COUNT(id) AS cnt FROM login_log WHERE ip = :ip AND :id < id');
    $stmt->bindValue(':ip', $row['ip']);
    $stmt->bindValue(':id', $row['last_login_id']);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    if ($threshold <= $count) {
      array_push($ips, $row['ip']);
    }
  }

  return $ips;
}

function locked_users() {
  $threshold = option('config')['user_lock_threshold'];
  $user_ids = [];

  $db = option('db_conn');

  $stmt = $db->prepare('SELECT login FROM (SELECT user_id, login, MAX(succeeded) as max_succeeded, COUNT(id) as cnt FROM login_log GROUP BY user_id) AS t0 WHERE t0.user_id IS NOT NULL AND t0.max_succeeded = 0 AND t0.cnt >= :threshold');
  $stmt->bindValue(':threshold', $threshold);
  $stmt->execute();
  $not_succeeded = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  $user_ids = array_merge($not_succeeded);

  $stmt = $db->prepare('SELECT user_id, login, MAX(id) AS last_login_id FROM login_log WHERE user_id IS NOT NULL AND succeeded = 1 GROUP BY user_id');
  $stmt->execute();
  $last_succeeds = $stmt->fetchAll();

  foreach ($last_succeeds as $row) {
    $stmt = $db->prepare('SELECT COUNT(id) AS cnt FROM login_log WHERE user_id = :user_id AND :id < id');
    $stmt->bindValue(':user_id', $row['user_id']);
    $stmt->bindValue(':id', $row['last_login_id']);
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    if ($threshold <= $count) {
      array_push($user_ids, $row['login']);
    }
  }

  return $user_ids;
}

dispatch_get('/', function() {
    /* var_dump(getallheaders()); */
    /* var_dump($_SERVER); */
  return html('index.html.php');
});

dispatch_post('/login', function() {
  $result = attempt_login($_POST['login'], $_POST['password']);
  if (!empty($result['user'])) {
    /* session_regenerate_id(true); */
    /* $_SESSION['user_id'] = $result['user']['id']; */
    option('m')->set('user', $result['user'], 0 , 600);
    return redirect_to('/mypage');
  }
  else {
    switch($result['error']) {
      case 'locked':
        flash('notice', 'This account is locked.');
        break;
      case 'banned':
        flash('notice', "You're banned.");
        break;
      default:
        flash('notice', 'Wrong username or password');
        break;
    }
    return redirect_to('/');
  }
});

dispatch_get('/mypage', function() {

  $user = current_user();

  if (empty($user)) {
    flash('notice', 'You must be logged in');
    return redirect_to('/');
  }
  else {
    set('user', $user);
    set('last_login', last_login());
    return html('mypage.html.php');
  }
});

dispatch_get('/report', function() {
  return json_encode([
    'banned_ips' => banned_ips(),
    'locked_users' => locked_users()
  ]);
});


function after($output) {

/*
xhprof_enable();
// プロファイリングの終了
$xhprof_data = xhprof_disable();

$XHPROF_ROOT = '/home/isucon/webapp/php/src';
include_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_lib.php";
include_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_runs.php";

$xhprof_runs = new XHProfRuns_Default();
$paramator = 'xhprof';
$run_id = $xhprof_runs->save_run($xhprof_data, $paramator);
*/
// URLを生成してリンクを作成
//echo "<a href=\"/xhprof/index.php?run=$run_id&source=$paramator\" target='_blank'>プロファイリング結果やで</a>";

return $output;
}

run();
