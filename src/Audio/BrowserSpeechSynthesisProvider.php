<?php
declare(strict_types=1);
namespace EnglAI\Audio;
final class BrowserSpeechSynthesisProvider implements AudioProvider
{
    public function configuration(array $activityContent): array
    {
        $audio=is_array($activityContent['audio']??null)?$activityContent['audio']:[];
        return ['provider'=>'browser_speech_synthesis','label'=>'Generated Listening Audio','language'=>(string)($audio['language']??'en-US'),'rate'=>max(.5,min(1.5,(float)($audio['rate']??1))),'pitch'=>max(.5,min(1.5,(float)($audio['pitch']??1))),'voice_preference'=>(string)($audio['voice_preference']??'any English voice'),'max_replays'=>max(1,min(10,(int)($audio['max_replays']??3)))];
    }
}
