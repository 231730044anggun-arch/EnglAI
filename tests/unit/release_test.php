<?php
$root=dirname(__DIR__,2);check(trim((string)file_get_contents($root.'/VERSION'))==='1.0.0','Version must be 1.0.0.');
$manifest=(string)file_get_contents($root.'/release/manifest.txt');foreach(['.env','.git','runtime logs','private RPP','database dumps'] as $item)check(str_contains($manifest,$item),"Release manifest missing exclusion: {$item}");
$required=['docs/TEACHER_GUIDE.md','docs/STUDENT_GUIDE.md','docs/TECHNICAL_GUIDE.md','docs/DEMO_SCRIPT.md','scripts/build_release.ps1','scripts/backup.ps1','scripts/restore_uploads.ps1','.env.production.example'];foreach($required as $file)check(is_file($root.'/'.$file),"Release readiness file missing: {$file}");
$source='📖 🎧 🎤 ✍️ 🏆 🎮 ·';check(mb_check_encoding($source,'UTF-8'),'UTF-8 fixture invalid.');
