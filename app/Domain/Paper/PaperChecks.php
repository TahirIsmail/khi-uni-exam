<?php

namespace App\Domain\Paper;

/**
 * Whether one question of a paper gives away the answer to another. It is a plain, honest test: the
 * text of the second question's correct answer (long enough not to be a common word) appears, word
 * for word, in the first question's text or options. That finds the usual leaks — a vignette that
 * names the diagnosis another question asks for — and says nothing when it finds none.
 */
final class PaperChecks
{
    /**
     * @param  array<int, array{text: string, answers: list<string>}>  $questions  by item id: all the text of the question, and its correct answers
     * @return list<array{giver: int, receiver: int}> the item whose text gives the answer, and the item whose answer it gives
     */
    public function cues(array $questions): array
    {
        $minimum = (int) config('exam.paper.cue_min_length');

        $texts = [];
        $answers = [];
        foreach ($questions as $id => $question) {
            $texts[$id] = ' '.$this->normalise($question['text']).' ';
            $answers[$id] = array_values(array_filter(
                array_map(fn (string $answer): string => $this->normalise($answer), $question['answers']),
                fn (string $answer): bool => mb_strlen($answer) >= $minimum,
            ));
        }

        $found = [];
        foreach ($answers as $receiver => $set) {
            foreach ($set as $answer) {
                foreach ($texts as $giver => $text) {
                    if ($giver !== $receiver && str_contains($text, ' '.$answer.' ')) {
                        $found[$giver.'>'.$receiver] = ['giver' => $giver, 'receiver' => $receiver];
                    }
                }
            }
        }

        return array_values($found);
    }

    /** Lower case, without tags or punctuation, single spaces: so wording, not markup, is compared. */
    public function normalise(string $html): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
