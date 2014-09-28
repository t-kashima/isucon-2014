isucon-2014
===========

ISUCON 2014

## ベンチマークの取り方

```
$ ./benchmarker bench --api-key 87-2-ncfwse-40rp-a21ade5ac9385add3c84f6e907298d15c1ba14e5
```

## データベースへの接続

```
$ ./mysql -u isucon -pisucon isu4_qualifier
```


## PHP側の改修場所

### *

```
93行目:  $stmt = $db->prepare('SELECT * FROM users WHERE login = :login');
129行目: $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
150行目:  $stmt = $db->prepare('SELECT * FROM login_log WHERE succeeded = 1 AND user_id = :id ORDER BY id DESC LIMIT 2');
```

### Where句内

```
93行目: 
　stmt = $db->prepare('SELECT * FROM users WHERE login = :login');
　=> 
　stmt = $db->prepare('SELECT * FROM users WHERE id = :');
```
