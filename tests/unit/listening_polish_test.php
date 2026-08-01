<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$js=file_get_contents($root.'/assets/js/listening-game.js');$css=file_get_contents($root.'/assets/css/listening-feedback.css');$service=file_get_contents($root.'/src/Learning/ListeningSessionService.php');$fail=[];$check=static function(bool$ok,string$message)use(&$fail){if(!$ok)$fail[]=$message;};
$check(!str_contains($js,'First play · Replay available')&&!str_contains($js,'First play completed · Replay available'),'Status First play/Replay available harus hilang.');
$check(str_contains($service,'shuffle($distractors)')&&str_contains($service,"'correct_option_id'")&&str_contains($service,"'correct_distribution'"),'Options harus diacak server-side dengan stable correct ID.');
$check(str_contains($service,'sequence_fingerprint')&&str_contains($service,'snapshotize'),'Session snapshot harus immutable dan history-aware.');
$check(str_contains($css,'grid-template-columns:minmax(0,1fr) 82px')&&str_contains($css,'gap:30px')&&str_contains($css,'justify-self:end'),'Area atas Listening harus memiliki alignment desktop yang rapi.');
$check(str_contains($css,'@media(max-width:650px)')&&str_contains($css,'68px'),'Layout atas harus responsif pada mobile.');
$check(!preg_match('/innerHTML|GEMINI_API_KEY|AIza[0-9A-Za-z_-]+/',$js),'Listening frontend harus safe tanpa secret.');
if($fail){fwrite(STDERR,"Listening polish failed:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo"Listening polish targeted OK.\n";
