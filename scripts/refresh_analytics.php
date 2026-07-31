<?php
declare(strict_types=1);require_once __DIR__.'/../config/koneksi.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Analytics\AnalyticsService;
$id=(int)($argv[1]??0);if($id<1){fwrite(STDERR,"Usage: php scripts/refresh_analytics.php <classroom_id>\n");exit(2);}
$metrics=(new AnalyticsService(db()))->refresh($id);echo json_encode(['success'=>true,'classroom_id'=>$id,'calculated_at'=>date(DATE_ATOM),'students'=>$metrics['students']]).PHP_EOL;
