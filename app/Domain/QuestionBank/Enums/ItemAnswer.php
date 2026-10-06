<?php

namespace App\Domain\QuestionBank\Enums;

/** How a sub-part of a question is answered (qb_question_types.item_answer). */
enum ItemAnswer: string
{
    case None = 'none';
    case Boolean = 'boolean';
    case Option = 'option';
    case Text = 'text';
    case Position = 'position';
}
