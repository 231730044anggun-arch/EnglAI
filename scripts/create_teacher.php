<?php
declare(strict_types=1);require_once __DIR__.'/../config/koneksi.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Auth\AccountService;
$email=(string)($argv[1]??'');$name=(string)($argv[2]??'');if($email===''||$name===''){fwrite(STDERR,"Usage: php scripts/create_teacher.php email name\nPassword is read interactively.\n");exit(2);}echo'Password: ';$password=trim((string)fgets(STDIN));$id=(new AccountService(db()))->register('teacher',$email,$name,$password);echo"Teacher #{$id} created.\n";
