<?php
declare(strict_types=1);
namespace EnglAI\Auth;
final class AccountService{
 public function __construct(private readonly \PDO $pdo){}
 public function register(string $role,string $email,string $name,string $password):int{
  $role=in_array($role,['teacher','student'],true)?$role:'student';$email=mb_strtolower(trim($email));$name=trim($name);
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>190||mb_strlen($name)<2||mb_strlen($name)>120||!self::validPassword($password))throw new \InvalidArgumentException('Data pendaftaran tidak valid.');
  try{$q=$this->pdo->prepare("INSERT INTO users(role,email,name,password_hash,status) VALUES(?,?,?,?, 'active')");$q->execute([$role,$email,$name,password_hash($password,PASSWORD_DEFAULT)]);return(int)$this->pdo->lastInsertId();}catch(\PDOException $e){if((string)$e->getCode()==='23000')throw new \RuntimeException('Akun tidak dapat dibuat dengan data tersebut.');throw$e;}
 }
 public function login(string $email,string $password,string $role):array{
  $email=mb_strtolower(trim($email));$identity=hash('sha256',$email);$ip=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'local').'|'.(string)env_value('APP_KEY','englai'));
  $q=$this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identity_hash=? AND ip_hash=? AND succeeded=0 AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)");$q->execute([$identity,$ip]);if((int)$q->fetchColumn()>=8)throw new \RuntimeException('Login sementara dibatasi. Coba kembali nanti.');
  $q=$this->pdo->prepare('SELECT * FROM users WHERE email=? AND role=? LIMIT 1');$q->execute([$email,$role]);$u=$q->fetch();$ok=$u&&$u['status']==='active'&&password_verify($password,$u['password_hash']);$this->pdo->prepare('INSERT INTO login_attempts(identity_hash,ip_hash,succeeded) VALUES(?,?,?)')->execute([$identity,$ip,$ok?1:0]);
  if(!$ok)throw new \RuntimeException('Email atau password tidak valid.');$this->pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$u['id']]);unset($u['password_hash'],$u['remember_token_hash']);return$u;
 }
 public static function validPassword(string $v):bool{return strlen($v)>=10&&preg_match('/[A-Z]/',$v)&&preg_match('/[a-z]/',$v)&&preg_match('/\d/',$v);}
 public static function establish(array $u):void{session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['user_role']=$u['role'];$_SESSION['user_name']=$u['name'];$_SESSION['authenticated_at']=time();if($u['role']==='teacher'){$_SESSION['admin_authenticated_at']=time();$_SESSION['admin_username']=$u['email'];}}
}
