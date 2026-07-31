<?php
declare(strict_types=1);require_once __DIR__.'/../config/koneksi.php';
$errors=[];foreach(['APP_KEY','DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $key)if(trim((string)env_value($key,''))==='')$errors[]="Missing {$key}";
if(strlen((string)env_value('APP_KEY',''))<32)$errors[]='APP_KEY must be at least 32 characters.';
if((string)env_value('APP_ENV','production')==='production'&&(string)env_value('APP_DEBUG','false')!=='false')$errors[]='APP_DEBUG must be false in production.';
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo"Production configuration OK.\n";
