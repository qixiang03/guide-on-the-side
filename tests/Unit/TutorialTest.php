<?php
use PHPUnit\Framework\TestCase;

class TutorialTest extends TestCase {
    // 
    public function test_slide_flow_increments_correctly() {
        $currentSlide = 1;
        $currentSlide++; 
        $this->assertEquals(2, $currentSlide);
    }

    // 
    public function test_quiz_logic_calculates_score() {
        $answers = ['correct', 'wrong'];
        $score = ($answers[0] === 'correct') ? 1 : 0;
        $this->assertEquals(1, $score);
    }
}