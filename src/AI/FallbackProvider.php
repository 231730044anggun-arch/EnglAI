<?php
declare(strict_types=1);

namespace EnglAI\AI;

final class FallbackProvider
{
    /** @return array<string,mixed>|null */
    public function for(string $mode, int $unit, string $difficulty): ?array
    {
        $items = $mode === 'speaking' ? $this->speakingItems() : $this->quizItems();
        $matches = array_values(array_filter($items, static fn(array $item): bool => (int) $item['u'] === $unit && $item['dif'] === $difficulty));
        if (!$matches) {
            $matches = array_values(array_filter($items, static fn(array $item): bool => (int) $item['u'] === $unit));
        }
        if (!$matches) {
            $matches = $items;
        }
        return $matches ? $matches[array_rand($matches)] : null;
    }

    /** @return list<array<string,mixed>> */
    private function quizItems(): array
    {
        return [
            ['u'=>1,'q'=>'What is the main threat to the Bekantan’s survival?','op'=>['A. Climate change','B. Habitat destruction','C. Natural predators','D. Overpopulation'],'ans'=>'B','exp'=>'Deforestation and habitat destruction threaten the Bekantan habitat.','cat'=>'Reading','dif'=>'medium'],
            ['u'=>1,'q'=>'Choose the correct possessive adjective: “The students love ___ English teacher.”','op'=>['A. their','B. them','C. they','D. theirs'],'ans'=>'A','exp'=>'Students is plural, so the possessive adjective is their.','cat'=>'Grammar','dif'=>'easy'],
            ['u'=>2,'q'=>'Which sentence compares two animals correctly?','op'=>['A. Gorillas are heavy than orangutans.','B. Gorillas are heavier than orangutans.','C. Gorillas is heavier orangutans.','D. Gorillas more heavy than orangutans.'],'ans'=>'B','exp'=>'The comparative form of heavy is heavier.','cat'=>'Grammar','dif'=>'medium'],
            ['u'=>3,'q'=>'Change to active voice: “The seeds are eaten by the bird.”','op'=>['A. The bird eats the seeds.','B. The bird is eating the seeds.','C. The bird ate the seeds.','D. The bird eat the seeds.'],'ans'=>'A','exp'=>'Passive present are eaten becomes active present eats.','cat'=>'Grammar','dif'=>'hard'],
            ['u'=>3,'q'=>'Where is the Cendrawasih commonly found?','op'=>['A. Papua','B. Java','C. Sumatra','D. Madura'],'ans'=>'A','exp'=>'The lesson material identifies Papua as the habitat of Cendrawasih.','cat'=>'Facts','dif'=>'easy'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function speakingItems(): array
    {
        return [
            ['u'=>1,'phrase'=>'The bekantan lives in the mangrove forests of Kalimantan.','tips'=>'Speak clearly and pause naturally.','exp'=>'','cat'=>'Speaking','dif'=>'easy'],
            ['u'=>2,'phrase'=>'Orangutans have longer arms than gorillas.','tips'=>'Make the comparative ending in longer clear.','exp'=>'','cat'=>'Speaking','dif'=>'medium'],
            ['u'=>3,'phrase'=>'Cendrawasih is found in Papua.','tips'=>'Say each word clearly.','exp'=>'','cat'=>'Speaking','dif'=>'easy'],
            ['u'=>3,'phrase'=>'These beautiful birds are protected by Indonesian law.','tips'=>'Keep the final sounds in protected clear.','exp'=>'','cat'=>'Speaking','dif'=>'hard'],
        ];
    }
}
