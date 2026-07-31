<?php
declare(strict_types=1);
namespace EnglAI\Learning;
final class Level
{
    public const VALUES=['basic','intermediate','advanced'];
    public static function validate(string $value): string
    {
        $value=strtolower(trim($value));
        if(!in_array($value,self::VALUES,true))throw new \InvalidArgumentException('Level tidak valid.');
        return $value;
    }
    /** @return array<string,mixed> */
    public static function profile(string $level): array
    {
        return match(self::validate($level)){
            'basic'=>['length'=>70,'vocabulary'=>'common everyday','grammar'=>'simple present and simple sentences','thinking'=>'explicit information','support'=>'many examples and direct hints'],
            'intermediate'=>['length'=>130,'vocabulary'=>'broader contextual','grammar'=>'compound sentences and intermediate structures','thinking'=>'explicit and simple implicit meaning','support'=>'moderate guidance'],
            'advanced'=>['length'=>220,'vocabulary'=>'academic and nuanced','grammar'=>'complex clauses and varied structures','thinking'=>'inference, critical and analytical thinking','support'=>'minimal guidance'],
        };
    }
}
